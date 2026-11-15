<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Menu;

use Knp\Menu\MenuFactory;
use Knp\Menu\MenuItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use TomVondracek\RequestAnalytics\Controller\AnalyticsController;
use TomVondracek\RequestAnalytics\Insight\Insight;
use TomVondracek\RequestAnalytics\Insight\InsightContext;
use TomVondracek\RequestAnalytics\Insight\InsightRule;
use TomVondracek\RequestAnalytics\Insight\InsightsReport;
use TomVondracek\RequestAnalytics\Insight\Message;
use TomVondracek\RequestAnalytics\Menu\AnalyticsMenu;
use TomVondracek\RequestAnalytics\Security\AnalyticsVoter;
use TomVondracek\RequestAnalytics\Tests\Support\FakePageCatalog;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryInsightData;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryLogger;
use TomVondracek\RequestAnalytics\Tests\Support\Project;
use TomVondracek\RequestAnalytics\Tests\Support\Translations;

final class AnalyticsMenuTest extends TestCase
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

    public function testAddsATranslatedItemThatLeadsToTheAnalyticsPage(): void
    {
        $menu = $this->menu($this->analyticsMenu(locale: 'de'));

        $item = $menu->getChild(AnalyticsMenu::NAME);
        self::assertInstanceOf(MenuItem::class, $item);
        self::assertSame('/bolt/extension/request-analytics', $item->getUri());
        self::assertSame('Analyse', $item->getExtra('name'));
        self::assertSame('fa-chart-pie', $item->getExtra('icon'));
        self::assertFalse($item->getExtra('active'));
    }

    public function testIsActiveOnTheAnalyticsPage(): void
    {
        $request = Request::create('/bolt/extension/request-analytics');
        $request->attributes->set('_route', AnalyticsController::ROUTE);

        $item = $this->menu($this->analyticsMenu(request: $request))->getChild(AnalyticsMenu::NAME);

        self::assertTrue($item?->getExtra('active'));
    }

    public function testNoItemWithoutTheDashboardPermission(): void
    {
        self::assertFalse($this->menu($this->analyticsMenu(granted: false))->hasChildren());
    }

    public function testNoItemWhenTheExtensionIsDisabled(): void
    {
        $this->project->configure([
            'enabled' => false,
        ]);

        self::assertFalse($this->menu($this->analyticsMenu())->hasChildren());
    }

    /**
     * The page names what is wrong with the config, so the way there stays.
     */
    public function testItemStaysWithABrokenConfig(): void
    {
        $this->project->configure([
            'enabled' => 'maybe',
        ]);

        self::assertTrue($this->menu($this->analyticsMenu())->hasChildren());
    }

    /**
     * Several insights that ask for a fix turn the icon into a warning, on
     * every backend page.
     *
     * @param list<string> $kinds
     */
    #[DataProvider('alerts')]
    public function testTheIconWarnsOfSeveralFixes(array $kinds, string $icon): void
    {
        $insights = array_map(static fn (string $kind, int $index): Insight => new Insight('insight-' . $index, Insight::SECTION_BROKEN_LINKS, $kind, 10, Message::of('Redirect it to the page that replaced it.')), $kinds, array_keys($kinds));

        $item = $this->menu($this->analyticsMenu(insights: $insights))->getChild(AnalyticsMenu::NAME);

        self::assertSame($icon, $item?->getExtra('icon'));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function alerts(): iterable
    {
        yield 'none' => [[], AnalyticsMenu::ICON];
        yield 'one fix' => [[Insight::KIND_FIX, Insight::KIND_GROW, Insight::KIND_DECIDE], AnalyticsMenu::ICON];
        yield 'two fixes' => [[Insight::KIND_FIX, Insight::KIND_FIX], AnalyticsMenu::ALERT_ICON];
        yield 'a fix and a setup' => [[Insight::KIND_SETUP, Insight::KIND_FIX], AnalyticsMenu::ALERT_ICON];
    }

    /**
     * Before the tables exist, or with a failing rule: the plain icon, and
     * nothing logged on every backend page.
     */
    public function testNoWarningWhenTheInsightsCannotBeComputed(): void
    {
        $logger = new MemoryLogger();
        $failing = new class() implements InsightRule {
            public function evaluate(InsightContext $context): array
            {
                throw new RuntimeException('no such table');
            }

            public function dependsOn(): array
            {
                return [];
            }
        };

        $item = $this->menu($this->analyticsMenu(rule: $failing, logger: $logger))->getChild(AnalyticsMenu::NAME);

        self::assertSame(AnalyticsMenu::ICON, $item?->getExtra('icon'));
        self::assertSame([], $logger->records);
    }

    public function testNoItemBeforeTheRoutesAreConfigured(): void
    {
        self::assertFalse($this->menu($this->analyticsMenu(routes: false))->hasChildren());
    }

    private function menu(AnalyticsMenu $analyticsMenu): MenuItem
    {
        $menu = (new MenuFactory())->createItem('root');
        self::assertInstanceOf(MenuItem::class, $menu);
        $analyticsMenu->addItems($menu);

        return $menu;
    }

    /**
     * @param list<Insight> $insights what the rules find
     */
    private function analyticsMenu(bool $granted = true, bool $routes = true, string $locale = 'en', ?Request $request = null, array $insights = [], ?InsightRule $rule = null, ?MemoryLogger $logger = null): AnalyticsMenu
    {
        $authorization = self::createStub(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')
            ->willReturnCallback(static fn (mixed $attribute): bool => $granted && $attribute === AnalyticsVoter::VIEW);

        $collection = new RouteCollection();
        if ($routes) {
            $collection->add(AnalyticsController::ROUTE, new Route('/bolt/extension/request-analytics'));
        }

        $requests = new RequestStack([$request ?? Request::create('/bolt/')]);

        return new AnalyticsMenu(
            new UrlGenerator($collection, new RequestContext()),
            Translations::translator($locale),
            $authorization,
            $this->project->configLoader(),
            $requests,
            new InsightsReport([$rule ?? self::rule($insights)], new MemoryInsightData(), new FakePageCatalog(), $this->project->configLoader(), $this->project->siteTime(), new ArrayAdapter(), $logger ?? new MemoryLogger()),
        );
    }

    /**
     * @param list<Insight> $insights
     */
    private static function rule(array $insights): InsightRule
    {
        return new class($insights) implements InsightRule {
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
    }
}
