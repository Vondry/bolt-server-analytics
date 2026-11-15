<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Widget;

use Bolt\Widget\BaseWidget;
use Bolt\Widget\Injector\AdditionalTarget;
use Bolt\Widget\Injector\RequestZone;
use Bolt\Widget\TwigAwareInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;
use TomVondracek\RequestAnalytics\Config\RequestAnalyticsConfig;
use TomVondracek\RequestAnalytics\Controller\AnalyticsController;
use TomVondracek\RequestAnalytics\Controller\ApiController;
use TomVondracek\RequestAnalytics\Twig\CspNonce;

/**
 * The page-visit summary on the Bolt dashboard: the last 30 days with their
 * change, a small chart and a link to the analytics page. Renders only the
 * card; the numbers come from the API, so opening the dashboard never waits
 * for them.
 *
 * At the top of the right column by default: the lowest priority comes first.
 */
final class TrendWidget extends BaseWidget implements TwigAwareInterface
{
    protected $name = 'Request Analytics: Trend';
    protected $target = AdditionalTarget::WIDGET_BACK_DASHBOARD_ASIDE_TOP;
    protected $zone = RequestZone::BACKEND;
    protected $priority = -1000;
    protected $template = '@request-analytics/widget/trend.html.twig';
    protected $cacheDuration = 0;

    /**
     * @param RequestAnalyticsConfig|null $config null when the config could not be loaded
     * @param string|null $configError what is wrong with the config, shown instead of the chart
     */
    public function __construct(
        private readonly ?RequestAnalyticsConfig $config = null,
        private readonly ?UrlGeneratorInterface $urlGenerator = null,
        private readonly ?string $configError = null,
    ) {
        if ($config instanceof RequestAnalyticsConfig) {
            $this->target = $config->widgetTarget;
        }
    }

    /**
     * @param array<array-key, mixed> $params
     */
    protected function run(array $params = []): ?string
    {
        return parent::run($params + [
            'trend' => $this->settings(),
            'error' => $this->configError,
            'warnings' => $this->config instanceof RequestAnalyticsConfig ? $this->config->warnings : [],
            'nonce' => $this->nonces(),
        ]);
    }

    /**
     * @return array{script: ?string, style: ?string}
     */
    private function nonces(): array
    {
        try {
            return CspNonce::all($this->getTwig());
        } catch (Throwable) {
            return [
                'script' => null,
                'style' => null,
            ];
        }
    }

    /**
     * What the script of the card needs; null when the card can't work yet
     * (the routes are only there after `extensions:configure`).
     *
     * @return array{trendUrl: string, rollupUrl: string, pageUrl: string, csrfHeader: string, metric: string, rollup: bool}|null
     */
    private function settings(): ?array
    {
        if (! $this->config instanceof RequestAnalyticsConfig || ! $this->urlGenerator instanceof UrlGeneratorInterface) {
            return null;
        }

        try {
            return [
                'trendUrl' => $this->urlGenerator->generate(ApiController::ROUTE_TREND),
                'rollupUrl' => $this->urlGenerator->generate(ApiController::ROUTE_ROLLUP),
                'pageUrl' => $this->urlGenerator->generate(AnalyticsController::ROUTE),
                'csrfHeader' => ApiController::CSRF_HEADER,
                'metric' => $this->config->widgetMetric,
                'rollup' => $this->config->rollupOnAdminVisit(),
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
