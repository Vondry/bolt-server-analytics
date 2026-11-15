<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Aggregation;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\FlockStore;
use Throwable;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Config\RequestAnalyticsConfig;
use TomVondracek\RequestAnalytics\SiteTime;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;
use TomVondracek\RequestAnalytics\Storage\PrivateFiles;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * The cold path: rolls raw rows into the aggregate tables and deletes them.
 * Runs when an administrator opens the dashboard (within a time budget) or
 * from cron; never during a public request.
 *
 * The work is set-based SQL in chunks of `id`; rows are never loaded into PHP,
 * except when the spool is moved into the database.
 *
 * On MySQL / MariaDB the chunks run in READ COMMITTED: under the default
 * REPEATABLE READ, `… WHERE id <= :b` also locks the gap after the newest row,
 * and the rollup of another web server, moving its own spool into `ra_hit`,
 * would wait until the chunk is committed.
 */
final readonly class RollupService
{
    private const LOCK = 'rollup';

    /**
     * How often a chunk is retried when a raw row was committed while it ran.
     */
    private const MAX_RETRIES = 5;

    /**
     * How long the position of a spool file is kept when its file was deleted
     * but its row was not (a rollup that died in between).
     */
    private const SPOOL_PROGRESS_KEPT_DAYS = 90;

    public function __construct(
        private ConfigLoader $configLoader,
        #[Autowire(expression: AnalyticsDatabase::CONNECTION)]
        private Connection $connection,
        private TableNames $tables,
        private Aggregates $aggregates,
        private UpsertSqlBuilder $sqlBuilder,
        private SpoolIngestor $spoolIngestor,
        private RollupState $state,
        private SiteTime $siteTime,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    /**
     * @param int|null $budgetMs time after which no further chunk is started; null = `aggregation.time_budget_ms`
     *
     * @phpstan-impure
     */
    public function rollup(?int $budgetMs = null): RollupResult
    {
        $config = $this->configLoader->load();
        $start = hrtime(true);
        $deadline = $start + ($budgetMs ?? $config->timeBudgetMs) * 1_000_000;

        // Two dashboard tabs at once: the second one just shows the current data.
        $lock = $this->lock();
        if (! $lock->acquire()) {
            return new RollupResult(0, $this->pending(), self::elapsedMs($start), true);
        }

        try {
            // The raw rows it deletes hold the most detail: not left in the file.
            AnalyticsDatabase::secureDelete($this->connection);
            $this->state->ensure();
            $this->spoolIngestor->ingest($deadline);
            $processed = $this->aggregate($config, $deadline);
            $this->purge($config);
        } finally {
            $lock->release();
        }

        return new RollupResult($processed, $this->pending(), self::elapsedMs($start));
    }

    /**
     * The lock a rollup holds while it runs, on this server: whoever else
     * changes the raw rows or the spool (StatisticsEraser) holds it too.
     */
    public function lock(): SharedLockInterface
    {
        return $this->lockFactory()
            ->createLock(self::LOCK);
    }

    /**
     * Raw rows that are not aggregated yet, in `ra_hit` and in the spool.
     */
    public function pending(): int
    {
        $rows = $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $this->tables->hit()));

        return (is_numeric($rows) ? (int) $rows : 0) + $this->spoolIngestor->pending();
    }

    /**
     * When the rollup last ran, in the site timezone; null before the first run.
     */
    public function aggregatedUntil(): ?DateTimeImmutable
    {
        $value = $this->state->lastRollupAt();
        if ($value === null) {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone($this->siteTime->timezone());
        } catch (Throwable) {
            return null;
        }
    }

    private function aggregate(RequestAnalyticsConfig $config, int|float $deadline): int
    {
        $hitTable = $this->tables->hit();

        // Rows that arrive from now on wait for the next run, so the loop ends.
        $max = $this->connection->fetchOne(sprintf('SELECT MAX(id) FROM %s', $hitTable));

        if (! is_numeric($max)) {
            $this->touchState();

            return 0;
        }

        $platform = $this->connection->getDatabasePlatform();
        $statements = array_map(
            fn (Aggregate $aggregate): string => $this->sqlBuilder->build($platform, $aggregate, $hitTable),
            $this->aggregates->all()
        );

        $processed = 0;
        $retries = 0;

        do {
            $deleted = $this->aggregateChunk($hitTable, (int) $max, $config->chunkSize, $statements);

            if ($deleted === null) {
                if (++$retries > self::MAX_RETRIES) {
                    throw new RuntimeException('Request Analytics: raw rows kept changing while they were aggregated; the rollup was stopped.');
                }

                continue;
            }

            if ($deleted === 0) {
                break;
            }

            $processed += $deleted;
        } while (hrtime(true) < $deadline);

        return $processed;
    }

    /**
     * One chunk in one transaction: add its rows to every aggregate, delete them.
     *
     * The predicates are `id <= b` without a lower bound, so a row that was
     * committed late with a smaller id is picked up by a later chunk.
     *
     * @param list<string> $statements
     *
     * @return int|null rows aggregated and deleted; 0 when nothing is left; null when the chunk has to be retried
     */
    private function aggregateChunk(string $hitTable, int $snapshotMax, int $chunkSize, array $statements): ?int
    {
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            // Applies to the next transaction only.
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        $this->connection->beginTransaction();

        try {
            // Locks the state row until the commit: a rollup on another server
            // (which does not share the file lock) waits here.
            $this->touchState();

            $min = $this->connection->fetchOne(sprintf('SELECT MIN(id) FROM %s', $hitTable));
            if (! is_numeric($min) || (int) $min > $snapshotMax) {
                $this->connection->commit();

                return 0;
            }

            $bound = [
                UpsertSqlBuilder::UPPER_BOUND => min((int) $min + $chunkSize - 1, $snapshotMax),
            ];
            $type = [
                UpsertSqlBuilder::UPPER_BOUND => ParameterType::INTEGER,
            ];

            $expected = $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE id <= :%s', $hitTable, UpsertSqlBuilder::UPPER_BOUND), $bound, $type);

            foreach ($statements as $statement) {
                $this->connection->executeStatement($statement, $bound, $type);
            }

            $deleted = (int) $this->connection->executeStatement(sprintf('DELETE FROM %s WHERE id <= :%s', $hitTable, UpsertSqlBuilder::UPPER_BOUND), $bound, $type);

            // Rows only ever appear while the chunk runs (nobody else deletes).
            // The same count before and after means every statement saw the same
            // rows; otherwise a row was committed in between and may have been
            // deleted without being counted everywhere.
            if (! is_numeric($expected) || (int) $expected !== $deleted) {
                $this->connection->rollBack();

                return null;
            }

            $this->connection->commit();

            return $deleted;
        } catch (Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Deletes detailed aggregates older than `privacy.retention_months`, once a
     * day. The daily totals stay: the trend compares up to three years. Also
     * forgets positions of spool files that were left behind.
     */
    private function purge(RequestAnalyticsConfig $config): void
    {
        $today = $this->siteTime->now()
            ->format('Y-m-d');
        if ($this->state->lastPurgeDay() === $today) {
            return;
        }

        $this->state->pruneSpoolProgress($this->siteTime->now()->modify(sprintf('-%d days', self::SPOOL_PROGRESS_KEPT_DAYS))->format('Ymd'));

        if ($config->retentionMonths === 0) {
            $this->state->purged($today);

            return;
        }

        $cutoff = (new DateTimeImmutable(mb_substr($today, 0, 8) . '01'))
            ->modify(sprintf('-%d months', $config->retentionMonths))
            ->format('Y-m-d');

        $this->connection->beginTransaction();

        try {
            foreach ($this->aggregates->detailed() as $aggregate) {
                $this->connection->executeStatement(sprintf('DELETE FROM %s WHERE day < :cutoff', $aggregate->table), [
                    'cutoff' => $cutoff,
                ]);
            }

            $this->state->purged($today);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    private function touchState(): void
    {
        $this->state->touch($this->siteTime->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    private function lockFactory(): LockFactory
    {
        $directory = $this->projectDir . '/var/request-analytics';
        PrivateFiles::directory($directory);

        return new LockFactory(new FlockStore($directory));
    }

    private static function elapsedMs(int|float $start): int
    {
        return (int) round((hrtime(true) - $start) / 1_000_000);
    }
}
