<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Aggregation;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * The single row of `ra_state`: when the rollup ran, when the retention cleanup
 * ran, and which days were deleted on purpose; and `ra_spool`: how far each
 * claimed spool file has been moved into `ra_hit`.
 */
final readonly class RollupState
{
    private const ID = 1;

    /**
     * How long a deleted period is remembered: longer than any window of the
     * insights (90 days, and the 14 before them).
     */
    private const ERASED_KEPT_DAYS = 180;

    public function __construct(
        #[Autowire(expression: AnalyticsDatabase::CONNECTION)]
        private Connection $connection,
        private TableNames $tables,
    ) {
    }

    /**
     * Creates the row on the first run; every other method expects it.
     */
    public function ensure(): void
    {
        if ($this->get('id') !== null) {
            return;
        }

        try {
            $this->connection->insert($this->tables->state(), [
                'id' => self::ID,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another server created it in the meantime.
        }
    }

    /**
     * UTC, `Y-m-d H:i:s`; null before the first run.
     */
    public function lastRollupAt(): ?string
    {
        return $this->get('last_rollup_at');
    }

    /**
     * Updating the row first in a transaction also locks it until the commit:
     * a rollup on another server, which does not share the file lock, waits.
     *
     * @param string $at UTC, `Y-m-d H:i:s`
     */
    public function touch(string $at): void
    {
        $this->set([
            'last_rollup_at' => $at,
        ]);
    }

    /**
     * Site timezone, `Y-m-d`; null before the first cleanup.
     */
    public function lastPurgeDay(): ?string
    {
        $day = $this->get('last_purge_day');

        return $day === null ? null : mb_substr($day, 0, 10);
    }

    public function purged(string $day): void
    {
        $this->set([
            'last_purge_day' => $day,
        ]);
    }

    /**
     * Bytes of each claimed spool file that are stored in `ra_hit`, of every
     * server; a file that is not listed has none.
     *
     * @return array<string, int> file name => bytes
     */
    public function spoolOffsets(): array
    {
        $offsets = [];
        foreach ($this->connection->fetchAllAssociative(sprintf('SELECT file_name, bytes_read FROM %s', $this->tables->spoolProgress())) as $row) {
            $file = $row['file_name'] ?? null;
            $bytes = $row['bytes_read'] ?? null;
            if (is_string($file) && is_numeric($bytes)) {
                $offsets[$file] = (int) $bytes;
            }
        }

        return $offsets;
    }

    /**
     * Bytes of the claimed spool file that are stored in `ra_hit`.
     */
    public function spoolOffset(string $file): int
    {
        $bytes = $this->connection->fetchOne(sprintf('SELECT bytes_read FROM %s WHERE file_name = :file', $this->tables->spoolProgress()), [
            'file' => $file,
        ]);

        return is_numeric($bytes) ? (int) $bytes : 0;
    }

    /**
     * In the transaction of the batch that stored those bytes.
     */
    public function saveSpoolOffset(string $file, int $bytes): void
    {
        $this->forgetSpoolFiles([$file]);
        $this->connection->insert($this->tables->spoolProgress(), [
            'file_name' => $file,
            'bytes_read' => $bytes,
        ], [
            'file_name' => ParameterType::STRING,
            'bytes_read' => ParameterType::INTEGER,
        ]);
    }

    /**
     * The files are deleted: their positions go too.
     *
     * @param list<string> $files names
     */
    public function forgetSpoolFiles(array $files): void
    {
        if ($files === []) {
            return;
        }

        $this->connection->executeStatement(sprintf('DELETE FROM %s WHERE file_name IN (:files)', $this->tables->spoolProgress()), [
            'files' => $files,
        ], [
            'files' => ArrayParameterType::STRING,
        ]);
    }

    /**
     * Forgets the positions of files of hours before $beforeHour
     * (`YYYYMMDDHH`): left behind by a rollup that died between deleting a
     * file and its position. Only long after: another server may still be
     * working off a file of its own.
     */
    public function pruneSpoolProgress(string $beforeHour): void
    {
        $this->connection->executeStatement(sprintf('DELETE FROM %s WHERE file_name < :before', $this->tables->spoolProgress()), [
            'before' => $beforeHour,
        ]);
    }

    /**
     * The periods whose statistics were deleted, oldest first.
     *
     * @return list<array{from: string, to: string}> Y-m-d, site timezone
     */
    public function erased(): array
    {
        $value = $this->get('erased_periods');
        $periods = $value === null ? null : json_decode($value, true);

        $erased = [];
        foreach (is_array($periods) ? $periods : [] as $period) {
            if (is_array($period) && is_string($period['from'] ?? null) && is_string($period['to'] ?? null)) {
                $erased[] = [
                    'from' => $period['from'],
                    'to' => $period['to'],
                ];
            }
        }

        return $erased;
    }

    /**
     * Adds a deleted period, merged with those it overlaps or touches. Periods
     * that ended more than ERASED_KEPT_DAYS before the latest are forgotten.
     *
     * @param string|null $from first deleted day; null when everything was deleted: no day is left to doubt
     * @param string $to last deleted day (today)
     */
    public function erase(?string $from, string $to): void
    {
        if ($from === null) {
            $this->connection->update($this->tables->state(), [
                'erased_periods' => null,
            ], [
                'id' => self::ID,
            ]);

            return;
        }

        $periods = [...$this->erased(), [
            'from' => $from,
            'to' => $to,
        ]];
        $latest = max(array_column($periods, 'to'));
        $kept = (new DateTimeImmutable($latest, new DateTimeZone('UTC')))->modify(sprintf('-%d days', self::ERASED_KEPT_DAYS))->format('Y-m-d');
        $periods = array_filter($periods, static fn (array $period): bool => $period['to'] >= $kept);
        usort($periods, static fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        /** @var list<array{from: string, to: string}> $merged */
        $merged = [];
        foreach ($periods as $period) {
            $last = array_key_last($merged);
            if ($last !== null && $period['from'] <= self::nextDay($merged[$last]['to'])) {
                $merged[$last]['to'] = max($merged[$last]['to'], $period['to']);

                continue;
            }

            $merged[] = $period;
        }

        $this->set([
            'erased_periods' => (string) json_encode($merged),
        ]);
    }

    private static function nextDay(string $day): string
    {
        return (new DateTimeImmutable($day, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
    }

    private function get(string $column): ?string
    {
        // The column name is one of this class's own.
        $value = $this->connection->fetchOne(sprintf('SELECT %s FROM %s WHERE id = :id', $column, $this->tables->state()), [
            'id' => self::ID,
        ]);

        // false: no row.
        return is_string($value) || is_int($value) ? ((string) $value === '' ? null : (string) $value) : null;
    }

    /**
     * @param array<string, string|int> $values
     */
    private function set(array $values): void
    {
        $this->connection->update($this->tables->state(), $values, [
            'id' => self::ID,
        ], array_map(static fn (string|int $value): ParameterType => is_int($value) ? ParameterType::INTEGER : ParameterType::STRING, $values));
    }
}
