<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Diagnostics;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;
use TomVondracek\RequestAnalytics\Storage\PrivateFiles;

/**
 * "At most once per N seconds" across the requests of a site, so a failure on
 * every request is reported (or retried) once in a while, not every time.
 *
 * In APCu when it is enabled; otherwise a marker file per key, whose mtime is
 * the start of the window. Two requests may pass at the same moment; that's
 * harmless for what it guards. When neither works, everything passes: a log
 * line too many is better than a failure nobody hears of. For work that must
 * not run on every request (`failOpen: false`), nothing passes then.
 */
final readonly class Throttle
{
    private const PRUNE_AFTER_SECONDS = 86_400;

    /**
     * @param bool $apcu use APCu when it is enabled; false: always the marker files
     */
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
        private bool $apcu = true,
    ) {
    }

    /**
     * True when $key has not passed within the last $seconds; then it passes now.
     *
     * @param bool $failOpen what to answer when the time cannot be kept (no APCu, and the marker cannot be written)
     */
    public function allow(string $key, int $seconds, bool $failOpen = true): bool
    {
        try {
            // Sites in one PHP-FPM pool share APCu.
            $hash = hash('xxh128', $this->projectDir . "\0" . $key);

            if ($this->apcu && function_exists('apcu_enabled') && apcu_enabled()) {
                return apcu_add('request_analytics.throttle.' . $hash, 1, $seconds);
            }

            $directory = $this->projectDir . '/var/request-analytics/throttle';
            PrivateFiles::directory($directory);

            $file = $directory . '/' . $hash;
            clearstatcache(true, $file);
            $since = @filemtime($file);
            if ($since !== false && time() - $since < $seconds) {
                return false;
            }

            // Not kept: the next request would pass as well.
            if (! @touch($file)) {
                return $failOpen;
            }

            PrivateFiles::restrict($file);
            if ($since === false) {
                // A new key: markers of keys that have not come back for a day go.
                self::prune($directory, max($seconds, self::PRUNE_AFTER_SECONDS));
            }

            return true;
        } catch (Throwable) {
            return $failOpen;
        }
    }

    private static function prune(string $directory, int $olderThan): void
    {
        $names = @scandir($directory);
        foreach ($names === false ? [] : $names as $name) {
            $file = $directory . '/' . $name;
            $modified = $name[0] === '.' ? false : @filemtime($file);
            if ($modified !== false && time() - $modified > $olderThan) {
                @unlink($file);
            }
        }
    }
}
