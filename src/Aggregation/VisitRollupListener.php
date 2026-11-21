<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Aggregation;

use Closure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;
use TomVondracek\RequestAnalytics\Collector\CollectListener;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Diagnostics\FailureReporter;
use TomVondracek\RequestAnalytics\Diagnostics\Throttle;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;

/**
 * The rollup without cron: a request starts one, at most every
 * `aggregation.visit_interval_minutes` (an hour), once its response has been
 * sent, like WordPress's WP-Cron. On a hosting without cron, or one whose
 * cron can only call a URL, the statistics are kept up to date without
 * anybody opening the dashboard, and visits never wait long in the spool.
 *
 * Only where the response is out before `kernel.terminate` (PHP-FPM,
 * LiteSpeed, FrankenPHP): under Apache's mod_php or CGI the visitor would
 * wait for it. Never with `trigger: cron`, nor while the site is developed.
 *
 * A request that is not due costs one look at the throttle (APCu, or the
 * mtime of a file). When the throttle cannot keep its time (no APCu, and
 * `var/` not writable), no rollup starts: never one on every request. The
 * rollup service is only built when one is due, so a broken database or
 * config costs nothing either; a failure is logged (throttled) and
 * forgotten. Several requests at once: one rollup runs, the others find its
 * lock taken and stop. A call of the rollup URL has run one already.
 */
#[AsEventListener(event: KernelEvents::TERMINATE, priority: self::PRIORITY)]
final readonly class VisitRollupListener
{
    /**
     * After the collector has written the request's own line.
     */
    public const PRIORITY = CollectListener::PRIORITY - 1;

    private const THROTTLE_KEY = 'visit-rollup';

    /**
     * @param Closure(): RollupService $rollupService
     * @param Closure(): AnalyticsDatabase $database
     * @param bool|null $responseSent whether the response is out before `kernel.terminate`; null = as the SAPI says
     */
    public function __construct(
        private ConfigLoader $configLoader,
        private Throttle $throttle,
        private FailureReporter $failures,
        #[AutowireServiceClosure(RollupService::class)]
        private Closure $rollupService,
        #[AutowireServiceClosure(AnalyticsDatabase::class)]
        private Closure $database,
        #[Autowire(param: 'kernel.environment')]
        private string $environment = 'prod',
        #[Autowire(param: 'kernel.debug')]
        private bool $debug = false,
        private ?bool $responseSent = null,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        if ($this->environment === 'dev' || $this->debug || ! $event->isMainRequest() || ! ($this->responseSent ?? self::responseIsSent())
            || $event->getRequest()->attributes->get(CollectListener::NOT_COUNTED_ATTRIBUTE) === true
        ) {
            return;
        }

        try {
            $config = $this->configLoader->load();
            if (! $config->rollupOnVisit() || ! $this->throttle->allow(self::THROTTLE_KEY, $config->visitIntervalMinutes * 60, failOpen: false)) {
                return;
            }

            $rollupService = ($this->rollupService)();
            ($this->database)()->withSchema(static fn (): RollupResult => $rollupService->rollup());
        } catch (Throwable $exception) {
            $this->failures->report('rollup on a visit', $exception);
        }
    }

    /**
     * Symfony's Response::send() closes the connection before
     * `kernel.terminate` with one of these, and only these (FrankenPHP has
     * `fastcgi_finish_request()` too).
     */
    private static function responseIsSent(): bool
    {
        return PHP_SAPI !== 'cli' && (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'));
    }
}
