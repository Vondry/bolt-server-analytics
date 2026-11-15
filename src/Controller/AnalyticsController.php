<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Controller;

use Bolt\Configuration\Config;
use Bolt\Controller\Backend\BackendZoneInterface;
use Bolt\Extension\ExtensionController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;
use TomVondracek\RequestAnalytics\Aggregation\StatisticsEraser;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Config\RequestAnalyticsConfig;
use TomVondracek\RequestAnalytics\Insight\InsightFormatter;
use TomVondracek\RequestAnalytics\Insight\InsightsReport;
use TomVondracek\RequestAnalytics\Insight\InsightsResult;
use TomVondracek\RequestAnalytics\Report\PageCatalogInterface;
use TomVondracek\RequestAnalytics\Security\AnalyticsVoter;
use TomVondracek\RequestAnalytics\Security\ResponseHeaders;
use TomVondracek\RequestAnalytics\SiteTime;
use TomVondracek\RequestAnalytics\Twig\CspNonce;
use Twig\Environment;

/**
 * The analytics page of the backend, linked from the menu and the dashboard
 * card: the full trend chart and the shares of devices, browsers and operating
 * systems. Renders only the page; the numbers come from the API.
 *
 * Only for the roles of `access.view` (AnalyticsVoter). For those of
 * `access.delete` it also has the button that deletes statistics; for
 * everybody else it is not there.
 *
 * Except the insights: computed here, while the page renders, and written
 * into it. They have no route of their own and are never fetched.
 *
 * A backend controller for Bolt (BackendZoneInterface): its zone gives the
 * request the user's backend language, and keeps the collector away.
 */
final class AnalyticsController extends ExtensionController implements BackendZoneInterface
{
    public const ROUTE = 'request_analytics_page';
    public const TEMPLATE = '@request-analytics/page/analytics.html.twig';

    public function __construct(
        Config $config,
        private readonly ConfigLoader $configLoader,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
        private readonly PageCatalogInterface $pageCatalog,
        private readonly InsightsReport $insightsReport,
        private readonly InsightFormatter $insightFormatter,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly SiteTime $siteTime,
    ) {
        parent::__construct($config);
    }

    #[Route('%bolt.backend_url%/extension/request-analytics', name: self::ROUTE, methods: [Request::METHOD_GET])]
    #[IsGranted(AnalyticsVoter::VIEW)]
    public function page(?Request $request = null): Response
    {
        $config = null;
        $error = null;

        try {
            $config = $this->configLoader->load();
        } catch (Throwable $exception) {
            // An administrator should see what is wrong with the config.
            $error = $exception->getMessage();
        }

        $insights = $config instanceof RequestAnalyticsConfig ? $this->insightsReport->result($request?->getHost() ?? '') : null;

        $response = new Response($this->twig->render(self::TEMPLATE, [
            'trend' => $config instanceof RequestAnalyticsConfig ? $this->settings($config) : null,
            'error' => $error,
            'warnings' => $config instanceof RequestAnalyticsConfig ? $config->warnings : [],
            'threshold' => $config instanceof RequestAnalyticsConfig ? $config->reportThresholdK : null,
            // The languages a page can be viewed in, for their flags.
            'locales' => $this->pageCatalog->locales(),
            'defaultLocale' => $this->pageCatalog->defaultLocale(),
            // For the example links of the help: the address of this site.
            'siteUrl' => $request instanceof Request ? $request->getSchemeAndHttpHost() : '',
            'searchParams' => $config instanceof RequestAnalyticsConfig ? $config->searchParams : [],
            'aliases' => $config instanceof RequestAnalyticsConfig ? [
                'hosts' => $config->aliasHosts,
                'param' => $config->aliasParam,
            ] : [
                'hosts' => [],
                'param' => 'ra_via',
            ],
            'insights' => $insights instanceof InsightsResult ? $this->insightFormatter->format($insights->insights) : [],
            // When none fires: wait for statistics, or all is fine.
            'insightsStatus' => $this->insightFormatter->status($insights?->status),
            'erase' => $config instanceof RequestAnalyticsConfig ? $this->eraseSettings() : null,
            'nonce' => CspNonce::all($this->twig),
        ]));
        return ResponseHeaders::apply($response);
    }

    /**
     * What the dialog that deletes statistics needs; null for a user who may
     * not delete them (the API refuses them too).
     *
     * @return array{previewUrl: string, eraseUrl: string, csrfHeader: string, today: string, maxDays: int}|null
     */
    private function eraseSettings(): ?array
    {
        if (! $this->authorizationChecker->isGranted(AnalyticsVoter::DELETE)) {
            return null;
        }

        return [
            'previewUrl' => $this->urlGenerator->generate(ApiController::ROUTE_ERASE_PREVIEW),
            'eraseUrl' => $this->urlGenerator->generate(ApiController::ROUTE_ERASE),
            'csrfHeader' => ApiController::CSRF_HEADER,
            // The days of the choices, as the server counts them.
            'today' => $this->siteTime->now()
                ->format('Y-m-d'),
            'maxDays' => StatisticsEraser::MAX_DAYS,
        ];
    }

    /**
     * What the scripts of the page need.
     *
     * @return array{trendUrl: string, rollupUrl: string, summaryUrl: string, breakdownUrl: string, pagesUrl: string, sourcesUrl: string, campaignsUrl: string, aliasesUrl: string, brokenLinksUrl: string, searchesUrl: string, pageFlowUrl: string, botsUrl: string, hoursUrl: string, csrfHeader: string, range: string, compare: string, metric: string, rollup: bool}
     */
    private function settings(RequestAnalyticsConfig $config): array
    {
        return [
            'trendUrl' => $this->urlGenerator->generate(ApiController::ROUTE_TREND),
            'rollupUrl' => $this->urlGenerator->generate(ApiController::ROUTE_ROLLUP),
            'summaryUrl' => $this->urlGenerator->generate(ApiController::ROUTE_SUMMARY),
            'breakdownUrl' => $this->urlGenerator->generate(ApiController::ROUTE_BREAKDOWN),
            'pagesUrl' => $this->urlGenerator->generate(ApiController::ROUTE_PAGES),
            'sourcesUrl' => $this->urlGenerator->generate(ApiController::ROUTE_SOURCES),
            'campaignsUrl' => $this->urlGenerator->generate(ApiController::ROUTE_CAMPAIGNS),
            'aliasesUrl' => $this->urlGenerator->generate(ApiController::ROUTE_ALIASES),
            'brokenLinksUrl' => $this->urlGenerator->generate(ApiController::ROUTE_BROKEN_LINKS),
            'searchesUrl' => $this->urlGenerator->generate(ApiController::ROUTE_SEARCHES),
            'pageFlowUrl' => $this->urlGenerator->generate(ApiController::ROUTE_PAGE_FLOW),
            'botsUrl' => $this->urlGenerator->generate(ApiController::ROUTE_BOTS),
            'hoursUrl' => $this->urlGenerator->generate(ApiController::ROUTE_HOURS),
            'csrfHeader' => ApiController::CSRF_HEADER,
            'range' => $config->widgetDefaultRange,
            'compare' => $config->widgetCompare,
            'metric' => $config->widgetMetric,
            'rollup' => $config->rollupOnAdminVisit(),
        ];
    }
}
