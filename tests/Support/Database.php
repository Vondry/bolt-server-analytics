<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;
use TomVondracek\RequestAnalytics\Storage\StorageTarget;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * The extension's tables, created from the entity mapping and prefixed the way
 * Bolt's TablePrefix listener does it.
 *
 * In-memory SQLite by default. With `RA_TEST_DATABASE_URL` (e.g.
 * `mysql://root:pass@127.0.0.1:3306/ra` or `postgresql://…`) the tests of the
 * `database` group run against that server: the schema is created once per
 * run, and every Database empties the tables.
 *
 * @phpstan-import-type Params from DriverManager
 */
final readonly class Database
{
    public const PREFIX = 'bolt_';

    /**
     * Prefix of tables that don't exist, for `new Database(false)` on a server.
     */
    private const MISSING_PREFIX = 'missing_';

    public Connection $connection;
    public EntityManager $entityManager;
    public TableNames $tables;
    public QueryLog $log;
    public string $prefix;

    public function __construct(
        bool $createSchema = true
    ) {
        $url = self::url();
        $this->log = new QueryLog();

        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2) . '/src/Entity'], true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $config->setMiddlewares([$this->log]);
        AnalyticsDatabase::configureLazyObjects($config);

        $this->connection = DriverManager::getConnection(self::params($url), $config);
        $this->entityManager = new EntityManager($this->connection, $config);

        $prefix = $url !== null && ! $createSchema ? self::MISSING_PREFIX : self::PREFIX;
        $this->entityManager->getEventManager()
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

        $this->prefix = $prefix;
        $this->tables = TableNames::withPrefix($prefix);

        if ($createSchema) {
            $url === null ? $this->createSchema() : $this->emptyServerSchema($url);
        } elseif ($url !== null) {
            $this->dropMissingTables();
        }

        $this->log->clear();
    }

    /**
     * The database server of `RA_TEST_DATABASE_URL`; null for in-memory SQLite.
     */
    public static function url(): ?string
    {
        $url = getenv('RA_TEST_DATABASE_URL');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Another connection to the same database, like a second PHP process; null
     * for in-memory SQLite, where there is no such thing.
     */
    public function secondConnection(): ?Connection
    {
        $url = self::url();

        return $url === null ? null : DriverManager::getConnection(self::params($url));
    }

    public function isSqlite(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
    }

    public function isMysql(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    /**
     * Rows with booleans as 0 / 1, the same on every platform.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $table, string $orderBy = '1'): array
    {
        return array_map(
            static fn (array $row): array => array_map(static fn (mixed $value): mixed => is_bool($value) ? (int) $value : $value, $row),
            $this->connection->fetchAllAssociative(sprintf('SELECT * FROM %s ORDER BY %s', $table, $orderBy))
        );
    }

    public function count(string $table): int
    {
        $count = $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return Params
     */
    private static function params(?string $url): array
    {
        if ($url === null) {
            return [
                'driver' => 'pdo_sqlite',
                'memory' => true,
            ];
        }

        // As the extension connects: with its connection settings (the
        // `utf8mb4` character set on MySQL / MariaDB, whatever the server's
        // default is).
        return StorageTarget::fromConfig($url)->params;
    }

    private function createSchema(): void
    {
        (new SchemaTool($this->entityManager))->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }

    /**
     * On a server the tables of `new Database(false)` must really be missing:
     * a test that let the extension create them (on first use) would leave
     * them for every test after it, in whatever order the tests run.
     */
    private function dropMissingTables(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        foreach ($schemaManager->introspectTableNames() as $name) {
            $table = $name->getUnqualifiedName()->getValue();
            if (str_starts_with(mb_strtolower($table), self::MISSING_PREFIX)) {
                $this->connection->executeStatement(sprintf('DROP TABLE %s', $table));
            }
        }
    }

    private function emptyServerSchema(string $url): void
    {
        /** @var array<string, true> $created */
        static $created = [];
        if (! isset($created[$url])) {
            $tool = new SchemaTool($this->entityManager);
            $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
            $tool->dropSchema($metadata);
            $tool->createSchema($metadata);
            $created[$url] = true;

            return;
        }

        $tables = array_map(static fn (ClassMetadata $metadata): string => $metadata->getTableName(), $this->entityManager->getMetadataFactory()->getAllMetadata());
        $platform = $this->connection->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform) {
            $this->connection->executeStatement(sprintf('TRUNCATE TABLE %s RESTART IDENTITY', implode(', ', $tables)));

            return;
        }

        foreach ($tables as $table) {
            $this->connection->executeStatement(sprintf('TRUNCATE TABLE %s', $table));
        }
    }
}
