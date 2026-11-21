<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\TableNotFoundException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TomVondracek\RequestAnalytics\Aggregation\Aggregates;
use TomVondracek\RequestAnalytics\Aggregation\DailyCap;
use TomVondracek\RequestAnalytics\Aggregation\RollupResult;
use TomVondracek\RequestAnalytics\Aggregation\RollupService;
use TomVondracek\RequestAnalytics\Aggregation\RollupState;
use TomVondracek\RequestAnalytics\Aggregation\SpoolIngestor;
use TomVondracek\RequestAnalytics\Aggregation\UpsertSqlBuilder;
use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Query\TrendQuery;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;
use TomVondracek\RequestAnalytics\Storage\TableNames;
use TomVondracek\RequestAnalytics\Tests\Support\Database;
use TomVondracek\RequestAnalytics\Tests\Support\Project;
use TomVondracek\RequestAnalytics\Writer\SpoolHitWriter;

/**
 * The extension's own database: by default a SQLite file, or a server
 * database of its own; never Bolt's.
 */
final class AnalyticsDatabaseTest extends TestCase
{
    private const TABLES = ['ra_aliases', 'ra_bots', 'ra_clients', 'ra_daily', 'ra_hit', 'ra_hourly', 'ra_locales', 'ra_not_found', 'ra_pages', 'ra_search', 'ra_sources', 'ra_spool', 'ra_state', 'ra_transitions'];

    private Project $project;
    private ?Connection $bolt = null;

