<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Aggregation;

use Closure;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;
use TomVondracek\RequestAnalytics\Collector\CollectListener;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Diagnostics\FailureReporter;
use TomVondracek\RequestAnalytics\Security\ResponseHeaders;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;

/**
 * The rollup URL (`aggregation.rollup_url`, off by default), for a hosting
 * whose cron can only call a URL (WEDOS), or a cron service on the web:
 * `https://example.com/_request-analytics/rollup?token=<aggregation.rollup_token>`.
 *
 * Answered before routing, so the path is whatever the config says. Only a
 * request with the right token (`?token=`, or an `Authorization: Bearer`
 * header; compared in constant time) is answered here. Any other goes on as
 * if the URL were not there: the site's own 404 page, or its page of that
 * path. So nobody without the token can tell the URL exists, and a path that
 * is also a page of the site keeps working. A known token lets its holder
 * start rollups and read how many rows are pending, nothing more; one rollup
 * runs at a time. Off with `enabled: false`.
 *
 * An answered call is never counted as a visit, and starts no second rollup
 * on `kernel.terminate` (VisitRollupListener).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: self::PRIORITY)]
final readonly class RollupUrlListener
{
    /**
     * Before routing (32) and the firewall (8), after the alias redirect.
     */
    public const PRIORITY = 255;

    /**
     * The longest a rollup of the URL runs: below the time limit of PHP on
     * most hostings (30 s), and shorter still under a lower limit.
     */
    private const BUDGET_MS = 20_000;

    /**
     * @param Closure(): RollupService $rollupService
     * @param Closure(): AnalyticsDatabase $database
     */
    public function __construct(
        private ConfigLoader $configLoader,
        private FailureReporter $failures,
        #[AutowireServiceClosure(RollupService::class)]
        private Closure $rollupService,
        #[AutowireServiceClosure(AnalyticsDatabase::class)]
        private Closure $database,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (! $event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        try {
            $config = $this->configLoader->load();
        } catch (Throwable) {
            // A broken config is reported where it is loaded next; no URL then.
            return;
        }

        if (! $config->enabled || $config->rollupUrl === null || mb_rtrim($request->getPathInfo(), '/') !== $config->rollupUrl
            || ! self::isAuthorized($request, $config->rollupToken)
        ) {
            return;
        }

        $request->attributes->set(CollectListener::NOT_COUNTED_ATTRIBUTE, true);
        $event->setResponse($this->answer($request));
    }

    private function answer(Request $request): JsonResponse
    {
        if (! $request->isMethod(Request::METHOD_GET) && ! $request->isMethod(Request::METHOD_POST)) {
            return self::json([
                'error' => 'method',
            ], Response::HTTP_METHOD_NOT_ALLOWED);
        }

        try {
            $rollupService = ($this->rollupService)();
            $result = ($this->database)()->withSchema(static fn (): RollupResult => $rollupService->rollup(self::budgetMs()));

            return self::json($result->toArray());
        } catch (Throwable $exception) {
            $this->failures->report('rollup URL', $exception);

            return self::json([
                'error' => 'failed',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private static function isAuthorized(Request $request, ?string $token): bool
    {
        $given = $request->query->all()['token'] ?? null;
        $authorization = (string) $request->headers->get('Authorization');
        if (str_starts_with($authorization, 'Bearer ')) {
            $given = mb_trim(mb_substr($authorization, 7));
        }

        // No token (its variable not set): nobody gets in.
        return $token !== null && is_string($given) && hash_equals($token, $given);
    }

    /**
     * BUDGET_MS, or less where PHP stops a script sooner.
     */
    private static function budgetMs(): int
    {
        $limit = (int) ini_get('max_execution_time');

        return $limit > 0 ? max(1_000, min(self::BUDGET_MS, ($limit - 5) * 1_000)) : self::BUDGET_MS;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = ResponseHeaders::apply(new JsonResponse($data, $status));
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
