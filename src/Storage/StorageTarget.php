<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Storage;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\MalformedDsnException;
use Doctrine\DBAL\Tools\DsnParser;
use InvalidArgumentException;
use PDO;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Config\RequestAnalyticsConfig;

/**
 * The database of the statistics, as `storage.database` names it: the path of
 * a SQLite file (the default), or the URL of a MySQL, MariaDB or PostgreSQL
 * database of their own (`mysql://user:password@host:3306/analytics`,
 * `mariadb://…`, `postgresql://…`; `sqlite:///…` is a SQLite file too).
 *
 * Never Bolt's database: the statistics are kept apart from the site's data,
 * so collecting and aggregating never wait for, or block, the site, and
 * deleting either never touches the other. isSameDatabaseAs() compares with
 * the parameters of Bolt's connection, which are known without connecting.
 *
 * @phpstan-import-type Params from DriverManager
 */
final readonly class StorageTarget
{
    public const SQLITE = 'sqlite';
    public const MYSQL = 'mysql';
    public const PGSQL = 'pgsql';

    /**
     * URL scheme => DBAL driver.
     */
    private const SCHEMES = [
        'sqlite' => 'pdo_sqlite',
        'mysql' => 'pdo_mysql',
        'mariadb' => 'pdo_mysql',
        'pgsql' => 'pdo_pgsql',
        'postgres' => 'pdo_pgsql',
        'postgresql' => 'pdo_pgsql',
    ];

    /**
     * DBAL driver (of Bolt's connection too) => platform family.
     */
    private const PLATFORMS = [
        'pdo_sqlite' => self::SQLITE,
        'sqlite3' => self::SQLITE,
        'pdo_mysql' => self::MYSQL,
        'mysqli' => self::MYSQL,
        'pdo_pgsql' => self::PGSQL,
        'pgsql' => self::PGSQL,
    ];

    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const DEFAULT_PORTS = [
        self::MYSQL => 3306,
        self::PGSQL => 5432,
    ];

    /**
     * Host names that all mean "this machine".
     */
    private const LOCAL_HOSTS = ['', 'localhost', '127.0.0.1', '::1', '[::1]'];

    /**
     * @param self::SQLITE|self::MYSQL|self::PGSQL $platform
     * @param Params $params connection parameters for DriverManager
     */
    private function __construct(
        public string $platform,
        public array $params,
    ) {
    }

    /**
     * @param string $value `storage.database`, `%env()%` and `%kernel.project_dir%` resolved
     *
     * @throws InvalidArgumentException when it is neither a SQLite file nor the URL of a supported database
     */
    public static function fromConfig(string $value): self
    {
        if (! self::isUrl($value)) {
            return new self(self::SQLITE, [
                'driver' => 'pdo_sqlite',
                'path' => $value,
            ]);
        }

        $scheme = mb_strtolower(mb_substr($value, 0, (int) mb_strpos($value, '://')));
        if (! isset(self::SCHEMES[$scheme])) {
            throw self::invalid(sprintf('the path of a SQLite file or the URL of a MySQL, MariaDB or PostgreSQL database (mysql://, mariadb://, postgresql://), a URL of "%s://" given', $scheme));
        }

        try {
            $params = (new DsnParser(self::SCHEMES))->parse($value);
        } catch (MalformedDsnException) {
            throw self::invalid(sprintf('a valid database URL, "%s://…" given (special characters in the user name or password must be percent-encoded)', $scheme));
        }

        $driver = $params['driver'] ?? null;
        $platform = is_string($driver) ? (self::PLATFORMS[$driver] ?? null) : null;
        if ($platform === null) {
            throw self::invalid(sprintf('the URL of a MySQL, MariaDB or PostgreSQL database, "%s://…" given', $scheme));
        }

        if ($platform === self::SQLITE) {
            $path = $params['path'] ?? null;
            if (! is_string($path) || $path === '' || isset($params['memory'])) {
                throw self::invalid('the path of a SQLite file, a sqlite:// URL without one given');
            }

            return new self(self::SQLITE, [
                'driver' => 'pdo_sqlite',
                'path' => $path,
            ]);
        }

        $database = $params['dbname'] ?? null;
        if (! is_string($database) || $database === '') {
            throw self::invalid(sprintf('a URL that names the database, as in %s://user:password@host/analytics, a URL without one given', $scheme));
        }

        return new self($platform, self::serverDefaults($platform, $params));
    }

    /**
     * Settings of a connection to a database server, unless the URL sets them:
     *
     * - a connect timeout of CONNECT_TIMEOUT_SECONDS: the Analytics page and
     *   its menu item read the statistics; with a server that cannot be
     *   reached, PHP would wait for the socket timeout (often 60 s) on every
     *   backend page
     * - on MySQL / MariaDB the `utf8mb4` connection character set, the one of
     *   the tables (TableNames::OPTIONS)
     *
     * @param Params $params
     *
     * @return Params
     */
    private static function serverDefaults(string $platform, array $params): array
    {
        $options = $params['driverOptions'] ?? [];
        $options[PDO::ATTR_TIMEOUT] ??= self::CONNECT_TIMEOUT_SECONDS;
        $params['driverOptions'] = $options;

        if ($platform === self::MYSQL) {
            $params['charset'] ??= TableNames::MYSQL_CHARSET;
        }

        return $params;
    }

    /**
     * Whether the value is a URL (`scheme://…`) rather than a file path.
     */
    public static function isUrl(string $value): bool
    {
        return preg_match('~^[a-z][a-z0-9+.\-]*://~i', $value) === 1;
    }

    /**
     * The SQLite file of a value; null for a database server, or a URL that
     * fromConfig() would refuse. Never throws: it runs on every config load.
     */
    public static function sqlitePath(string $value): ?string
    {
        if (! self::isUrl($value)) {
            return $value;
        }

        if (! str_starts_with(mb_strtolower($value), 'sqlite://')) {
            return null;
        }

        try {
            return self::fromConfig($value)->path();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The SQLite file; null for a database server.
     */
    public function path(): ?string
    {
        $path = $this->params['path'] ?? null;

        return $this->platform === self::SQLITE && is_string($path) ? $path : null;
    }

    /**
     * The PHP extension the connection needs.
     */
    public function extension(): string
    {
        return match ($this->platform) {
            self::SQLITE => 'pdo_sqlite',
            self::MYSQL => 'pdo_mysql',
            self::PGSQL => 'pdo_pgsql',
        };
    }

    /**
     * For messages: the file, or the URL without the password.
     */
    public function describe(): string
    {
        if ($this->platform === self::SQLITE) {
            return (string) $this->path();
        }

        $user = $this->params['user'] ?? null;
        $host = $this->params['host'] ?? null;
        $port = $this->params['port'] ?? null;

        return sprintf(
            '%s://%s%s%s/%s',
            $this->platform === self::MYSQL ? 'mysql' : 'postgresql',
            is_string($user) && $user !== '' ? $user . '@' : '',
            is_string($host) ? $host : '',
            is_int($port) || is_string($port) ? ':' . $port : '',
            self::string($this->params['dbname'] ?? null),
        );
    }

    /**
     * Whether this is the database of a connection with these parameters
     * (Bolt's): the same SQLite file, or the same database on the same
     * server. In doubt (the same server reached through a socket and a host
     * name), the same: a false alarm is easily fixed, mixed data is not.
     *
     * @param array<array-key, mixed> $params `Connection::getParams()`, which never connects
     */
    public function isSameDatabaseAs(array $params): bool
    {
        // A primary/replica connection writes to its primary.
        $primary = $params['primary'] ?? null;
        if (is_array($primary)) {
            $params = $primary + $params;
        }

        $driver = $params['driver'] ?? null;
        $platform = is_string($driver) ? (self::PLATFORMS[$driver] ?? null) : null;
        if ($platform !== $this->platform) {
            return false;
        }

        if ($platform === self::SQLITE) {
            $path = $params['path'] ?? null;
            if (($params['memory'] ?? false) === true || ! is_string($path) || $path === '') {
                return false;
            }

            return RequestAnalyticsConfig::canonical($path) === RequestAnalyticsConfig::canonical((string) $this->path());
        }

        return self::database($params) === self::database($this->params)
            && $this->sameServer($params);
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function sameServer(array $params): bool
    {
        $socket = self::string($params['unix_socket'] ?? null);
        $ownSocket = self::string($this->params['unix_socket'] ?? null);
        if ($socket !== '' || $ownSocket !== '') {
            // A socket is always this machine: the same server as a local host.
            return ($socket === '' || $ownSocket === '' || $socket === $ownSocket)
                && self::isLocal($socket === '' ? $params : $this->params);
        }

        return self::host($params) === self::host($this->params)
            && $this->port($params) === $this->port($this->params);
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private static function isLocal(array $params): bool
    {
        return in_array(self::host($params), ['localhost'], true) || isset($params['unix_socket']);
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private static function host(array $params): string
    {
        $host = mb_strtolower(self::string($params['host'] ?? null));

        return in_array($host, self::LOCAL_HOSTS, true) ? 'localhost' : $host;
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function port(array $params): int
    {
        $port = $params['port'] ?? null;

        return is_numeric($port) ? (int) $port : (self::DEFAULT_PORTS[$this->platform] ?? 0);
    }

    /**
     * MySQL names are case-sensitive on some systems only; in doubt, the same.
     *
     * @param array<array-key, mixed> $params
     */
    private static function database(array $params): string
    {
        return mb_strtolower(self::string($params['dbname'] ?? null));
    }

    private static function string(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    private static function invalid(string $expected): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf('The Request Analytics `storage.database` must be %s. Check config/extensions/%s.yaml.', $expected, ConfigLoader::CONFIG_BASENAME));
    }
}