    protected function setUp(): void
    {
        $this->project = new Project();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testOwnSqliteFileByDefault(): void
    {
        $database = $this->database();

        self::assertSame($this->project->dir . '/var/data/request-analytics.db', $database->path());
        self::assertSame('', $database->tablePrefix(), 'no prefix in a file of its own');
        self::assertTrue($database->isSqlite());
        self::assertSame($database->path(), $database->connection()->getParams()['path'] ?? null);
    }

    public function testFileRunsInWalMode(): void
    {
        $connection = $this->database()
            ->connection();

        self::assertSame('wal', $connection->fetchOne('PRAGMA journal_mode'));
        self::assertSame(1, self::int($connection->fetchOne('PRAGMA synchronous')), 'NORMAL');
        self::assertSame(10_000, self::int($connection->fetchOne('PRAGMA busy_timeout')));
        self::assertSame(1, self::int($connection->fetchOne('PRAGMA secure_delete')), 'deleted rows are overwritten, not left in the file');
        self::assertFileExists($this->project->dir . '/var/data/request-analytics.db', 'the directory was created');
    }

    /**
     * Owner and group only (the web server and cron), never other accounts
     * on the server, whatever the umask.
     */
    public function testFileIsForItsOwnerAndGroupOnly(): void
    {
        $umask = umask(0o002);

        try {
            $this->database()
                ->connection()
                ->fetchOne('SELECT 1');
        } finally {
            umask($umask);
        }

        self::assertSame(0o660, fileperms($this->project->dir . '/var/data/request-analytics.db') & 0o777);
        self::assertSame(0o770, fileperms($this->project->dir . '/var/data') & 0o777);
    }

    public function testRestrictFilesTightensWhatEarlierVersionsCreated(): void
    {
        $database = $this->database();
        $database->connection()
            ->fetchOne('SELECT 1');
        $file = $this->project->dir . '/var/data/request-analytics.db';
        $spool = $this->project->spoolDir();
        mkdir($spool, 0o777, true);
        touch($spool . '/2026111310.ndjson');
        touch($this->project->dir . '/var/request-analytics/sf.rollup.lock');
        foreach ([$file, $spool . '/2026111310.ndjson', $this->project->dir . '/var/request-analytics/sf.rollup.lock'] as $loose) {
            chmod($loose, 0o666);
        }
        chmod($spool, 0o777);

        $restricted = $database->restrictFiles();

        self::assertContains($file, $restricted);
        self::assertSame(0o660, fileperms($file) & 0o777);
        self::assertSame(0o660, fileperms($spool . '/2026111310.ndjson') & 0o777);
        self::assertSame(0o660, fileperms($this->project->dir . '/var/request-analytics/sf.rollup.lock') & 0o777);
        self::assertSame(0o770, fileperms($spool) & 0o777);
    }

    public function testVacuumRewritesOnlyItsOwnFile(): void
    {
        self::assertTrue($this->database()->vacuum());
        self::assertFalse(AnalyticsDatabase::fixed($this->boltConnection(), 'bolt_')->vacuum(), 'never a database it was given');
    }

    public function testInstallCreatesEveryTableOnceAndThenFindsNothingToDo(): void
    {
        $database = $this->database();

        $statements = $database->install();

        self::assertNotEmpty($statements);
        self::assertSame(self::TABLES, self::tables($database->connection()));
        self::assertSame([], $database->install(), 'a second install finds the schema up to date');
        self::assertSame([], $this->database()->install(), 'so does another process');
    }

    /**
     * A later version adds a column: the table is updated, its rows are kept.
     */
    public function testInstallUpdatesAnOlderTableAndKeepsItsRows(): void
    {
        $database = $this->database();
        $database->install();
        $connection = $database->connection();
        $connection->insert('ra_daily', [
            'day' => '2026-11-13',
            'pageviews' => 7,
        ]);
        $connection->executeStatement('ALTER TABLE ra_daily DROP COLUMN alias');

        $statements = $database->install();

        self::assertNotEmpty($statements);
        self::assertSame([
            'day' => '2026-11-13',
            'pageviews' => 7,
            'alias' => 0,
        ], array_intersect_key((array) $connection->fetchAssociative('SELECT * FROM ra_daily'), array_flip(['day', 'pageviews', 'alias'])));
        self::assertSame([], $database->install());
    }

    public function testWithSchemaCreatesMissingTablesAndRetries(): void
    {
        $database = $this->database();
        $tables = new TableNames($database);
        $runs = 0;

        $count = $database->withSchema(static function () use ($database, $tables, &$runs): mixed {
            ++$runs;

            return $database->connection()->fetchOne('SELECT COUNT(*) FROM ' . $tables->daily());
        });

        self::assertSame(2, $runs);
        self::assertSame(0, self::int($count));
    }

    public function testOtherErrorsAreNotRetried(): void
    {
        $runs = 0;
        $this->expectExceptionObject(new RuntimeException('Something else.'));

        try {
            $this->database()
                ->withSchema(static function () use (&$runs): never {
                    ++$runs;

                    throw new RuntimeException('Something else.');
                });
        } finally {
            self::assertSame(1, $runs, 'run once, not retried');
        }
    }

    /**
     * The point of a separate file: Bolt holding a write lock on its database
     * (an editor saving, a long migration) doesn't stop the statistics, and
     * the statistics never make Bolt wait.
     */
    public function testRollupRunsWhileBoltsDatabaseIsLocked(): void
    {
        $bolt = $this->boltConnection();
        $bolt->executeStatement('CREATE TABLE bolt_content (id INTEGER PRIMARY KEY)');
        $bolt->executeStatement('BEGIN EXCLUSIVE');

        try {
            $database = $this->database();
            $tables = new TableNames($database);
            (new SpoolHitWriter($this->project->configLoader()))->write(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/'));

            $result = $database->withSchema(fn (): RollupResult => $this->rollupService($database, $tables)->rollup(5000));

            self::assertSame(1, $result->processed);
            $trend = (new TrendQuery($database->connection(), $tables, $this->project->siteTime()))->trend('7d', 'pageviews', 'none');
            self::assertSame(1, $trend['total']['value']);
        } finally {
            $bolt->executeStatement('ROLLBACK');
        }

        self::assertSame(['bolt_content'], self::tables($bolt), "nothing in Bolt's database");
    }

    /**
     * The collector of a public page only appends to the spool: it never
     * opens, let alone creates, the analytics file.
     */
    public function testCollectorNeverOpensTheFile(): void
    {
        (new SpoolHitWriter($this->project->configLoader()))->write(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/'));

        self::assertFileExists($this->project->spoolDir() . '/2026111310.ndjson');
        self::assertFileDoesNotExist($this->project->dir . '/var/data/request-analytics.db');
    }

    /**
     * The country of an earlier version is NOT NULL and no longer written: the
     * first rollup after an update brings the schema up to date and moves the
     * spooled hits.
     */
    public function testRollupDropsAColumnThatIsNoLongerWritten(): void
    {
        $database = $this->database();
        $database->install();
        $database->connection()->executeStatement('DROP TABLE ra_hit');
        $database->connection()->executeStatement('CREATE TABLE ra_hit (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, day DATE NOT NULL, hour SMALLINT NOT NULL, kind VARCHAR(10) NOT NULL, path VARCHAR(255) NOT NULL, device VARCHAR(16) NOT NULL, browser VARCHAR(16) NOT NULL, os VARCHAR(16) NOT NULL, country CHAR(2) NOT NULL, lang VARCHAR(8) NOT NULL, channel VARCHAR(16) NOT NULL, source VARCHAR(64) NOT NULL, campaign VARCHAR(64) NOT NULL, is_entry BOOLEAN NOT NULL, is_reload BOOLEAN NOT NULL, ref VARCHAR(255) DEFAULT NULL, bot VARCHAR(32) DEFAULT NULL, via VARCHAR(255) DEFAULT NULL, term VARCHAR(64) DEFAULT NULL)');
        $tables = new TableNames($database);
        (new SpoolHitWriter($this->project->configLoader()))->write(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/'));

        $result = $database->withSchema(fn (): RollupResult => $this->rollupService($database, $tables)->rollup(5000));

        self::assertSame(1, $result->processed);
        self::assertFalse($database->connection()->createSchemaManager()->introspectTableByUnquotedName('ra_hit')->hasColumn('country'));
    }

    /**
     * The statistics are kept apart from the site's data: Bolt's database is
     * refused, found from the parameters of its connection alone (the file
     * of Bolt's connection is never even created).
     */
    public function testBoltsDatabaseIsRefusedWithoutOpeningIt(): void
    {
        $bolt = $this->project->dir . '/bolt.db';
        $this->project->configure([
            'storage' => [
                // Written another way than Bolt's connection has it.
                'database' => '%kernel.project_dir%/var/../bolt.db',
            ],
        ]);

        try {
            $this->database()
                ->connection();
            self::fail("Bolt's database was accepted.");
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString("is Bolt's own database", $exception->getMessage());
        }

        self::assertFileDoesNotExist($bolt, 'neither connection was opened');
    }

    public function testAFileNextToBoltsIsADatabaseOfItsOwn(): void
    {
        $this->database()
            ->install();

        self::assertFileExists($this->project->dir . '/var/data/request-analytics.db');
        self::assertFileDoesNotExist($this->project->dir . '/bolt.db', "Bolt's connection was never opened");
    }

    /**
     * A MySQL / MariaDB or PostgreSQL database of its own, by its URL: on the
     * server of RA_TEST_DATABASE_URL (CI), with Bolt in its SQLite file.
     */
    #[Group('database')]
    public function testDatabaseServerOfItsOwn(): void
    {
        $url = Database::url();
        if ($url === null) {
            self::markTestSkipped('Needs RA_TEST_DATABASE_URL.');
        }

        $this->project->configure([
            'storage' => [
                'database' => $url,
            ],
        ]);
        $database = $this->database();
        $tables = new TableNames($database);

        try {
            self::assertNull($database->path(), 'no file');
            $database->install();
            self::assertSame([], $database->install(), 'up to date after the first install');
            (new SpoolHitWriter($this->project->configLoader()))->write(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/'));

            $result = $database->withSchema(fn (): RollupResult => $this->rollupService($database, $tables)->rollup(5000));

            self::assertSame(1, $result->processed);
            $trend = (new TrendQuery($database->connection(), $tables, $this->project->siteTime()))->trend('7d', 'pageviews', 'none');
            self::assertSame(1, $trend['total']['value']);
            self::assertFalse($database->vacuum(), 'only a SQLite file of its own is rewritten');
            self::assertFileDoesNotExist($this->project->dir . '/var/data/request-analytics.db');
        } finally {
            foreach (self::TABLES as $table) {
                $database->connection()->executeStatement('DROP TABLE IF EXISTS ' . $table);
            }
        }
    }

    /**
     * MySQL / MariaDB: a table created with another character set (latin1,
     * the default of many older servers) or a case- and accent-insensitive
     * collation is converted, so every text fits and `/Cafe` and `/café`
     * stay two pages, as on the other platforms.
     */
    #[Group('database')]
    public function testMysqlTablesAreConvertedToUtf8mb4Binary(): void
    {
        $url = Database::url();
        if ($url === null || ! str_starts_with($url, 'm')) {
            self::markTestSkipped('Needs RA_TEST_DATABASE_URL of a MySQL or MariaDB server.');
        }

        $this->project->configure([
            'storage' => [
                'database' => $url,
            ],
        ]);
        $database = $this->database();

        try {
            $database->install();
            $connection = $database->connection();
            $connection->executeStatement('ALTER TABLE ra_pages CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci');
            $connection->executeStatement('ALTER TABLE ra_search CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');

            $converted = array_values(array_filter($database->install(), static fn (string $statement): bool => str_contains($statement, 'CONVERT')));
            sort($converted);

            self::assertSame([
                'ALTER TABLE ra_pages CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin',
                'ALTER TABLE ra_search CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_bin',
            ], $converted);
            self::assertSame([], $database->install());

            foreach (['/Cafe', '/cafe', '/café', '/kůň'] as $path) {
                $connection->insert('ra_pages', [
                    'day' => '2026-11-13',
                    'path' => $path,
                    'is_entry' => 0,
                    'hits' => 1,
                ]);
            }
            self::assertSame(['/Cafe', '/cafe', '/café', '/kůň'], $connection->fetchFirstColumn('SELECT path FROM ra_pages ORDER BY path'));
        } finally {
            foreach (self::TABLES as $table) {
                $database->connection()->executeStatement('DROP TABLE IF EXISTS ' . $table);
            }
        }
    }

    public function testWithoutTheTablesAQueryFails(): void
    {
        $this->expectException(TableNotFoundException::class);

        $this->database()
            ->connection()
            ->fetchOne('SELECT COUNT(*) FROM ra_daily');
    }

    private function database(): AnalyticsDatabase
    {
        return new AnalyticsDatabase($this->project->configLoader(), $this->project->dir, fn (): Connection => $this->boltConnection());
    }

    /**
     * A database like Bolt's: a SQLite file of its own.
     */
    private function boltConnection(): Connection
    {
        return $this->bolt ??= DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->project->dir . '/bolt.db',
        ]);
    }

    private function rollupService(AnalyticsDatabase $database, TableNames $tables): RollupService
    {
        $connection = $database->connection();
        $state = new RollupState($connection, $tables);

        return new RollupService(
            $this->project->configLoader(),
            $connection,
            $tables,
            new Aggregates($tables),
            new UpsertSqlBuilder(),
            new SpoolIngestor($this->project->configLoader(), $connection, $tables, $state),
            $state,
            $this->project->siteTime(),
            $this->project->dir,
            new DailyCap($connection, $tables),
        );
    }

    private static function int(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    /**
     * @return list<string>
     */
    private static function tables(Connection $connection): array
    {
        $tables = $connection->fetchFirstColumn("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");

        return array_values(array_filter($tables, is_string(...)));
    }
}
