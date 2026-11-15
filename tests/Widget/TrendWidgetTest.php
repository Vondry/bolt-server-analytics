<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Widget;

use Bolt\Widget\Injector\RequestZone;
use Closure;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use TomVondracek\RequestAnalytics\Config\RequestAnalyticsConfig;
use TomVondracek\RequestAnalytics\Controller\AnalyticsController;
use TomVondracek\RequestAnalytics\Controller\ApiController;
use TomVondracek\RequestAnalytics\Extension;
use TomVondracek\RequestAnalytics\Tests\Support\Translations;
use TomVondracek\RequestAnalytics\Widget\TrendWidget;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Renders the shipped template, the way Bolt does on the dashboard.
 */
final class TrendWidgetTest extends TestCase
{
    public function testIsADashboardWidgetOfTheBackend(): void
    {
        $widget = new TrendWidget();

        self::assertSame('Request Analytics: Trend', $widget->getName());
        self::assertSame(['dashboard_aside_top'], $widget->getTargets(), 'the right column');
        self::assertSame(-1000, $widget->getPriority(), 'Bolt renders the lowest priority first');
        self::assertSame(RequestZone::BACKEND, $widget->getZone());
        self::assertSame('@request-analytics/widget/trend.html.twig', $widget->getTemplate());
        self::assertSame(0, $widget->getCacheDuration(), 'the CSRF token must not be cached');
    }

    public function testTargetComesFromTheConfig(): void
    {
        $widget = new TrendWidget(self::config([
            'widget' => [
                'target' => 'dashboard_below_header',
            ],
        ]));

        self::assertSame(['dashboard_below_header'], $widget->getTargets());
    }

    public function testRendersTheCardWithWhatItsScriptNeeds(): void
    {
        $html = $this->render(new TrendWidget(self::config([
            'widget' => [
                'default_range' => 'ytd',
                'compare' => 'year',
                'metric' => 'entries',
            ],
        ]), self::urlGenerator()));

        self::assertStringStartsWith('<div class="widget" id="widget-request-analytics-trend"', $html);
        self::assertSame(1, preg_match('/data-config="([^"]+)"/', $html, $matches));
        self::assertSame([
            'trendUrl' => '/bolt/extension/request-analytics/api/trend',
            'rollupUrl' => '/bolt/extension/request-analytics/api/rollup',
            'pageUrl' => '/bolt/extension/request-analytics',
            'csrfHeader' => 'X-CSRF-Token',
            'metric' => 'entries',
            'rollup' => true,
        ], json_decode(html_entity_decode($matches[1]), true));
        self::assertStringContainsString('<span id="ra-api" hidden data-header="X-CSRF-Token" data-token="token-for-request_analytics_api"></span>', $html, 'the token every request of its script sends');
        self::assertStringContainsString('<script>', $html);
        self::assertStringContainsString('title="Arrivals from outside the site (search, links, typed address). Moving between its pages is not counted again.">Visits</span>', $html, 'the configured metric is named, with what it counts');
    }

    /**
     * The dashboard gets the summary; the full chart is on the analytics page.
     */
    public function testIsASummaryThatLinksToTheAnalyticsPage(): void
    {
        $html = $this->render(new TrendWidget(self::config(), self::urlGenerator()));

        self::assertStringContainsString('<a href="/bolt/extension/request-analytics" class="ra-summary__link">Complete analytics', $html);
        self::assertStringContainsString('Page views · Last 30 days', (string) preg_replace('/\s+/', ' ', strip_tags($html)));
        self::assertStringContainsString('title="Every page opened, including moves from page to page within the site.">Page views</span>', $html);
        self::assertStringNotContainsString('data-ra-preset', $html, 'no period buttons');
        self::assertStringNotContainsString('data-ra-compare=', $html, 'no comparison select');
    }

    public function testCronTriggerDoesNotStartARollupFromTheDashboard(): void
    {
        $html = $this->render(new TrendWidget(self::config([
            'aggregation' => [
                'trigger' => 'cron',
            ],
        ]), self::urlGenerator()));

        self::assertStringContainsString('&quot;rollup&quot;:false', $html);
    }

