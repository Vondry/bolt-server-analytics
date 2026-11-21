<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Controller;

use Bolt\Configuration\Config;
use Bolt\Controller\Backend\BackendZoneInterface;
use Bolt\Extension\ExtensionController;
use Doctrine\DBAL\Exception\TableNotFoundException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;
use TomVondracek\RequestAnalytics\Aggregation\RollupResult;
use TomVondracek\RequestAnalytics\Aggregation\RollupService;
use TomVondracek\RequestAnalytics\Aggregation\StatisticsEraser;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Extension;
use TomVondracek\RequestAnalytics\Query\BreakdownQuery;
use TomVondracek\RequestAnalytics\Query\TrendQuery;
use TomVondracek\RequestAnalytics\Report\ContentGapsReport;
use TomVondracek\RequestAnalytics\Report\PageFlowReport;
use TomVondracek\RequestAnalytics\Report\PagesReport;
use TomVondracek\RequestAnalytics\Report\SourcesReport;
use TomVondracek\RequestAnalytics\Report\SummaryReport;
use TomVondracek\RequestAnalytics\Report\TrafficReport;
use TomVondracek\RequestAnalytics\Security\AnalyticsVoter;
use TomVondracek\RequestAnalytics\Security\ResponseHeaders;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;

/**
 * JSON for the dashboard widget and the analytics page. The paths are below Bolt's backend URL, so its
 * access control keeps anonymous users out; the `dashboard` permission is the
 * one the dashboard itself asks for.
 *
 * Under `/extension/…` (singular): core's `/extensions/{name}` route would
 * swallow anything below `/extensions/`.
 *
 * Not an API for anything else: only the scripts of the Analytics page and of
 * the dashboard card get answers.
 *
 * - who: the roles of `access.view` (deleting: also `access.delete`), with a
 *   login, as for the rest of Bolt's backend (AnalyticsVoter)
 * - how: with the session's token in a header (CSRF_HEADER), which only the
 *   page has; a request without it (a script, a link, an `<img>`) is refused
 * - from where: the site itself; a browser request of another site
 *   (`Sec-Fetch-Site`, `Origin`) is refused, whatever it sends along
 * - every answer with ResponseHeaders: not stored, framed, sniffed or embedded
 *
 * Errors meant for the administrator are translated, into the user's backend
 * language (BackendZoneInterface makes Bolt apply it); those that name a request
 * parameter or a config key stay in English, like the keys themselves.
 */
#[Route('%bolt.backend_url%/extension/request-analytics/api', name: 'request_analytics_api_')]
final class ApiController extends ExtensionController implements BackendZoneInterface
{
    public const ROUTE_TREND = 'request_analytics_api_trend';
    public const ROUTE_ROLLUP = 'request_analytics_api_rollup';
    public const ROUTE_BREAKDOWN = 'request_analytics_api_breakdown';
    public const ROUTE_PAGES = 'request_analytics_api_pages';
    public const ROUTE_SOURCES = 'request_analytics_api_sources';
    public const ROUTE_CAMPAIGNS = 'request_analytics_api_campaigns';
    public const ROUTE_ALIASES = 'request_analytics_api_aliases';
    public const ROUTE_SUMMARY = 'request_analytics_api_summary';
    public const ROUTE_BROKEN_LINKS = 'request_analytics_api_broken_links';
    public const ROUTE_SEARCHES = 'request_analytics_api_searches';
    public const ROUTE_PAGE_FLOW = 'request_analytics_api_page_flow';
    public const ROUTE_BOTS = 'request_analytics_api_bots';
    public const ROUTE_HOURS = 'request_analytics_api_hours';
    public const ROUTE_ERASE_PREVIEW = 'request_analytics_api_erase_preview';
    public const ROUTE_ERASE = 'request_analytics_api_erase';

    /**
     * The token of the session that every request of the page's scripts
     * sends (CSRF_HEADER); deleting has one of its own.
     */
    public const CSRF_TOKEN_ID = 'request_analytics_api';

