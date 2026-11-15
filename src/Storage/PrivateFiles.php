<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Storage;

use RuntimeException;

/**
 * The extension's files and directories (the SQLite file, the spool, locks,
 * throttles) are for the owner and the group only: the web server and cron
 * (same user, or a shared group; see the README), never other accounts on
 * the server.
 *
 * Modes are set explicitly: mkdir() and new files take the process's umask,
 * often 022 (readable by everybody).
 */
final class PrivateFiles
{
    public const DIRECTORY_MODE = 0o770;
    public const FILE_MODE = 0o660;

    /**
     * Creates the directory (and its parents) when it is missing; the
     * directory itself owner and group only.
     *
     * @throws RuntimeException when it cannot be created
     */
    public static function directory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! @mkdir($directory, self::DIRECTORY_MODE, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('The directory "%s" could not be created.', $directory));
        }

        @chmod($directory, self::DIRECTORY_MODE);
        clearstatcache(true, $directory);
    }

    /**
     * Owner and group only; nothing when the file is not there or not ours.
     */
    public static function restrict(string $path): void
    {
        clearstatcache(true, $path);
        if (file_exists($path)) {
            @chmod($path, is_dir($path) ? self::DIRECTORY_MODE : self::FILE_MODE);
            // Before PHP 8.4 chmod() leaves the stat cache as it was: a later
            // fileperms() in this process would still see the old mode.
            clearstatcache(true, $path);
        }
    }
}
