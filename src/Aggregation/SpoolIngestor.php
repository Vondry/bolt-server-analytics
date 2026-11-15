<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Aggregation;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;
use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;
use TomVondracek\RequestAnalytics\Storage\TableNames;
use TomVondracek\RequestAnalytics\Writer\SpoolHitWriter;

/**
 * Moves the lines of the spool files into `ra_hit`.
 *
 * A file is claimed by renaming it (with a random part, so the names of
 * several servers never meet), and the collector continues in a fresh file.
 * Its lines are inserted in batches, one transaction each, which also saves how
 * far the file got, in a row of its own (see RollupState). So the time budget
 * is checked after every batch, a large file is spread over several runs, and
 * a file whose rows are committed is never counted again, also when the
 * process dies before the file is deleted, and also when other servers move
 * their own spool into the same database meanwhile.
 *
 * Files claimed by an earlier run come first, then new ones, oldest first; a
 * run stops at the first file it does not finish.
 */
final readonly class SpoolIngestor
{
    private const CLAIMED = '.ingest';

    /**
     * Lines per transaction, and so how often the position in the file is saved.
     */
    private const ROWS_PER_TRANSACTION = 1000;

    /**
     * Rows per INSERT. The values are written into the statement (quoted), not
     * bound: Doctrine's debug middleware and logger keep the parameters of every
     * statement in `dev`, and 19 000 of them per batch exhausted the memory of
     * a rollup there. Even with every text column at its maximum, 200 rows stay
     * below SQLite's limit of 1 MB per statement.
     */
    private const ROWS_PER_STATEMENT = 200;

    /**
     * How much of the spool pending() reads; beyond that it extrapolates.
     */
    private const PENDING_SAMPLE_BYTES = 1 << 20;

    public function __construct(
        private ConfigLoader $configLoader,
        #[Autowire(expression: AnalyticsDatabase::CONNECTION)]
        private Connection $connection,
        private TableNames $tables,
        private RollupState $state,
    ) {
    }

    /**
     * @param int|float $deadline `hrtime(true)` after which no further batch is started (at least one batch runs)
     *
     * @return int number of rows moved
     */
    public function ingest(int|float $deadline): int
    {
        $files = $this->files();
        if ($files === []) {
            return 0;
        }

        $ingested = 0;

        foreach (self::inOrder($files) as $file) {
            $claimed = self::claim($file);
            if ($claimed === null) {
                continue;
            }

            $offset = str_ends_with($file, self::CLAIMED) ? $this->state->spoolOffset(basename($claimed)) : 0;
            [$rows, $finished] = $this->ingestFile($claimed, $offset, $deadline);
            $ingested += $rows;

            if (! $finished || hrtime(true) >= $deadline) {
                break;
            }
        }

        return $ingested;
    }

    /**
     * Number of lines that are waiting in the spool: counted in the first
     * megabyte, extrapolated beyond it, so the dashboard stays fast however big
     * the backlog gets.
     */
    public function pending(): int
    {
        $files = $this->files();
        if ($files === []) {
            return 0;
        }

        $offsets = $this->state->spoolOffsets();
        $total = 0;
        $sampled = 0;
        $lines = 0;

        foreach ($files as $file) {
            $offset = $offsets[basename($file)] ?? 0;
            $size = max(0, (int) @filesize($file) - $offset);
            $total += $size;

            $budget = min($size, self::PENDING_SAMPLE_BYTES - $sampled);
            if ($budget <= 0) {
                continue;
            }

            $chunk = @file_get_contents($file, false, null, $offset, $budget);
            if (is_string($chunk)) {
                // Bytes, like filesize(); the chunk may end inside a character.
                $sampled += mb_strlen($chunk, '8bit');
                $lines += mb_substr_count($chunk, "\n", '8bit');
            }
        }

        if ($sampled === 0) {
            return 0;
        }

        return $sampled >= $total ? $lines : (int) round($lines * $total / $sampled);
    }

    /**
     * Deletes the spool files of the hours of $fromDay and later, or every
     * spool file (null), without moving their lines into `ra_hit`. Claimed
     * files too, and the saved position goes with its file. Only the spool of
     * this server: with several web servers, each has its own.
     *
     * Only while holding the rollup lock (RollupService::lock()), so no run
     * reads the files meanwhile. A collector that appends to a file as it is
     * deleted loses that line: a hit of today, so of the deleted days.
     *
     * @param string|null $fromDay Y-m-d, site timezone
     *
     * @return int files deleted
     */
    public function discard(?string $fromDay): int
    {
        // The name of a file starts with the hour of its hits: YYYYMMDDHH.
        $from = $fromDay === null ? null : str_replace('-', '', $fromDay);
        $deleted = [];

        foreach ($this->files() as $file) {
            $name = basename($file);
            if ($from !== null && strcmp(mb_substr($name, 0, 8), $from) < 0) {
                continue;
            }

            if (! @unlink($file)) {
                clearstatcache(true, $file);
                if (is_file($file)) {
                    throw new RuntimeException(sprintf('The spool file "%s" could not be deleted; check that the web server and cron users can both write to the spool directory.', $file));
                }

                continue;
            }

            $deleted[] = $name;
        }

        $this->state->forgetSpoolFiles($deleted);

        return count($deleted);
    }

    /**
     * @return list<string> oldest first
     */
    private function files(): array
    {
        $directory = $this->configLoader->load()->spoolDir;
        $names = is_dir($directory) ? scandir($directory) : false;
        if ($names === false) {
            return [];
        }

        $files = [];
        foreach ($names as $name) {
            if (str_ends_with($name, SpoolHitWriter::EXTENSION) || str_ends_with($name, self::CLAIMED)) {
                $files[] = $directory . '/' . $name;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The files claimed by an earlier run first, then the new ones; oldest
     * first within each group.
     *
     * @param list<string> $files sorted
     *
     * @return list<string>
     */
    private static function inOrder(array $files): array
    {
        $rank = static fn (string $file): int => str_ends_with($file, self::CLAIMED) ? 0 : 1;

        usort($files, static fn (string $a, string $b): int => [$rank($a), $a] <=> [$rank($b), $b]);

        return $files;
    }

    private static function claim(string $file): ?string
    {
        if (str_ends_with($file, self::CLAIMED)) {
            // Claimed by an earlier run that stopped halfway.
            return $file;
        }

        $claimed = $file . '.' . bin2hex(random_bytes(4)) . self::CLAIMED;
        if (@rename($file, $claimed)) {
            return $claimed;
        }

        // Only the rollup renames or deletes spool files, and only one runs at
        // a time: a file that is gone is no error, one that stays is.
        clearstatcache(true, $file);
        if (is_file($file)) {
            throw new RuntimeException(sprintf('The spool file "%s" could not be claimed; check that the web server and cron users can both write to the spool directory.', $file));
        }

        return null;
    }

    /**
     * @return array{int, bool} rows moved, and whether the file is done (and deleted)
     */
    private function ingestFile(string $file, int $offset, int|float $deadline): array
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            throw new RuntimeException(sprintf('The spool file "%s" could not be opened; check that the web server and cron users can both read and write the spool directory.', $file));
        }

        $name = basename($file);
        $ingested = 0;
        $deleted = false;

        try {
            // A collector that opened the file just before it was renamed
            // finishes its line first (see SpoolHitWriter).
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException(sprintf('The spool file "%s" could not be locked.', $file));
            }

            fseek($handle, $offset);

            do {
                $batch = [];
                while (count($batch) < self::ROWS_PER_TRANSACTION && ($line = fgets($handle)) !== false) {
                    $row = json_decode($line, true);
                    $hit = is_array($row) ? Hit::fromRow($row) : null;
                    if ($hit instanceof Hit) {
                        $batch[] = $hit;
                    }
                }

                $position = (int) ftell($handle);
                $this->insert($batch, $name, $position);
                $ingested += count($batch);
                // Nobody appends to a claimed file while it is locked here.
                $stat = fstat($handle);
                $finished = $stat === false || $position >= $stat['size'];
            } while (! $finished && hrtime(true) < $deadline);

            // Deleted while still locked: a collector that gets the lock next
            // finds the file gone and writes to a new one (see SpoolHitWriter).
            // A file that is kept is read to its end by the next run, so a line
            // appended to it after this point is not lost either.
            $deleted = $finished && @unlink($file);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        if ($finished && ! $deleted) {
            // Windows does not delete an open file.
            $deleted = @unlink($file);
        }

        // The position goes with its file. A file that could not be deleted
        // keeps it, so the next run does not read it again from the start.
        if ($deleted) {
            $this->state->forgetSpoolFiles([$name]);
        }

        return [$ingested, $finished];
    }

    /**
     * One transaction: the rows, and how far the file got.
     *
     * @param list<Hit> $hits
     */
    private function insert(array $hits, string $file, int $offset): void
    {
        $this->connection->beginTransaction();

        try {
            foreach (array_chunk($hits, self::ROWS_PER_STATEMENT) as $chunk) {
                $this->connection->executeStatement(sprintf(
                    'INSERT INTO %s (%s) VALUES %s',
                    $this->tables->hit(),
                    implode(', ', array_keys(Hit::COLUMNS)),
                    implode(', ', array_map($this->values(...), $chunk))
                ));
            }

            $this->state->saveSpoolOffset($file, $offset);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * The row as SQL literals: strings quoted by the driver, booleans the way
     * the platform writes them. Hit::fromRow() has checked every value.
     */
    private function values(Hit $hit): string
    {
        $platform = $this->connection->getDatabasePlatform();
        $literals = [];
        foreach ($hit->toRow() as $value) {
            $literals[] = match (true) {
                $value === null => 'NULL',
                is_bool($value) => var_export($platform->convertBooleans($value), true),
                is_int($value) => (string) $value,
                default => $this->connection->quote($value),
            };
        }

        return '(' . implode(', ', $literals) . ')';
    }
}
