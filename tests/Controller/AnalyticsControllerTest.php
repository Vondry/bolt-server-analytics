<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Controller;

use Bolt\Event\Subscriber\ZoneSubscriber;
use Bolt\Widget\Injector\RequestZone;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Bridge\Twig\AppVariable;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route as RoutingRoute;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use TomVondracek\RequestAnalytics\Controller\AnalyticsController;
use TomVondracek\RequestAnalytics\Controller\ApiController;
use TomVondracek\RequestAnalytics\Extension;
use TomVondracek\RequestAnalytics\Insight\Insight;
use TomVondracek\RequestAnalytics\Insight\InsightContext;
use TomVondracek\RequestAnalytics\Insight\InsightFormatter;
use TomVondracek\RequestAnalytics\Insight\InsightRule;
use TomVondracek\RequestAnalytics\Insight\InsightsReport;
use TomVondracek\RequestAnalytics\Insight\Message;
use TomVondracek\RequestAnalytics\Insight\Param;
use TomVondracek\RequestAnalytics\Security\AnalyticsVoter;
use TomVondracek\RequestAnalytics\Tests\Support\FakePageCatalog;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryInsightData;
use TomVondracek\RequestAnalytics\Tests\Support\Payloads;
use TomVondracek\RequestAnalytics\Tests\Support\Project;
use TomVondracek\RequestAnalytics\Tests\Support\Translations;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Renders the shipped page template inside a stand-in for Bolt's backend layout.
 */
