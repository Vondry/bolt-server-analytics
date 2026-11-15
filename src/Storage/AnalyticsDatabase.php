<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Storage;

use Closure;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Exception\TableDoesNotExist;
use Doctrine\ORM\Configuration as ORMConfiguration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use FilesystemIterator;
use InvalidArgumentException;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SensitiveParameter;
use SplFileInfo;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Throwable;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Config\RequestAnalyticsConfig;

/**
 * Where the extension's tables live: a database of their own
 * (`storage.database`), by default a SQLite file, or a MySQL, MariaDB or
 * PostgreSQL database (see StorageTarget). Never Bolt's database: the
 * statistics are kept apart from the site's data, so the rollup never waits
 * for, or blocks, the site's own reads and writes (the collector only appends
 * to the spool). Before connecting, the target is compared with the
 * parameters of Bolt's connection, which never connects to Bolt's database;
 * the same file, or the same database on the same server, is refused. A
 * SQLite file runs in WAL mode: the admin pages read while a rollup writes.
 *
 * The extension creates and updates its tables itself; Bolt's Doctrine
 * migrations are not involved. This happens on first use (see withSchema()) or with
 * `bin/console request-analytics:install`.
 *
 * Public: the services that use its connection get it through an expression
 * (CONNECTION), and the container removes private services that only
 * expressions refer to.
 */
#[Autoconfigure(public: true)]
final class AnalyticsDatabase
{
    /**
     * For `#[Autowire(expression: …)]`: the connection of the extension's tables.
     */
    public const CONNECTION = "service('TomVondracek\\\\RequestAnalytics\\\\Storage\\\\AnalyticsDatabase').connection()";

    private const LOCK = 'request-analytics-install';
    private const SQLITE_BUSY_TIMEOUT_MS = 10_000;

    private ?Connection $connection = null;
    private ?string $prefix = null;

    /**
     * @param (Closure(): Connection)|null $boltConnection Bolt's connection, only for its parameters: never connected, never queried
     */
    public function __construct(
        private readonly ?ConfigLoader $configLoader,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir = '',
        #[AutowireServiceClosure(Connection::class)]
        private readonly ?Closure $boltConnection = null,
    ) {
    }

    /**
     * Tables in an existing connection, whatever the config says (tests, tools).
     */
    public static function fixed(Connection $connection, string $tablePrefix, string $projectDir = ''): self
    {
        $database = new self(null, $projectDir);
        $database->connection = $connection;
        $database->prefix = $tablePrefix;

        return $database;
    }

    /**
     * Not connected yet: it connects on its first query.
     *
     * @throws InvalidArgumentException when `storage.database` is not valid, is Bolt's database, or its PHP extension is missing
     */
    public function connection(): Connection
    {
        if ($this->connection instanceof Connection) {
            return $this->connection;
        }

        if ($this->configLoader === null) {
            throw new LogicException('A database made with fixed() has its connection.');
        }

        $target = $this->target();
        if (! extension_loaded($target->extension())) {
            throw new InvalidArgumentException(sprintf('The Request Analytics `storage.database` (%s) needs the %s PHP extension, which is not installed. Visits are kept in the spool until it is.', $target->describe(), $target->extension()));
        }

        $this->assertNotBolts($target);
        $path = $target->path();

        return $this->connection = $path === null ? DriverManager::getConnection($target->params) : self::sqlite($path);
    }

    /**
     * The configured database, `%env()%` resolved.
     *
     * @throws InvalidArgumentException
     */
    public function target(): StorageTarget
    {
        if ($this->configLoader === null) {
            throw new LogicException('A database made with fixed() has no configured target.');
        }

        return StorageTarget::fromConfig($this->configLoader->load()->storageDatabase);
    }

    /**
     * The SQLite file of the tables; null for a database server, or a
     * connection given to fixed().
     */
    public function path(): ?string
    {
        if ($this->configLoader === null) {
            return null;
        }

        try {
            return StorageTarget::sqlitePath($this->configLoader->load()->storageDatabase);
        } catch (Throwable) {
            // A broken config is reported by whoever loads it next; nothing is
            // written meanwhile, so the default file is as good as any.
            return str_replace('%kernel.project_dir%', $this->projectDir, RequestAnalyticsConfig::STORAGE_DATABASE_DEFAULT);
        }
    }

    /**
     * The statistics are kept apart from the site's data. Bolt's connection
     * is only asked for its parameters (DBAL connects on the first query, not
     * when it is built), so this never opens Bolt's database.
     *
     * @throws InvalidArgumentException
     */
    private function assertNotBolts(StorageTarget $target): void
    {
        $bolt = $this->boltConnection === null ? null : ($this->boltConnection)();
        if ($bolt instanceof Connection && $target->isSameDatabaseAs($bolt->getParams())) {
            throw new InvalidArgumentException(sprintf("The Request Analytics `storage.database` (%s) is Bolt's own database. The statistics are kept apart from the site's data: set it to a SQLite file or a database of their own. Check config/extensions/%s.yaml.", $target->describe(), ConfigLoader::CONFIG_BASENAME));
        }
    }