    /**
     * Right after `composer require` the routes are not there yet; the
     * dashboard must still open.
     */
    public function testWithoutRoutesItTellsHowToSetUp(): void
    {
        $html = $this->render(new TrendWidget(self::config(), new UrlGenerator(new RouteCollection(), new RequestContext())));

        self::assertStringContainsString('Request Analytics is not set up yet.', $html);
        self::assertStringContainsString('bin/console extensions:configure', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('data-config', $html);
    }

    public function testConfigErrorIsShownInsteadOfTheChart(): void
    {
        $html = $this->render(new TrendWidget(configError: 'The Request Analytics `enabled` must be true or false, <b>maybe</b> given.'));

        self::assertStringContainsString('must be true or false, &lt;b&gt;maybe&lt;/b&gt; given.', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    /**
     * A typo in the config is ignored, and named on the card.
     */
    public function testConfigWarningsAreShownAboveTheChart(): void
    {
        $html = $this->render(new TrendWidget(self::config([
            'own_host' => ['example.com'],
        ]), self::urlGenerator()));

        self::assertStringContainsString('Unknown Request Analytics config key(s) &quot;own_host&quot; are ignored', $html);
        self::assertStringContainsString('data-config', $html, 'the chart works anyway');
    }

    /**
     * With a Content-Security-Policy (NelmioSecurityBundle's `csp_nonce()`), the
     * inline style and script carry its nonces.
     */
    public function testInlineCodeGetsTheNonceOfAContentSecurityPolicy(): void
    {
        $widget = new TrendWidget(self::config(), self::urlGenerator());

        $html = $this->render($widget, static fn (string $directive): string => 'nonce-' . $directive);

        self::assertStringContainsString('<style nonce="nonce-style">', $html);
        self::assertStringContainsString('<script nonce="nonce-script">', $html);
    }

    /**
     * The card and the texts of its script come in the backend's language.
     */
    public function testCardIsTranslated(): void
    {
        $html = $this->render(new TrendWidget(self::config(), self::urlGenerator()), locale: 'cs');

        self::assertStringContainsString('&nbsp; Návštěvnost stránek', $html);
        self::assertStringContainsString('Kompletní analytika', $html);
        self::assertStringContainsString('Posledních 30 dní', $html);
        self::assertSame(1, preg_match('/data-i18n="([^"]+)"/', $html, $matches));
        self::assertSame([
            'chart' => 'Návštěvnost stránek v čase',
            'today' => 'Dnes: %count%',
            'soFar' => '(zatím)',
            'new' => 'nové',
            'failed' => 'Statistiky se nepodařilo načíst.',
        ], json_decode(html_entity_decode($matches[1]), true), 'placeholders are filled in by the script');
    }

    public function testSetupHintIsTranslated(): void
    {
        $html = $this->render(new TrendWidget(self::config(), new UrlGenerator(new RouteCollection(), new RequestContext())), locale: 'de');

        self::assertStringContainsString('Request Analytics ist noch nicht eingerichtet. Führen Sie aus:', $html);
    }

    /**
     * @param (Closure(string): string)|null $cspNonce the `csp_nonce()` Twig function of a project that has one
     */
    private function render(TrendWidget $widget, ?Closure $cspNonce = null, string $locale = 'en'): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 2) . '/templates', Extension::TWIG_NAMESPACE);
        $twig = new Environment($loader, [
            'strict_variables' => true,
            'autoescape' => 'html',
        ]);
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => 'token-for-' . $id));
        $twig->addExtension(new TranslationExtension(Translations::translator($locale)));
        if ($cspNonce !== null) {
            $twig->addFunction(new TwigFunction('csp_nonce', $cspNonce));
        }

        $widget->setTwig($twig);
        $widget->injectExtension(new Extension());

        return (string) $widget();
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function config(array $config = []): RequestAnalyticsConfig
    {
        return RequestAnalyticsConfig::fromArray($config, '/project');
    }

    private static function urlGenerator(): UrlGenerator
    {
        $routes = new RouteCollection();
        $routes->add(ApiController::ROUTE_TREND, new Route('/bolt/extension/request-analytics/api/trend'));
        $routes->add(ApiController::ROUTE_ROLLUP, new Route('/bolt/extension/request-analytics/api/rollup'));
        $routes->add(AnalyticsController::ROUTE, new Route('/bolt/extension/request-analytics'));

        return new UrlGenerator($routes, new RequestContext());
    }
}
