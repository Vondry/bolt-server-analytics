<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Writer;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Storage\PrivateFiles;

/**
 * The collector's writer: one NDJSON line appended to the spool file of the
 * hit's hour (`<spool_dir>/<YYYYMMDDHH>.ndjson`); the rollup moves the lines to
 * `ra_hit`. The collector never opens a database: SQLite allows one writer at a
 * time, and a rollup, a retention delete or a VACUUM would make it wait (up to
 * the busy timeout), holding a PHP worker; under Apache mod_php or CGI, where
 * `kernel.terminate` runs before the connection closes, the visitor too. A
 * file append takes some 50 µs and never waits for the database.
 *
 * Lines are far shorter than 4 KB and written with an exclusive lock, so
 * concurrent appends do not interleave. The rollup claims a file by renaming
 * it, and deletes it once read while it still holds the lock. So a line is
 * only appended to a file that still exists when the lock is held: the rollup
 * reads it then, or on its next run. The link count of the open file decides,
 * not a lookup of the path: right after a rename, another process may still
 * resolve the path to the renamed file (seen on macOS, for about 2 ms).
 *
 * The lock is never waited for: the rollup holds the lock of a claimed file
 * while it reads it, up to its whole time budget (50 s from cron). A collector
 * that opened the file just before the claim would wait that long, holding a
 * PHP worker. Instead it tries again, briefly, and gives up after MAX_WAIT_MS:
 * another collector holds the lock for microseconds only.
 *
 * The spool never fills the disk (`collector.max_spool_mb`): when a new file
 * is started (once an hour), the size of the spool is checked, and from the
 * limit on the hit is dropped (and logged, see FailureReporter) until the
 * rollup has worked the spool off. A marker file keeps that answer for
 * FULL_RECHECK_SECONDS, so a full spool is not summed up on every request.
 */
#[AsAlias(HitWriterInterface::class)]
final readonly class SpoolHitWriter implements HitWriterInterface
{
    public const EXTENSION = '.ndjson';
    private const MAX_WAIT_MS = 1000;
    private const RETRY_PAUSE_US = 500;
    private const MAX_RETRY_PAUSE_US = 10_000;

    /**
     * Marker of a full spool in the spool directory; its mtime is the last check.
     */
    public const FULL_MARKER = '.full';

    private const FULL_RECHECK_SECONDS = 60;

    public function __construct(
        private ConfigLoader $configLoader,
    ) {
    }

    public function write(Hit $hit): void
    {
        $config = $this->configLoader->load();
        $directory = $config->spoolDir;
        $file = $directory . '/' . str_replace('-', '', $hit->day) . sprintf('%02d', $hit->hour) . self::EXTENSION;
        $line = json_encode($hit->toRow(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        PrivateFiles::directory($directory);
        if ($config->maxSpoolMb > 0 && ! is_file($file)) {
            self::assertRoom($directory, $config->maxSpoolMb);
        }

        // Another try covers a file that is locked, or that was claimed and
        // read between opening and locking it. The pause outlasts a path that
        // still resolves to the deleted file for a moment.
        $deadline = hrtime(true) + self::MAX_WAIT_MS * 1_000_000;
        for ($attempt = 1; ; ++$attempt) {
            if ($this->append($file, $line)) {
                return;
            }

            if (hrtime(true) >= $deadline) {
                break;
            }

            usleep(min(self::RETRY_PAUSE_US * $attempt, self::MAX_RETRY_PAUSE_US));
        }

        throw new RuntimeException(sprintf('The spool file "%s" stayed locked for %d ms; the hit was dropped.', $file, self::MAX_WAIT_MS));
    }

    /**
     * @throws RuntimeException when the spool holds $maxMb or more
     */
    private static function assertRoom(string $directory, int $maxMb): void
    {
        $marker = $directory . '/' . self::FULL_MARKER;
        clearstatcache(true, $marker);
        $checked = @filemtime($marker);

        if ($checked === false || time() - $checked >= self::FULL_RECHECK_SECONDS) {
            if (self::size($directory) < $maxMb * 1024 * 1024) {
                if ($checked !== false) {
                    @unlink($marker);
                }

                return;
            }

            @touch($marker);
            PrivateFiles::restrict($marker);
        }

        throw new RuntimeException(sprintf('The spool "%s" holds %d MB (collector.max_spool_mb): visits are dropped until the rollup has worked it off. Check that `bin/console request-analytics:rollup` runs and reaches its database.', $directory, $maxMb));
    }

    /**
     * Bytes of the spool files, claimed ones too.
     */
    private static function size(string $directory): int
    {
        $bytes = 0;
        $names = @scandir($directory);
        foreach ($names === false ? [] : $names as $name) {
            if (str_ends_with($name, self::EXTENSION) || str_ends_with($name, '.ingest')) {
                $bytes += (int) @filesize($directory . '/' . $name);
            }
        }

        return $bytes;
    }

    /**
     * @return bool false when the file is locked, or was claimed meanwhile (try again)
     */
    private function append(string $file, string $line): bool
    {
        $new = ! is_file($file);
        $handle = @fopen($file, 'a');
        if ($handle === false) {
            throw new RuntimeException(sprintf('The spool file "%s" could not be opened.', $file));
        }

        if ($new) {
            PrivateFiles::restrict($file);
        }

        try {
            if (! flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                if ($wouldBlock === 1) {
                    return false;
                }

                throw new RuntimeException(sprintf('The spool file "%s" could not be locked.', $file));
            }

            // Deleted by the rollup, which read it: the line belongs in a new file.
            $opened = fstat($handle);
            if ($opened === false || $opened['nlink'] < 1) {
                return false;
            }

            // Claimed meanwhile: a new file is the better home, though the
            // claimed one would still be read (this check can see a stale path).
            clearstatcache(true, $file);
            $atPath = @stat($file);
            if ($atPath === false || $atPath['ino'] !== $opened['ino'] || $atPath['dev'] !== $opened['dev']) {
                return false;
            }

            // Bytes, not characters: paths and terms may be any UTF-8.
            if (fwrite($handle, $line) !== mb_strlen($line, '8bit')) {
                throw new RuntimeException(sprintf('The spool file "%s" could not be written.', $file));
            }

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
