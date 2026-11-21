<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Collector;

/**
 * Why a request was not counted, for the debug dump (DebugDump): filled in
 * by HitClassifier and CollectListener when one is given, never otherwise.
 */
final class ClassificationTrace
{
    public const DISABLED = 'disabled (enabled: false)';
    public const DEVELOPMENT = 'site in development (APP_ENV=dev or APP_DEBUG=1): nothing is collected';
    public const NOT_GET = 'not a GET request';
    public const EXCLUDED = 'excluded path (exclude_paths)';
    public const NOT_FRONTEND = 'not a page of the site';
    public const PREFETCH = 'prefetch or prerender (Sec-Purpose, Purpose, X-Moz, X-Purpose)';
    public const NOT_DOCUMENT = 'not a page opened in a browser (Sec-Fetch-Dest/-Mode, or Accept without text/html)';
    public const STATUS = 'status neither 2xx, 304 nor 404';
    public const RELOAD = 'reload (count_reloads: false)';
    public const OPT_OUT_SKIP = 'GPC or DNT (opt_out_mode: skip)';
    public const ALIAS_NOT_VISITOR = 'alias redirect of a bot or a prefetch';

    /** Null while nothing stopped it. */
    public ?string $skipped = null;

    /** The visitor sent an opt-out signal that is honoured (GPC, DNT). */
    public bool $optOut = false;

    public function skip(string $reason): void
    {
        $this->skipped ??= $reason;
    }
}