    /**
     * On SQLite: deleted rows are overwritten, for this connection. Nothing
     * on other platforms, which reuse the space of deleted rows on their own.
     */
    public static function secureDelete(Connection $connection): void
    {
        if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $connection->executeStatement('PRAGMA secure_delete = ON');
        }
    }

    /**
     * Makes the extension's files that exist already owner and group only
     * (PrivateFiles): the SQLite file with its -wal and -shm, the spool, the
     * locks and throttles. Earlier versions created them readable by
     * everybody; new ones are created private.
     *
     * @return list<string> the files and directories
     */
    public function restrictFiles(): array
    {
        $paths = [];
        $database = $this->path();
        if ($database !== null) {
            array_push($paths, $database, $database . '-wal', $database . '-shm');
        }

        $directories = $this->projectDir === '' ? [] : [$this->projectDir . '/var/request-analytics'];
        try {
            $directories[] = $this->configLoader?->load()->spoolDir;
        } catch (Throwable) {
            // A broken config: its spool directory is unknown.
        }

        foreach (array_filter($directories, is_string(...)) as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $paths[] = $directory;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
                if ($file instanceof SplFileInfo && ! $file->isLink()) {
                    $paths[] = $file->getPathname();
                }
            }
        }

        $restricted = [];
        foreach (array_unique($paths) as $path) {
            if (file_exists($path)) {
                PrivateFiles::restrict($path);
                $restricted[] = $path;
            }
        }

        return $restricted;
    }

    /**
     * Rewrites the extension's own SQLite file without free pages, where rows
     * deleted before `secure_delete` was on may still be. Nothing for a
     * database server, which reuses the space of deleted rows on its own, or
     * a connection given to fixed(), which is not the extension's to rewrite.
     *
     * @return bool whether it ran
     */
    public function vacuum(): bool
    {
        if ($this->path() === null) {
            return false;
        }

        $this->connection()->executeStatement('VACUUM');

        return true;
    }

    public function isSqlite(): bool
    {
        return $this->connection()->getDatabasePlatform() instanceof SQLitePlatform;
    }

    public function tablePrefix(): string
    {
        return $this->prefix ??= '';
    }

    /**
     * Runs $work; when a table or a column of the extension is missing (the
     * first run, or a version with a changed schema), or a column of an earlier
     * version that is no longer written has no default, brings the schema up to
     * date and runs $work once more.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function withSchema(callable $work): mixed
    {
        try {
            return $work();
        } catch (TableNotFoundException|InvalidFieldNameException|NotNullConstraintViolationException) {
            $this->install();

            return $work();
        }
    }

    /**
     * Creates the missing tables and brings the existing ones up to date. Only
     * the extension's tables are compared; other tables of the database are
     * never touched.
     *
     * @return list<string> the statements that were run
     */
    public function install(): array
    {
        $lock = $this->lockFactory()
            ->createLock(self::LOCK);
        $lock->acquire(true);

        try {
            $connection = $this->connection();
            $schemaManager = $connection->createSchemaManager();
            $platform = $connection->getDatabasePlatform();
            $comparator = $schemaManager->createComparator();

            $statements = [];
            foreach ($this->schemaTool()->getSchemaFromMetadata($this->metadata())->getTables() as $table) {
                $name = $table->getObjectName()->getUnqualifiedName()->getValue();
                // By name: unlike a listing, this ignores the schema filter that
                // keeps the tables out of Bolt's migrations.
                try {
                    $current = $schemaManager->introspectTableByUnquotedName($name);
                } catch (TableDoesNotExist) {
                    $this->run($statements, ...$platform->getCreateTableSQL($table));

                    continue;
                }

                $converted = $this->convertMysqlTable($name);
                if ($converted !== null) {
                    $this->run($statements, $converted);
                    $current = $schemaManager->introspectTableByUnquotedName($name);
                }

                $diff = $comparator->compareTables($current, $table);
                if (! $diff->isEmpty()) {
                    $this->run($statements, ...$platform->getAlterTableSQL($diff));
                }
            }

            return $statements;
        } finally {
            $lock->release();
        }
    }

    /**
     * Runs the statements and adds them to $statements.
     *
     * @param list<string> $statements
     */
    private function run(array &$statements, string ...$sql): void
    {
        foreach ($sql as $statement) {
            $this->connection()->executeStatement($statement);
            $statements[] = $statement;
        }
    }

    /**
     * On MySQL / MariaDB, a table of the extension that was created with
     * another character set or collation (the default of its database, before
     * the tables named theirs) is converted to TableNames::OPTIONS. The schema
     * comparison does not look at them. Null when nothing is to be done.
     *
     * @param non-empty-string $table
     */
    private function convertMysqlTable(string $table): ?string
    {
        $connection = $this->connection();
        if (! $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            return null;
        }

        $collation = $connection->fetchOne('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
        if ($collation === TableNames::MYSQL_COLLATION) {
            return null;
        }

        return sprintf('ALTER TABLE %s CONVERT TO CHARACTER SET %s COLLATE %s', $table, TableNames::MYSQL_CHARSET, TableNames::MYSQL_COLLATION);
    }

    private static function sqlite(string $path): Connection
    {
        PrivateFiles::directory(dirname($path));

        $configuration = new Configuration();
        $configuration->setMiddlewares([self::pragmas()]);

        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $path,
        ], $configuration);
    }

    /**
     * Settings of the extension's own SQLite file, applied on every connect:
     *
     * - WAL: readers (the admin pages) never wait for the writer (the rollup)
     * - synchronous = NORMAL: no fsync per commit; with WAL a crash can lose the
     *   last commits, never corrupt the file (they are statistics)
     * - busy_timeout: a second writer waits for the first instead of failing
     * - secure_delete: deleted rows are overwritten with zeros, not left in
     *   free pages of the file (the raw rows the rollup deletes, the days the
     *   retention or an administrator deletes)
     *
     * An anonymous class on purpose: Bolt registers every class of the
     * extension as a service, and DoctrineBundle would add a named driver
     * middleware to Bolt's own connection.
     */
    private static function pragmas(): Middleware
    {
        return new class(self::SQLITE_BUSY_TIMEOUT_MS) implements Middleware {
            public function __construct(
                private readonly int $busyTimeoutMs,
            ) {
            }

            public function wrap(Driver $driver): Driver
            {
                return new class($driver, $this->busyTimeoutMs) extends AbstractDriverMiddleware {
                    public function __construct(
                        Driver $driver,
                        private readonly int $busyTimeoutMs,
                    ) {
                        parent::__construct($driver);
                    }

                    public function connect(#[SensitiveParameter] array $params): DriverConnection
                    {
                        // Created here, not by SQLite, so it is never readable by
                        // everybody; SQLite gives its -wal and -shm the same mode.
                        $path = $params['path'] ?? null;
                        if (is_string($path) && $path !== '' && ! file_exists($path)) {
                            @touch($path);
                            PrivateFiles::restrict($path);
                        }

                        $connection = parent::connect($params);
                        // The timeout first: switching to WAL needs a moment of exclusive access.
                        $connection->exec(sprintf('PRAGMA busy_timeout = %d', $this->busyTimeoutMs));
                        $connection->exec('PRAGMA journal_mode = WAL');
                        $connection->exec('PRAGMA synchronous = NORMAL');
                        $connection->exec('PRAGMA secure_delete = ON');

                        return $connection;
                    }
                };
            }
        };
    }

    private function schemaTool(): SchemaTool
    {
        return new SchemaTool($this->entityManager());
    }

    /**
     * @return list<ClassMetadata<object>>
     */
    private function metadata(): array
    {
        return array_values($this->entityManager()->getMetadataFactory()->getAllMetadata());
    }

    /**
     * An entity manager of the extension's own: the entities only describe the
     * tables, which are read and written with plain SQL.
     *
     * Its metadata is never cached beyond this object. Without a cache of its
     * own, Doctrine picks one by itself outside dev mode: APCu under a fixed
     * namespace (shared by every site of a PHP-FPM pool and kept across
     * deploys), or Memcached / Redis on 127.0.0.1. install() would then compare
     * the tables with the mapping of another version of the extension: after
     * an update it would change nothing, and with the older mapping of another
     * site it would drop new columns.
     */
    private function entityManager(): EntityManager
    {
        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__) . '/Entity'], cache: new ArrayAdapter());
        self::configureLazyObjects($config);

        $entityManager = new EntityManager($this->connection(), $config);
        $prefix = $this->tablePrefix();
        if ($prefix !== '') {
            $entityManager->getEventManager()
                ->addEventListener(Events::loadClassMetadata, new class($prefix) {
                    public function __construct(
                        private readonly string $prefix,
                    ) {
                    }

                    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
                    {
                        $metadata = $args->getClassMetadata();
                        $metadata->setPrimaryTable([
                            'name' => $this->prefix . $metadata->getTableName(),
                        ]);
                    }
                });
        }

        return $entityManager;
    }

    /**
     * Native lazy objects from PHP 8.4; before, Doctrine needs a directory and
     * a namespace for proxy classes. The entities only describe the tables and
     * are never loaded, so no proxy class is ever written there.
     *
     * @internal public for the tests' own entity manager
     */
    public static function configureLazyObjects(ORMConfiguration $config): void
    {
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);

            return;
        }

        $config->setProxyDir(sys_get_temp_dir() . '/request-analytics-proxies');
        $config->setProxyNamespace('TomVondracek\\RequestAnalytics\\Proxies');
    }

    private function lockFactory(): LockFactory
    {
        $directory = ($this->projectDir === '' ? sys_get_temp_dir() : $this->projectDir) . '/var/request-analytics';
        PrivateFiles::directory($directory);

        return new LockFactory(new FlockStore($directory));
    }
}