final class AnalyticsControllerTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testRouteIsBelowTheBackendUrlAndNeedsTheViewPermission(): void
    {
        $method = new ReflectionMethod(AnalyticsController::class, 'page');
        $route = $method->getAttributes(Route::class)[0]->newInstance();

        self::assertSame(['%bolt.backend_url%/extension/request-analytics', AnalyticsController::ROUTE, [Request::METHOD_GET]], [$route->path, $route->name, $route->methods]);
        self::assertSame(AnalyticsVoter::VIEW, $method->getAttributes(IsGranted::class)[0]->newInstance()->attribute);
    }

    /**
     * Bolt gives a request the user's backend language (and keeps the
     * collector away) only when its controller is in the backend zone.
     */
    public function testIsInBoltsBackendZone(): void
    {
        $request = Request::create('/bolt/extension/request-analytics');
        $request->attributes->set('_controller', AnalyticsController::class . '::page');

        self::assertSame(RequestZone::BACKEND, (new ZoneSubscriber())->setZone($request));
    }

    /**
     * Every card starts as placeholders of what it will show and tells screen
     * readers it is loading; the script replaces both with the data.
     */
    public function testCardsShowPlaceholdersWhileTheyLoad(): void
    {
        $html = (string) $this->controller()->page()->getContent();

        foreach (['ra-summary-tiles', 'ra-trend', 'ra-breakdown', 'ra-hours', 'ra-pages', 'ra-sources', 'ra-bots'] as $id) {
            self::assertMatchesRegularExpression('~<div [^>]*aria-busy="true"[^>]* id="' . $id . '"~', $html, $id . ' is busy');
        }

        self::assertSame(2, mb_substr_count($html, 'data-ra-gap="broken" aria-busy="true"') + mb_substr_count($html, 'data-ra-gap="search" aria-busy="true"'));
        self::assertMatchesRegularExpression('~<div class="ra-trend__chart" data-ra-chart>\s*<span class="ra-skeleton-chart" aria-hidden="true">~', $html, 'the chart is drawn over its placeholder');
        self::assertSame(1, mb_substr_count($html, 'window.raLoading = '), 'one loading helper');
        self::assertGreaterThan(30, mb_substr_count($html, 'class="ra-skeleton '));
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $html);
    }

    public function testPageHasTheFullChartAndThePieCharts(): void
    {
        $this->project->configure([
            'widget' => [
                'default_range' => 'ytd',
            ],
            'privacy' => [
                'report_threshold_k' => 7,
            ],
        ]);

        $response = $this->controller()->page();
        $html = (string) $response->getContent();

        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'), 'never in a frame of another site');
        self::assertSame("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('<span id="ra-api" hidden data-header="X-CSRF-Token" data-token="token-for-request_analytics_api"></span>', $html, 'the token every request of its scripts sends');
        self::assertStringContainsString('<title>Analytics</title>', $html);
        self::assertSame(1, preg_match('/id="ra-trend" data-config="([^"]+)"/', $html, $matches));
        self::assertSame([
            'trendUrl' => '/bolt/extension/request-analytics/api/trend',
            'rollupUrl' => '/bolt/extension/request-analytics/api/rollup',
            'summaryUrl' => '/bolt/extension/request-analytics/api/summary',
            'breakdownUrl' => '/bolt/extension/request-analytics/api/breakdown',
            'pagesUrl' => '/bolt/extension/request-analytics/api/pages',
            'sourcesUrl' => '/bolt/extension/request-analytics/api/sources',
            'campaignsUrl' => '/bolt/extension/request-analytics/api/campaigns',
            'aliasesUrl' => '/bolt/extension/request-analytics/api/aliases',
            'brokenLinksUrl' => '/bolt/extension/request-analytics/api/broken-links',
            'searchesUrl' => '/bolt/extension/request-analytics/api/searches',
            'pageFlowUrl' => '/bolt/extension/request-analytics/api/page-flow',
            'botsUrl' => '/bolt/extension/request-analytics/api/bots',
            'hoursUrl' => '/bolt/extension/request-analytics/api/hours',
            'csrfHeader' => 'X-CSRF-Token',
            'range' => 'ytd',
            'compare' => 'previous',
            'metric' => 'pageviews',
            'rollup' => true,
        ], json_decode(html_entity_decode($matches[1]), true));
        self::assertStringContainsString('data-ra-interval="week"', $html, 'the full chart with its grouping');
        self::assertSame(1, preg_match('/id="ra-period" data-ra-locale="[^"]*" data-config="([^"]+)"/', $html, $period), 'the period bar');
        self::assertSame(['range' => 'ytd', 'compare' => 'previous', 'metric' => 'pageviews'], json_decode(html_entity_decode($period[1]), true));
        self::assertStringContainsString('data-ra-preset="3m" title="Last 3 months"', $html);
        foreach (['device', 'browser', 'os'] as $dimension) {
            self::assertStringContainsString('data-ra-dimension="' . $dimension . '"', $html);
        }
        self::assertStringContainsString('Values with fewer than 7 visits are counted as Other.', $html);
    }

    /**
     * Both metrics are explained: on the options, and in a hint next to the
     * select that also opens with the keyboard.
     */
    public function testMetricsAreExplained(): void
    {
        $html = (string) $this->controller()->page()->getContent();
        $pageviews = 'Every page opened, including moves from page to page within the site.';
        $visits = 'Arrivals from outside the site (search, links, typed address). Moving between its pages is not counted again.';

        self::assertStringContainsString('<option value="pageviews" title="' . $pageviews . '">Page views</option>', $html);
        self::assertStringContainsString('<option value="entries" title="' . $visits . '">Visits</option>', $html);
        self::assertStringContainsString('aria-describedby="ra-metric-hint"', $html);
        self::assertStringContainsString('<span class="ra-hint" tabindex="0" aria-label="What do the metrics mean?">', $html);
        self::assertSame(1, preg_match('~<span class="ra-hint__text" role="tooltip" id="ra-metric-hint">(.+?)</span>\s*</span>~s', $html, $matches));
        self::assertSame('Page views: ' . $pageviews . ' Visits: ' . $visits, mb_trim((string) preg_replace('~\s+~', ' ', strip_tags($matches[1]))));
    }

    public function testMetricsAreExplainedInTheBackendLanguage(): void
    {
        $html = (string) $this->controller('de')->page()->getContent();

        self::assertStringContainsString('>Besuche</option>', $html);
        self::assertStringContainsString('aria-label="Was bedeuten die Kennzahlen?"', $html);
        self::assertStringContainsString('Ankünfte von außerhalb der Website', $html);
    }

    /**
     * The pages card gets what its script needs, the languages with Bolt's
     * flags among it; a code Bolt doesn't know keeps its code as name.
     */
    public function testPageHasThePagesWithTheFlagsOfTheLanguages(): void
    {
        $html = (string) $this->controller()->page()->getContent();

        self::assertSame(1, preg_match('/id="ra-pages" data-config="([^"]+)"/', $html, $matches));
        self::assertSame([
            'pagesUrl' => '/bolt/extension/request-analytics/api/pages',
            'pageFlowUrl' => '/bolt/extension/request-analytics/api/page-flow',
            'defaultLocale' => 'en',
            'locales' => [
                'en' => [
                    'flag' => 'gb',
                    'name' => 'English',
                ],
                'nl' => [
                    'flag' => 'nl',
                    'name' => 'Nederlands',
                ],
                'ja' => [
                    'flag' => 'jp',
                    'name' => '日本語',
                ],
                'xx' => [
                    'flag' => 'xx',
                    'name' => 'xx',
                ],
            ],
        ], json_decode(html_entity_decode($matches[1]), true));
        self::assertStringContainsString('data-ra-order="least">Least viewed</button>', $html);
        self::assertStringContainsString('placeholder="Search pages"', $html);
        self::assertStringContainsString('<th scope="col">Visitors prefer</th>', $html, 'the languages of the visitors next to those of the pages');
        self::assertStringContainsString('<th scope="col">Pages viewed in</th>', $html);
    }

    /**
     * Campaigns and aliases stay empty on many sites: the card explains how to
     * fill them, with links of this site and the marker parameter of the config.
     */
    public function testSourcesExplainHowTheyAreFilled(): void
    {
        $this->project->configure([
            'aliases' => [
                'hosts' => ['rockfestkurim.cz'],
                'target' => 'https://rockfest-kurim.cz',
                'param' => 'via',
            ],
        ]);

        $html = (string) $this->controller()->page(Request::create('https://rockfest-kurim.cz/bolt/extension/request-analytics'))->getContent();

        self::assertSame(1, preg_match('/id="ra-sources" data-config="([^"]+)"/', $html, $matches));
        $config = json_decode(html_entity_decode($matches[1]), true);
        self::assertIsArray($config);
        self::assertSame(['rockfestkurim.cz'], $config['configuredAliases'] ?? null);
        self::assertStringContainsString('<code>https://rockfest-kurim.cz/?utm_source=newsletter&amp;utm_medium=email&amp;utm_campaign=autumn-sale</code>', $html);
        self::assertStringContainsString('utm_campaign={{campaign.name}}</code>', $html, 'the placeholder of Meta, not Twig');
        self::assertStringContainsString('<code>https://rockfest-kurim.cz/?via=poster-a2</code>', $html);
        self::assertStringContainsString('aliases.hosts of config/extensions/tomvondracek-requestanalytics.yaml', $html);
        $sources = (string) mb_strstr((string) mb_strstr($html, 'id="ra-sources"'), 'id="ra-gaps"', true);
        self::assertSame(3, mb_substr_count($sources, '<details class="ra-help"'), 'one help per tab of the sources');
    }

    /**
     * The help of the site search names the parameters the config listens to.
     */
    public function testSiteSearchNamesItsParameters(): void
    {
        $html = (string) $this->controller()->page()->getContent();

        self::assertStringContainsString('id="ra-gaps"', $html);
        self::assertStringContainsString('one of the search_params of the config: searchTerm, q, s, search.', $html);
    }

    public function testPageIsTranslated(): void
    {
        $html = (string) $this->controller('cs')->page()->getContent();

        self::assertStringContainsString('<title>Analytika</title>', $html);
        self::assertStringContainsString('Operační systémy', $html);
        self::assertStringContainsString('Hodnoty s méně než 5 návštěvami jsou započteny do Ostatní.', $html);
    }

    /**
     * Usually nothing fires: then the card only says why (here: statistics
     * without visits), with no count, no toggle and no badge.
     */
    public function testWithoutInsightsTheCardSaysWhy(): void
    {
        $html = (string) $this->controller()->page()->getContent();

        self::assertSame(1, preg_match('~<section class="card mb-4 ra-insights" id="ra-insights"[^>]* data-ra-insights-status="quiet">.*?</section>~s', $html, $card));
        self::assertStringContainsString('<i class="fas fa-seedling" aria-hidden="true"></i> <span>Too few visits to tell a real problem from chance (last 90 days: 0). Insights appear as the site gets more visits.</span>', $card[0]);
        self::assertStringNotContainsString('ra-insights__count', $card[0]);
        self::assertStringNotContainsString('data-ra-insights-toggle', $card[0]);
        self::assertStringNotContainsString('data-ra-insight-badge aria-expanded', $html);
        self::assertStringNotContainsString('class="ra-insight-strip"', $html);
        self::assertMatchesRegularExpression('~id="ra-languages" hidden>~', $html, 'the languages card waits for its data');
    }

    /**
     * The insights are written into the page: the first five on their card,
     * ranked, and every one on the card it is about, behind a badge.
     */
    /**
     * The page shows the host of the request in its help. A forged `Host`
     * never gets there: Symfony refuses it (Bolt answers 400).
     */
    public function testAForgedHostNeverReachesThePage(): void
    {
        foreach (['evil.example"><script>alert(1)</script>', "evil.example\r\nSet-Cookie: x=1", 'evil.example/<b>', "evil.example'onmouseover='alert(1)"] as $host) {
            $request = Request::create('/bolt/extension/request-analytics');
            $request->headers->set('Host', $host);

            try {
                $this->controller()
                    ->page($request);
                self::fail('Rendered with the host ' . json_encode($host));
            } catch (SuspiciousOperationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * Malicious values in the insights, rendered by Twig into the whole page:
     * escaped where they are, never run, never evaluated as Twig.
     */
    public function testMaliciousValuesOfInsightsAreEscapedInThePage(): void
    {
        $insights = [];
        foreach (Payloads::all() as $name => $payload) {
            $insights[] = new Insight('payload:' . $name, Insight::SECTION_SEARCH, Insight::KIND_GROW, 1, Message::of('%count% searches for %term%, and no page is about it.', [
                'count' => Param::number(45),
                'term' => Param::term($payload),
            ]), Message::of('Redirect it to the page that replaced it.'));
        }

        $html = (string) $this->controller(insights: $insights)->page(Request::create('https://example.com/bolt/extension/request-analytics'))->getContent();

        foreach (Payloads::all() as $name => $payload) {
            if (preg_match('/[<>"]/', $payload) === 1) {
                self::assertStringNotContainsString($payload, $html, $name . ' is in the page unescaped');
            }
        }
        foreach (['<script>alert(1)', '<svg/onload', '<img src=x onerror', '<?php', '<style>body'] as $tag) {
            self::assertStringNotContainsString($tag, $html);
        }
        self::assertStringContainsString('<em>{{7*7}}</em>', $html, 'Twig in a value is text, not evaluated');
        self::assertStringContainsString('<em>&lt;script&gt;alert(1)&lt;/script&gt;</em>', $html);
    }

    public function testInsightsAreWrittenIntoThePage(): void
    {
        $insights = [];
        foreach (range(1, 6) as $index) {
            $insights[] = new Insight('broken-link:/old-' . $index, Insight::SECTION_BROKEN_LINKS, Insight::KIND_FIX, 100 - $index, Message::of('%path% does not exist, and gets %count% requests from other sites.', [
                'path' => Param::path('/old-' . $index),
                'count' => Param::number(1000 + $index),
            ]), Message::of('Redirect it to the page that replaced it.'));
        }
        $insights[] = new Insight('missing-language:de', Insight::SECTION_LANGUAGES, Insight::KIND_GROW, 10, Message::of('%count% searches for %term%, and no page is about it.', [
            'count' => Param::number(45),
            'term' => Param::term('<script>'),
        ]), days: InsightContext::STRUCTURAL_DAYS);
        $insights[] = new Insight('own-referral', Insight::SECTION_HEADLINE, Insight::KIND_SETUP, 1, Message::of('Send the header %header%.', [
            'header' => Param::code('Referrer-Policy: strict-origin-when-cross-origin'),
        ]), anchor: 'ra-sources');

        $response = $this->controller(insights: $insights)->page(Request::create('https://example.com/bolt/extension/request-analytics'));
        $html = (string) $response->getContent();

        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame(1, preg_match('~<section class="card mb-4 ra-insights" id="ra-insights".*?</section>~s', $html, $card));
        self::assertSame(['own-referral', 'broken-link:/old-1', 'broken-link:/old-2', 'broken-link:/old-3', 'broken-link:/old-4', 'broken-link:/old-5', 'broken-link:/old-6', 'missing-language:de'], self::ids($card[0]), 'setup first, then by impact × weight');
        preg_match_all('~data-ra-insight="([^"]+)" data-ra-insight-more hidden>~', $card[0], $hidden);
        self::assertSame(['broken-link:/old-5', 'broken-link:/old-6', 'missing-language:de'], $hidden[1], 'five shown, the rest behind the button');
        self::assertStringContainsString('Show all (8)', $card[0]);
        self::assertStringContainsString('<code>/old-1</code> does not exist, and gets 1,001 requests from other sites. <span class="ra-insight__action">→ Redirect it to the page that replaced it.</span>', $card[0]);
        self::assertStringContainsString('Last 28 days', $card[0]);
        self::assertStringContainsString('<a href="#ra-sources" data-ra-insight-go="headline">', $card[0], 'a setup insight leads to the card it is about');

        self::assertMatchesRegularExpression('~id="ra-broken-links">\s*<div class="card-header">.*?<button type="button" class="ra-insight-badge" data-ra-insight-badge aria-expanded="false" aria-controls="ra-insight-strip-broken-links"[^>]*><i class="fas fa-rocket" aria-hidden="true"></i> 6</button>~s', $html);
        self::assertSame(1, preg_match('~<div class="ra-insight-strip" id="ra-insight-strip-broken-links".*?</div>~s', $html, $strip));
        self::assertCount(6, self::ids($strip[0]), 'every insight of the card');

        self::assertStringContainsString('<em>&lt;script&gt;</em>', $html, 'values are escaped');
        self::assertStringContainsString('Last 90 days', $html);
        self::assertMatchesRegularExpression('~id="ra-languages">~', $html, 'the languages card shows its insight before its data');
        self::assertStringNotContainsString('aria-controls="ra-insight-strip-sources"', $html, 'no badge on a card without insights');
    }

    public function testInsightsAreInTheBackendLanguage(): void
    {
        $html = (string) $this->controller('cs', insights: [new Insight('search-gap:x', Insight::SECTION_SEARCH, Insight::KIND_FIX, 120, Message::of('%count% searches for %term%, and no page is about it.', [
            'count' => Param::number(1840),
            'term' => Param::term('parkování'),
        ]))])->page()->getContent();

        self::assertStringContainsString("1\u{a0}840 hledání výrazu <em>parkování</em> a žádná stránka o něm není.", $html);
        self::assertStringContainsString('<i class="fas fa-wrench" aria-hidden="true"></i> Oprava</span>', $html);

        $german = (string) $this->controller('de', insights: [new Insight('search-gap:x', Insight::SECTION_SEARCH, Insight::KIND_FIX, 120, Message::of('%count% searches for %term%, and no page is about it.', [
            'count' => Param::number(1840),
            'term' => Param::term('parken'),
        ]))])->page()->getContent();
        self::assertStringContainsString('1.840 Suchen nach <em>parken</em>, und keine Seite behandelt das Thema.', $german, 'numbers and texts in the backend language');
    }

    public function testConfigErrorIsShownInsteadOfTheCharts(): void
    {
        $this->project->configure([
            'enabled' => 'maybe',
        ]);

        $html = (string) $this->controller()->page()->getContent();

        self::assertStringContainsString('The Request Analytics `enabled` must be true or false', $html);
        self::assertStringNotContainsString('data-ra-dimension', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('ra-skeleton', $html, 'nothing is loading');
    }

    /**
     * Dates and numbers follow the backend language, like the texts: Bolt sets
     * `<html lang>` to the user's saved language, which `?_locale` overrides.
     */
    /**
     * Only who may delete (`access.delete`) sees the button that deletes
     * statistics; for who may only see them it is not in the page.
     */
    public function testOnlyWhoMayDeleteSeesTheButton(): void
    {
        $editor = (string) $this->controller(granted: [AnalyticsVoter::VIEW])->page()->getContent();
        self::assertStringNotContainsString('id="ra-erase"', $editor);
        self::assertStringNotContainsString('<dialog', $editor);
        self::assertStringNotContainsString('token-for-request_analytics_erase', $editor);

        $admin = (string) $this->controller(granted: [AnalyticsVoter::VIEW, AnalyticsVoter::DELETE])->page()->getContent();
        self::assertSame(1, preg_match('/id="ra-erase" data-ra-locale="[^"]*" data-config="([^"]+)" data-token="token-for-request_analytics_erase"/', $admin, $matches));
        self::assertSame([
            'previewUrl' => '/bolt/extension/request-analytics/api/erase/preview',
            'eraseUrl' => '/bolt/extension/request-analytics/api/erase',
            'csrfHeader' => 'X-CSRF-Token',
            'today' => '2026-11-13',
            'maxDays' => 36500,
        ], json_decode(html_entity_decode($matches[1]), true));
        self::assertStringContainsString('<dialog class="ra-erase-dialog" id="ra-erase-dialog" aria-labelledby="ra-erase-choose-title">', $admin);
    }

    /**
     * Whole days up to now, the least preselected, and everything apart; then
     * a confirmation that has to be ticked before the red button works.
     */
    public function testDeletingChoosesAPeriodAndAsksOnceMore(): void
    {
        $html = (string) $this->controller(granted: [AnalyticsVoter::VIEW, AnalyticsVoter::DELETE])->page()->getContent();

        self::assertSame(1, preg_match_all('/name="ra-erase-days" value="1" checked/', $html), 'today is preselected');
        foreach (['7', '30', '90', 'custom', 'all'] as $value) {
            self::assertStringContainsString('name="ra-erase-days" value="' . $value . '">', $html);
        }
        self::assertMatchesRegularExpression('~Last\s*<input type="number"[^>]* data-ra-erase-custom min="1" max="36500"[^>]*>\s*days~', $html, 'a number of days, inside its sentence');
        self::assertStringContainsString('<input type="checkbox" class="form-check-input" id="ra-erase-understand" data-ra-erase-understand>', $html);
        self::assertMatchesRegularExpression('~<button type="button" class="btn btn-danger" data-ra-erase-confirm disabled>~', $html);
        self::assertStringContainsString('Delete permanently?', $html);
    }

    public function testDeletingIsTranslated(): void
    {
        $html = (string) $this->controller('fr', granted: [AnalyticsVoter::VIEW, AnalyticsVoter::DELETE])->page()->getContent();

        self::assertStringContainsString('Supprimer définitivement ?', $html);
        // "%days% derniers jours": the number comes first.
        self::assertMatchesRegularExpression('~<span class="ra-erase__label"><input type="number"[^>]*>\s*derniers jours</span>~', $html);
    }

    public function testScriptsFormatInTheLanguageOfTheRequest(): void
    {
        $request = Request::create('/bolt/extension/request-analytics');
        $request->setLocale('pt_BR');
        $app = new AppVariable();
        $stack = new RequestStack([$request]);
        $app->setRequestStack($stack);

        $controller = $this->controller('pt_BR', $app);
        $html = (string) $controller->page($request)->getContent();

        foreach (['ra-summary-tiles', 'ra-trend', 'ra-breakdown', 'ra-hours', 'ra-pages', 'ra-sources', 'ra-gaps', 'ra-bots'] as $id) {
            self::assertStringContainsString('data-ra-locale="pt-BR" id="' . $id . '"', $html);
        }

        self::assertStringContainsString('data-ra-locale="" id="ra-trend"', (string) $this->controller()->page()->getContent(), 'without a request: the language of <html>');
    }

    /**
     * @return list<string> the insights listed in a piece of the page
     */
    private static function ids(string $html): array
    {
        preg_match_all('~data-ra-insight="([^"]+)"~', $html, $matches);

        return $matches[1];
    }

    /**
     * @param list<Insight> $insights what the rules find
     * @param list<string> $granted the attributes the user is granted (AnalyticsVoter)
     */
    private function controller(string $locale = 'en', ?AppVariable $app = null, array $insights = [], array $granted = []): AnalyticsController
    {
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 2) . '/templates', Extension::TWIG_NAMESPACE);
        $twig = new Environment(new ChainLoader([
            new ArrayLoader([
                '@bolt/_base/layout.html.twig' => '<title>{{ block("title")|striptags|trim }}</title>{% block shoulder %}{% endblock %}{% block title %}{% endblock %}<main>{% block main %}{% endblock %}</main>',
            ]),
            $loader,
        ]), [
            'strict_variables' => true,
            'autoescape' => 'html',
        ]);
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => 'token-for-' . $id));
        // Bolt's `locale()`: the flag and the name of a language, as its language picker shows them.
        $twig->addFunction(new TwigFunction('locale', static fn (string $code): array => [
            'code' => $code,
            'flag' => [
                'en' => 'gb',
                'nl' => 'nl',
                'ja' => 'jp',
            ][$code] ?? $code,
            'localizedname' => [
                'en' => 'English',
                'nl' => 'Nederlands',
                'ja' => '日本語',
            ][$code] ?? 'unknown',
        ]));
        $twig->addExtension(new TranslationExtension(Translations::translator($locale)));
        if ($app instanceof AppVariable) {
            $twig->addGlobal('app', $app);
        }

        $routes = new RouteCollection();
        $routes->add(ApiController::ROUTE_TREND, new RoutingRoute('/bolt/extension/request-analytics/api/trend'));
        $routes->add(ApiController::ROUTE_ROLLUP, new RoutingRoute('/bolt/extension/request-analytics/api/rollup'));
        $routes->add(ApiController::ROUTE_BREAKDOWN, new RoutingRoute('/bolt/extension/request-analytics/api/breakdown'));
        $routes->add(ApiController::ROUTE_PAGES, new RoutingRoute('/bolt/extension/request-analytics/api/pages'));
        $routes->add(ApiController::ROUTE_SUMMARY, new RoutingRoute('/bolt/extension/request-analytics/api/summary'));
        $routes->add(ApiController::ROUTE_ERASE_PREVIEW, new RoutingRoute('/bolt/extension/request-analytics/api/erase/preview'));
        $routes->add(ApiController::ROUTE_ERASE, new RoutingRoute('/bolt/extension/request-analytics/api/erase'));
        foreach ([ApiController::ROUTE_SOURCES => 'sources', ApiController::ROUTE_CAMPAIGNS => 'campaigns', ApiController::ROUTE_ALIASES => 'aliases', ApiController::ROUTE_BROKEN_LINKS => 'broken-links', ApiController::ROUTE_SEARCHES => 'searches', ApiController::ROUTE_PAGE_FLOW => 'page-flow', ApiController::ROUTE_BOTS => 'bots', ApiController::ROUTE_HOURS => 'hours'] as $name => $path) {
            $routes->add($name, new RoutingRoute('/bolt/extension/request-analytics/api/' . $path));
        }

        $authorizationChecker = self::createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')
            ->willReturnCallback(static fn (mixed $attribute): bool => in_array($attribute, $granted, true));

        $catalog = new FakePageCatalog('en', ['en', 'nl', 'ja', 'xx']);
        $rule = new class($insights) implements InsightRule {
            /**
             * @param list<Insight> $insights
             */
            public function __construct(
                private readonly array $insights,
            ) {
            }

            public function evaluate(InsightContext $context): array
            {
                return $this->insights;
            }

            public function dependsOn(): array
            {
                return [];
            }
        };

        return new AnalyticsController(
            $this->project->boltConfig(),
            $this->project->configLoader(),
            new UrlGenerator($routes, new RequestContext()),
            $twig,
            $catalog,
            new InsightsReport([$rule], new MemoryInsightData(), $catalog, $this->project->configLoader(), $this->project->siteTime(), new ArrayAdapter(), new NullLogger()),
            new InsightFormatter(Translations::translator($locale)),
            $authorizationChecker,
            $this->project->siteTime(),
        );
    }
}