    public const ERASE_CSRF_TOKEN_ID = 'request_analytics_erase';
    public const CSRF_HEADER = 'X-CSRF-Token';
    private const SETUP_COMMANDS = '`bin/console request-analytics:install`';

    public function __construct(
        Config $config,
        private readonly ConfigLoader $configLoader,
        private readonly TrendQuery $trendQuery,
        private readonly BreakdownQuery $breakdownQuery,
        private readonly PagesReport $pagesReport,
        private readonly SourcesReport $sourcesReport,
        private readonly SummaryReport $summaryReport,
        private readonly ContentGapsReport $contentGapsReport,
        private readonly PageFlowReport $pageFlowReport,
        private readonly TrafficReport $trafficReport,
        private readonly RollupService $rollupService,
        private readonly AnalyticsDatabase $database,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
        private readonly StatisticsEraser $eraser,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
        parent::__construct($config);
    }

    #[Route('/trend', name: 'trend', methods: [Request::METHOD_GET])]
    #[IsGranted(AnalyticsVoter::VIEW)]
    public function trend(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): array {
            $config = $this->configLoader->load();

            $trend = $this->trendQuery->trend(
                self::query($request, 'range') ?? $config->widgetDefaultRange,
                self::query($request, 'metric') ?? $config->widgetMetric,
                self::query($request, 'compare') ?? $config->widgetCompare,
                self::query($request, 'interval'),
                self::query($request, 'from'),
                self::query($request, 'to'),
            );

            return $trend + [
                'aggregatedUntil' => $this->rollupService->aggregatedUntil()?->format(DATE_ATOM),
                'pending' => $this->rollupService->pending(),
            ];
        });
    }

    /**
     * Devices, browsers and operating systems of a range of days, up to today:
     * the range the trend chart of the analytics page shows, with the counts of
     * the days it compares with (`compareFrom`/`compareTo`, `compare=none`).
     */
    #[Route('/breakdown', name: 'breakdown', methods: [Request::METHOD_GET])]
    #[IsGranted(AnalyticsVoter::VIEW)]
    public function breakdown(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): array {
            $config = $this->configLoader->load();
            $from = self::query($request, 'from');
            $to = self::query($request, 'to');
            if ($from === null || $to === null) {
                throw new InvalidArgumentException('"from" and "to" are required.');
            }

            [$range, $previous] = $this->summaryReport->ranges($from, $to, ...self::comparison($request));

            return $this->breakdownQuery->breakdown(
                self::query($request, 'metric') ?? $config->widgetMetric,
                $range->from,
                $range->to,
                $config->reportThresholdK,
                $previous,
            );
        });
    }

    /**
     * The pages of a range of days, most or least viewed first, a page of the
     * list at a time; the range the trend chart of the analytics page shows.
     */
    #[Route('/pages', name: 'pages', methods: [Request::METHOD_GET])]
    #[IsGranted(AnalyticsVoter::VIEW)]
    public function pages(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): array {
            $config = $this->configLoader->load();
            $from = self::query($request, 'from');
            $to = self::query($request, 'to');
            if ($from === null || $to === null) {
                throw new InvalidArgumentException('"from" and "to" are required.');
            }

            return $this->pagesReport->report(
                self::query($request, 'metric') ?? $config->widgetMetric,
                $from,
                $to,
                self::query($request, 'order') ?? PagesReport::ORDER_MOST,
                self::page($request),
                self::query($request, 'q') ?? '',
                ...self::comparison($request),
                threshold: $config->reportThresholdK,
            );
        });
    }

    /**
     * The totals of a range of days up to today, and of as many days before.
     */
    #[Route('/summary', name: 'summary', methods: [Request::METHOD_GET])]
    #[IsGranted(AnalyticsVoter::VIEW)]
    public function summary(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): array {
            [$from, $to] = self::range($request);

            return $this->summaryReport->summary($from, $to, ...self::comparison($request));
        });
    }

    /**
     * Missing pages of a range of days, with where the links to them are.
     */
    #[Route('/broken-links', name: 'broken_links', methods: [Request::METHOD_GET])]
    #[IsGranted(AnalyticsVoter::VIEW)]
    public function brokenLinks(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): array {
            [$from, $to] = self::range($request);

            return $this->contentGapsReport->brokenLinks($from, $to, $this->configLoader->load()->reportThresholdK, self::page($request), ...self::comparison($request));
        });
    }

    /**
     * Site-search terms of a range of days.
     */
    #[Route('/searches', name: 'searches', methods: [Request::METHOD_GET])]
    #[IsGranted(AnalyticsVoter::VIEW)]
    public function searches(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): array {
            [$from, $to] = self::range($request);

            return $this->contentGapsReport->searches($from, $to, $this->configLoader->load()->reportThresholdK, self::page($request), ...self::comparison($request));
        });
    }

    /**
     * Where the visitors of one page came from and went next, within the site.
     */
    #[Route('/page-flow', name: 'page_flow', methods: [Request::METHOD_GET])]
    #[IsGranted(AnalyticsVoter::VIEW)]
    public function pageFlow(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): array {
            [$from, $to] = self::range($request);
            $path = self::query($request, 'path');
            if ($path === null) {
                throw new InvalidArgumentException('"path" is required.');
            }

            return $this->pageFlowReport->flow($path, $from, $to, $this->configLoader->load()->reportThresholdK);
        });
    }

    /**
     * Bot requests by what the bots are for.
     */
    #[Route('/bots', name: 'bots', methods: [Request::METHOD_GET])]
    #[IsGranted(AnalyticsVoter::VIEW)]
    public function bots(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): array {
            [$from, $to] = self::range($request);

            return $this->trafficReport->bots($from, $to, ...self::comparison($request));
        });
    }

    /**
     * Page views by weekday and hour of the day.
     */
    #[Route('/hours', name: 'hours', methods: [Request::METHOD_GET])]
    #[IsGranted(AnalyticsVoter::VIEW)]
    public function hours(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): array {
            [$from, $to] = self::range($request);

            return $this->trafficReport->hours($from, $to);
        });
    }

    /**
     * Visits per channel and a page of the sources of a range of days.
     */
    #[Route('/sources', name: 'sources', methods: [Request::METHOD_GET])]
    #[IsGranted(AnalyticsVoter::VIEW)]
    public function sources(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): array {
            [$from, $to] = self::range($request);

            return $this->sourcesReport->sources(
                $from,
                $to,
                $this->configLoader->load()->reportThresholdK,
                self::query($request, 'channel'),
                self::query($request, 'q') ?? '',
                self::page($request),
                ...self::comparison($request),
            );
        });
    }

    /**
     * The campaigns (`utm_campaign`) of a range of days.
     */
    #[Route('/campaigns', name: 'campaigns', methods: [Request::METHOD_GET])]
    #[IsGranted(AnalyticsVoter::VIEW)]
    public function campaigns(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): array {
            [$from, $to] = self::range($request);

            return $this->sourcesReport->campaigns($from, $to, $this->configLoader->load()->reportThresholdK);
        });
    }

    /**
     * The domain aliases and markers (QR codes, …) of a range of days.
     */
    #[Route('/aliases', name: 'aliases', methods: [Request::METHOD_GET])]
    #[IsGranted(AnalyticsVoter::VIEW)]
    public function aliases(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): array {
            [$from, $to] = self::range($request);
            $config = $this->configLoader->load();

            return $this->sourcesReport->aliases($from, $to, $config->reportThresholdK, $config->aliasHosts);
        });
    }

    #[Route('/rollup', name: 'rollup', methods: [Request::METHOD_POST])]
    #[IsGranted(AnalyticsVoter::VIEW)]
    public function rollup(Request $request): JsonResponse
    {
        return $this->respond($request, fn (): array => $this->configLoader->load()->rollupOnAdminVisit()
            ? $this->rollupService->rollup()->toArray()
            // `trigger: cron`: the page never starts a rollup, and a request
            // of its own does not either.
            : (new RollupResult(0, $this->rollupService->pending(), 0))->toArray());
    }

    /**
     * What deleting the last `days` days (or `days=all`) would delete: the
     * first day, and the page views and visits of the period.
     */
    #[Route('/erase/preview', name: 'erase_preview', methods: [Request::METHOD_GET])]
    #[IsGranted(AnalyticsVoter::DELETE)]
    public function erasePreview(Request $request): JsonResponse
    {
        return $this->respond($request, fn (): array => $this->eraser->preview(self::days(self::query($request, 'days'))));
    }

    /**
     * Deletes the statistics of the last `days` days up to now, or all of them
     * (`days=all`), for good. Logged with the user who did it; the page says
     * it with a flash message once it has reloaded.
     */
    #[Route('/erase', name: 'erase', methods: [Request::METHOD_POST])]
    #[IsGranted(AnalyticsVoter::DELETE)]
    public function erase(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): array {
            $result = $this->eraser->erase(self::days(self::text($request->request->all()['days'] ?? null, 'days')));

            $this->logger->notice('request-analytics: {user} deleted {period} ({rows} rows, {files} spool files).', [
                'user' => $this->tokenStorage->getToken()?->getUserIdentifier() ?? 'unknown user',
                'period' => $result['from'] === null ? 'all statistics' : 'the statistics from ' . $result['from'] . ' on',
                'rows' => $result['rows'],
                'files' => $result['spoolFiles'],
            ]);
            self::flash($request, $result['from'] === null
                ? $this->trans('All statistics were deleted.')
                : $this->trans('The statistics of the chosen period were deleted.'));

            return $result;
        }, self::ERASE_CSRF_TOKEN_ID);
    }

    /**
     * @param callable(): array<string, mixed> $data
     */
    private function respond(Request $request, callable $data, string $tokenId = self::CSRF_TOKEN_ID): JsonResponse
    {
        $refused = $this->refuse($request, $tokenId);
        if ($refused instanceof JsonResponse) {
            return $refused;
        }

        try {
            // The tables are created on first use.
            return self::jsonResponse($this->database->withSchema($data));
        } catch (TableNotFoundException) {
            return self::error('setup', $this->trans('The Request Analytics tables are missing. Run %commands%.', [
                '%commands%' => self::SETUP_COMMANDS,
            ]), Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (InvalidArgumentException $exception) {
            // A request parameter or the extension's config; both name the problem.
            return self::error('invalid', $exception->getMessage(), Response::HTTP_BAD_REQUEST);
        } catch (LockConflictedException) {
            return self::error('busy', $this->trans('The statistics are being aggregated right now. Try again in a minute.'), Response::HTTP_CONFLICT);
        } catch (Throwable $exception) {
            $this->logger->error('request-analytics: {message}', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return self::error('failed', $this->trans('Request Analytics failed; see the log for details.'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trans(string $message, array $parameters = []): string
    {
        return $this->translator->trans($message, $parameters, Extension::TRANSLATION_DOMAIN);
    }

    private static function error(string $code, string $message, int $status): JsonResponse
    {
        return self::jsonResponse([
            'error' => $code,
            'message' => $message,
        ], $status);
    }

    /**
     * JSON with `<`, `>`, `&`, quotes escaped (Symfony's default), and invalid
     * UTF-8 replaced instead of failing: a refused value, or a row of an older
     * version or changed by hand, must not break the answer.
     *
     * @param array<string, mixed> $data
     */
    private static function jsonResponse(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse(null, $status);
        $response->setEncodingOptions($response->getEncodingOptions() | JSON_INVALID_UTF8_SUBSTITUTE);
        $response->setData($data);

        return self::noStore($response);
    }

    /**
     * A message Bolt shows after the next page load. Escaped: Bolt prints
     * flash messages as HTML.
     */
    private static function flash(Request $request, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()
                ->add('success', htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE));
        }
    }

    /**
     * `days` of the eraser: a number of days, or `all` (null).
     */
    private static function days(?string $value): ?int
    {
        if ($value === 'all') {
            return null;
        }

        if ($value === null || preg_match('/^[1-9]\d{0,5}$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('"days" must be a number from 1 to %d, or "all".', StatisticsEraser::MAX_DAYS));
        }

        return (int) $value;
    }

    /**
     * Null when the request may be answered; otherwise the refusal.
     */
    private function refuse(Request $request, string $tokenId): ?JsonResponse
    {
        // Browsers say where a request comes from; another site is refused
        // even when the browser sends the cookie along. Behind a proxy that
        // passes on another `Host` than the visitor used, the `Origin` is one
        // of `own_hosts` (see the README), which count as this site too.
        $site = $request->headers->get('Sec-Fetch-Site');
        $origin = $request->headers->get('Origin');
        if (($site !== null && $site !== 'same-origin') || ($origin !== null && ! $this->isOwnOrigin($request, $origin))) {
            return self::error('origin', $this->trans('The CSRF token is not valid. Reload the page.'), Response::HTTP_FORBIDDEN);
        }

        $token = $request->headers->get(self::CSRF_HEADER) ?? $request->request->all()['_csrf_token'] ?? null;
        if (! is_string($token) || ! $this->csrfTokenManager->isTokenValid(new CsrfToken($tokenId, $token))) {
            return self::error('csrf', $this->trans('The CSRF token is not valid. Reload the page.'), Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function isOwnOrigin(Request $request, string $origin): bool
    {
        $host = parse_url($origin, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        $host = mb_strtolower($host);
        if ($host === $request->getHost()) {
            return true;
        }

        try {
            return $this->configLoader->load()->isOwnHost($host);
        } catch (Throwable) {
            return false;
        }
    }

    private static function noStore(JsonResponse $response): JsonResponse
    {
        return ResponseHeaders::apply($response);
    }

    /**
     * @return array{string, string} the required `from` and `to`
     */
    private static function range(Request $request): array
    {
        $from = self::query($request, 'from');
        $to = self::query($request, 'to');
        if ($from === null || $to === null) {
            throw new InvalidArgumentException('"from" and "to" are required.');
        }

        return [$from, $to];
    }

    /**
     * What the trend chart compares with: `compareFrom` and `compareTo`, or
     * `compare=none`; neither = as many days right before.
     *
     * @return array{compareFrom: ?string, compareTo: ?string, compare: bool}
     */
    private static function comparison(Request $request): array
    {
        return [
            'compareFrom' => self::query($request, 'compareFrom'),
            'compareTo' => self::query($request, 'compareTo'),
            'compare' => self::query($request, 'compare') !== 'none',
        ];
    }

    private static function page(Request $request): int
    {
        $page = self::query($request, 'page') ?? '1';
        if (preg_match('/^[1-9]\d{0,5}$/', $page) !== 1) {
            throw new InvalidArgumentException(sprintf('"page" must be a number from 1, "%s" given.', $page));
        }

        return (int) $page;
    }

    /**
     * A non-empty query parameter; null for a missing, empty or array value.
     */
    private static function query(Request $request, string $name): ?string
    {
        return self::text($request->query->all()[$name] ?? null, $name);
    }

    /**
     * A parameter as text; null for a missing, empty or array value. Invalid
     * UTF-8 and control characters (NUL, line breaks, ...) are refused: no
     * parameter of the page holds them, and they would reach date parsers,
     * queries and the JSON of the answer.
     *
     * @throws InvalidArgumentException
     */
    private static function text(mixed $value, string $name): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('"%s" must be text without control characters.', $name));
        }

        return $value;
    }
}
