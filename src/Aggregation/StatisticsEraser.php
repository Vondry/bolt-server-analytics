<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Aggregation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Throwable;
use TomVondracek\RequestAnalytics\SiteTime;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * Deletes the statistics of the last days, up to now, or all of them: the
 * aggregates, the daily totals, the raw rows that wait for the rollup and the
 * spool files. Older days stay as they are.
 *
 * Whole days only: the tables keep no time of day (`ra_hit` and `ra_hourly`
 * the hour, the other tables only the day), so an hour already counted into a
 * day cannot be taken out of it again.
 *
 * Holds the rollup lock, so no rollup moves rows meanwhile. The rows are
 * deleted in one transaction: all of the period or nothing. On SQLite the
 * freed pages are overwritten (`secure_delete`), so the statistics are not
 * left in the file.
 *
 * The period is remembered in the same transaction (RollupState::erase()):
 * the insights must not take its empty days for an outage.
 */
final readonly class StatisticsEraser
{
    /**
     * The longest period that can be chosen (a hundred years); longer is everything.
     */
    public const MAX_DAYS = 36_500;

    /**
     * How long a running rollup is waited for.
     */
    private const LOCK_WAIT_MS = 10_000;

    private const LOCK_RETRY_US = 100_000;

    public function __construct(
        #[Autowire(expression: AnalyticsDatabase::CONNECTION)]
        private Connection $connection,
        private TableNames $tables,
        private Aggregates $aggregates,
        private SpoolIngestor $spoolIngestor,
        private RollupService $rollupService,
        private RollupState $state,
        private SiteTime $siteTime,
        private int $lockWaitMs = self::LOCK_WAIT_MS,
    ) {
    }

    /**
     * The first day of the last $days days, today included; null = everything.
     *
     * @return string|null Y-m-d, site timezone
     */
    public function firstDay(?int $days): ?string
    {
        if ($days === null) {
            return null;
        }

        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new InvalidArgumentException(sprintf('"days" must be a number from 1 to %d, or "all"; %d given.', self::MAX_DAYS, $days));
        }

        return $this->siteTime->now()
            ->modify(sprintf('-%d days', $days - 1))
            ->format('Y-m-d');
    }

    /**
     * What erase() would delete: page views and visits of the period, those
     * the rollup has not counted yet included (not those still in the spool).
     *
     * @return array{from: ?string, to: string, firstDay: ?string, pageviews: int, visits: int}
     */
    public function preview(?int $days): array
    {
        $from = $this->firstDay($days);
        $where = $from === null ? '' : ' WHERE day >= :from';
        $parameters = $from === null ? [] : [
            'from' => $from,
        ];

        $daily = $this->connection->fetchAssociative(sprintf('SELECT SUM(pageviews) AS pageviews, SUM(entries) AS visits, MIN(day) AS first_day FROM %s%s', $this->tables->daily(), $where), $parameters);
        $raw = $this->connection->fetchAssociative(sprintf(
            "SELECT SUM(CASE WHEN kind = 'pageview' THEN 1 ELSE 0 END) AS pageviews, SUM(CASE WHEN kind = 'pageview' AND is_entry THEN 1 ELSE 0 END) AS visits, MIN(day) AS first_day FROM %s%s",
            $this->tables->hit(),
            $where,
        ), $parameters);

        $firstDays = array_filter([self::text($daily, 'first_day'), self::text($raw, 'first_day')], static fn (?string $day): bool => $day !== null);

        return [
            'from' => $from,
            'to' => $this->siteTime->now()
                ->format('Y-m-d'),
            'firstDay' => $firstDays === [] ? null : min($firstDays),
            'pageviews' => self::number($daily, 'pageviews') + self::number($raw, 'pageviews'),
            'visits' => self::number($daily, 'visits') + self::number($raw, 'visits'),
        ];
    }

    /**
     * @param int|null $days today and the days before it; null = everything
     *
     * @return array{from: ?string, rows: int, spoolFiles: int}
     *
     * @throws LockConflictedException when a rollup is still running after the lock wait (10 s)
     */
    public function erase(?int $days): array
    {
        $from = $this->firstDay($days);

        $lock = $this->rollupService->lock();
        $deadline = hrtime(true) + $this->lockWaitMs * 1_000_000;
        while (! $lock->acquire()) {
            if (hrtime(true) >= $deadline) {
                throw new LockConflictedException('A rollup of the statistics is running.');
            }

            usleep(self::LOCK_RETRY_US);
        }

        try {
            $rows = $this->deleteRows($from, $this->siteTime->now()->format('Y-m-d'));

            return [
                'from' => $from,
                'rows' => $rows,
                'spoolFiles' => $this->spoolIngestor->discard($from),
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * @param string $today the last day of the period
     */
    private function deleteRows(?string $from, string $today): int
    {
        $sqlite = $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
        // Overwrite the freed pages with zeros instead of leaving the rows in the file.
        AnalyticsDatabase::secureDelete($this->connection);

        $tables = [
            $this->tables->hit(),
            ...array_map(static fn (Aggregate $aggregate): string => $aggregate->table, $this->aggregates->all()),
        ];

        $this->connection->beginTransaction();

        try {
            $rows = 0;
            foreach ($tables as $table) {
                // DELETE, not TRUNCATE: MySQL commits a TRUNCATE at once.
                $rows += $from === null
                    ? (int) $this->connection->executeStatement(sprintf('DELETE FROM %s', $table))
                    : (int) $this->connection->executeStatement(sprintf('DELETE FROM %s WHERE day >= :from', $table), [
                        'from' => $from,
                    ]);
            }

            $this->state->ensure();
            $this->state->erase($from, $today);

            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }

        if ($sqlite) {
            // In WAL mode the zeroed pages reach the file at a checkpoint; start
            // one now, without waiting for readers (they get it done later).
            $this->connection->fetchAssociative('PRAGMA wal_checkpoint(PASSIVE)');
        }

        return $rows;
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private static function number(array|false $row, string $column): int
    {
        $value = $row === false ? null : $row[$column] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private static function text(array|false $row, string $column): ?string
    {
        $value = $row === false ? null : $row[$column] ?? null;

        // Y-m-d; MySQL may add a time to a DATE in some drivers.
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 10) : null;
    }
}
