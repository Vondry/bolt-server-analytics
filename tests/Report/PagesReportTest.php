<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Report;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Report\PagesReport;
use TomVondracek\RequestAnalytics\Tests\Support\FakePageCatalog;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;

/**
 * @phpstan-import-type Report from PagesReport
 */
#[Group('database')]
final class PagesReportTest extends TestCase
{
    private Pipeline $pipeline;

    protected function setUp(): void
    {
        $this->pipeline = new Pipeline();
    }

    protected function tearDown(): void
    {
        $this->pipeline->remove();
    }

    public function testMostViewedFirstWithTitlesAndExactCounts(): void
    {
        $this->pipeline->pages('2026-11-10', '/', 7, true);
        $this->pipeline->pages('2026-11-11', '/', 3);
        $this->pipeline->pages('2026-11-11', '/page/program', 4);
        $this->pipeline->pages('2026-11-12', '/page/tickets', 1);
        // Out of the range.
        $this->pipeline->pages('2026-11-01', '/page/old', 100);

        $report = $this->report(new FakePageCatalog('en', ['en'], [
            '/' => 'Rockfest',
            '/page/program' => 'Program',
        ]));

        self::assertSame([['/', 'Rockfest', 10], ['/page/program', 'Program', 4], ['/page/tickets', null, 1]], self::rows($report));
        self::assertSame([3, 10, 15], [$report['total'], $report['max'], $report['totalViews']]);
        self::assertSame([[
            'code' => 'en',
            'views' => 15,
        ]], $report['locales'], 'a single language');
    }

    /**
     * Bolt prefixes a localized page with its language; the default language
     * has no prefix. The languages of a page are one row.
     */
    public function testLanguagesOfAPageAreOneRow(): void
    {
        $this->pipeline->pages('2026-11-10', '/page/about', 6);
        $this->pipeline->pages('2026-11-10', '/nl/page/about', 3);
        $this->pipeline->pages('2026-11-10', '/ja/page/about', 1);
        $this->pipeline->pages('2026-11-10', '/nl', 2);
        $this->pipeline->pages('2026-11-10', '/nl/', 1);
        $this->pipeline->pages('2026-11-10', '/', 4);
        // Not a language of the site: a path like any other.
        $this->pipeline->pages('2026-11-10', '/xx/page/about', 5);

        $report = $this->report(new FakePageCatalog('en', ['en', 'nl', 'ja'], [
            '/page/about' => 'About',
        ]));

        self::assertSame([
            [
                'key' => '/page/about',
                'title' => 'About',
                'views' => 10,
                'previous' => 0,
                'locales' => [
                    'en' => 6,
                    'nl' => 3,
                    'ja' => 1,
                ],
            ],
            [
                'key' => '/',
                'title' => null,
                'views' => 7,
                'previous' => 0,
                'locales' => [
                    'en' => 4,
                    'nl' => 3,
                ],
            ],
            [
                'key' => '/xx/page/about',
                'title' => null,
                'views' => 5,
                'previous' => 0,
                'locales' => [
                    'en' => 5,
                ],
            ],
        ], $report['rows']);
        self::assertSame([
            [
                'code' => 'en',
                'views' => 15,
            ],
            [
                'code' => 'nl',
                'views' => 6,
            ],
            [
                'code' => 'ja',
                'views' => 1,
            ],
        ], $report['locales']);
    }

    /**
     * Each page carries its views of as many days before: the range of
     * 2026-11-09 … 2026-11-15 is cut at today, 2026-11-13, and compared with
     * 2026-11-04 … 2026-11-08, its languages merged too.
     */
    public function testPagesCarryTheirViewsOfThePeriodBefore(): void
    {
        $this->pipeline->pages('2026-11-10', '/page/program', 9);
        $this->pipeline->pages('2026-11-05', '/page/program', 3);
        $this->pipeline->pages('2026-11-06', '/nl/page/program', 1);
        $this->pipeline->pages('2026-11-03', '/page/program', 50);
        $this->pipeline->pages('2026-11-08', '/page/gone', 4);

        $report = $this->report(new FakePageCatalog('en', ['en', 'nl'], [
            '/page/gone' => 'Gone',
        ]));

        self::assertSame(['2026-11-04', '2026-11-08'], [$report['previousFrom'], $report['previousTo']]);
        self::assertSame([['/page/program', 9, 4], ['/page/gone', 0, 4]], array_map(static fn (array $row): array => [$row['key'], $row['views'], $row['previous']], $report['rows']));
    }

    public function testWithoutComparisonThereIsNoPreviousCount(): void
    {
        $this->pipeline->pages('2026-11-10', '/page/program', 9);
        $this->pipeline->pages('2026-11-05', '/page/program', 3);

        $report = $this->pipeline->pagesReport()
            ->report('pageviews', '2026-11-09', '2026-11-15', compare: false);

        self::assertSame([null, null], [$report['previousFrom'], $report['previousTo']]);
        self::assertSame([['/page/program', 9, null]], array_map(static fn (array $row): array => [$row['key'], $row['views'], $row['previous']], $report['rows']));
    }

    /**
     * Do visitors who prefer a language find pages in it? Matched by the
     * language alone: Brazilian Portuguese pages serve visitors asking for
     * Portuguese. Unknown languages (also every opt-out) count on neither side;
     * a language few visitors prefer is "other".
     */
    public function testLanguagesOfVisitorsNextToTheLanguagesOfThePages(): void
    {
        $this->pipeline->pages('2026-11-10', '/page/about', 60);
        $this->pipeline->pages('2026-11-10', '/nl/page/about', 30);
        $this->pipeline->pages('2026-11-10', '/pt_BR/page/about', 10);
        $this->pipeline->locales('2026-11-10', 'cs', 50);
        $this->pipeline->locales('2026-11-10', 'en', 25);
        $this->pipeline->locales('2026-11-10', 'nl', 10);
        $this->pipeline->locales('2026-11-10', 'pt', 8);
        $this->pipeline->locales('2026-11-10', 'fi', 2);
        $this->pipeline->locales('2026-11-10', 'sv', 1);
        $this->pipeline->locales('2026-11-10', 'und', 4);

        $report = $this->pipeline->pagesReport(new FakePageCatalog('en', ['en', 'nl', 'pt_BR']))
            ->report('pageviews', '2026-11-09', '2026-11-15', threshold: 5);

        self::assertSame([
            [
                'code' => 'cs',
                'visitors' => 50,
                'pages' => 0,
                'offered' => false,
            ],
            [
                'code' => 'en',
                'visitors' => 25,
                'pages' => 60,
                'offered' => true,
            ],
            [
                'code' => 'nl',
                'visitors' => 10,
                'pages' => 30,
                'offered' => true,
            ],
            [
                'code' => 'pt',
                'visitors' => 8,
                'pages' => 10,
                'offered' => true,
            ],
            [
                'code' => PagesReport::OTHER,
                'visitors' => 3,
                'pages' => 0,
                'offered' => true,
            ],
        ], $report['languages']);
    }

    public function testLanguagesOfTheVisits(): void
    {
        $this->pipeline->pages('2026-11-10', '/', 4, true);
        $this->pipeline->locales('2026-11-10', 'de', 3, true);
        $this->pipeline->locales('2026-11-10', 'de', 50);

        $report = $this->pipeline->pagesReport()
            ->report('entries', '2026-11-09', '2026-11-15');

        self::assertSame([['de', 3, 0], ['en', 0, 4]], array_map(static fn (array $language): array => [$language['code'], $language['visitors'], $language['pages']], $report['languages']));
    }

    public function testDefaultLanguageComesFirstAmongEquals(): void
    {
        $this->pipeline->pages('2026-11-10', '/a', 5);
        $this->pipeline->pages('2026-11-10', '/de/a', 5);

        $report = $this->report(new FakePageCatalog('en', ['de', 'en']));

        self::assertSame(['en', 'de'], array_column($report['locales'], 'code'));
    }

    /**
     * The pages that matter most for "least viewed" are the published ones
     * nobody opened: the statistics alone don't know them.
     */
    public function testLeastViewedStartsWithPublishedPagesNobodyOpened(): void
    {
        $this->pipeline->pages('2026-11-10', '/page/popular', 9);
        $this->pipeline->pages('2026-11-10', '/page/rare', 1);

        $report = $this->report(new FakePageCatalog('en', ['en'], [
            '/page/popular' => 'Popular',
            '/page/rare' => 'Rare',
            '/page/forgotten' => 'Forgotten',
            '/entries' => 'Entries',
        ]), order: PagesReport::ORDER_LEAST);

        self::assertSame([['/entries', 'Entries', 0], ['/page/forgotten', 'Forgotten', 0], ['/page/rare', 'Rare', 1], ['/page/popular', 'Popular', 9]], self::rows($report));
        self::assertSame([], $report['rows'][0]['locales']);
    }

    public function testPagesOfTheList(): void
    {
        for ($index = 1; $index <= 45; ++$index) {
            $this->pipeline->pages('2026-11-10', sprintf('/page/p%02d', $index), $index);
        }

        $first = $this->report();
        $last = $this->report(page: 3);
        $beyond = $this->report(page: 99);

        self::assertSame([1, 3, 20, 45], [$first['page'], $first['pages'], $first['perPage'], $first['total']]);
        self::assertCount(20, $first['rows']);
        self::assertSame('/page/p45', $first['rows'][0]['key']);
        self::assertSame(['/page/p05', '/page/p04', '/page/p03', '/page/p02', '/page/p01'], array_column($last['rows'], 'key'));
        self::assertSame(3, $beyond['page'], 'a page beyond the end is the last one');
    }

    public function testSearchByPathOrTitle(): void
    {
        $this->pipeline->pages('2026-11-10', '/page/program', 4);
        $this->pipeline->pages('2026-11-10', '/nl/page/program', 2);
        $this->pipeline->pages('2026-11-10', '/page/tickets', 3);
        $catalog = new FakePageCatalog('en', ['en', 'nl'], [
            '/page/tickets' => 'Vstupenky a ceny',
        ]);

        self::assertSame(['/page/program'], array_column($this->report($catalog, search: 'PROGRAM')['rows'], 'key'));
        self::assertSame(['/page/tickets'], array_column($this->report($catalog, search: 'ceny')['rows'], 'key'));
        $none = $this->report($catalog, search: 'nothing');
        self::assertSame([[], 0, 1], [$none['rows'], $none['total'], $none['pages']]);
        self::assertSame(9, $none['totalViews'], 'the languages and totals are of the whole site');
    }

    public function testVisits(): void
    {
        $this->pipeline->pages('2026-11-10', '/', 7, true);
        $this->pipeline->pages('2026-11-10', '/', 3);
        $this->pipeline->pages('2026-11-10', '/page/program', 4);

        $report = $this->pipeline->pagesReport()
            ->report('entries', '2026-11-09', '2026-11-15');

        self::assertSame([['/', null, 7]], self::rows($report));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'metric' => ['hits', '2026-11-10', '2026-11-10', 'most'];
        yield 'date' => ['pageviews', '2026-11-31', '2026-12-01', 'most'];
        yield 'reversed' => ['pageviews', '2026-11-10', '2026-11-09', 'most'];
        yield 'order' => ['pageviews', '2026-11-10', '2026-11-10', 'random'];
    }

    #[DataProvider('invalidArguments')]
    public function testInvalidArguments(string $metric, string $from, string $to, string $order): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->pipeline->pagesReport()
            ->report($metric, $from, $to, $order);
    }

    /**
     * @return Report
     */
    private function report(?FakePageCatalog $catalog = null, string $order = PagesReport::ORDER_MOST, int $page = 1, string $search = ''): array
    {
        return $this->pipeline->pagesReport($catalog)
            ->report('pageviews', '2026-11-09', '2026-11-15', $order, $page, $search);
    }

    /**
     * @param Report $report
     *
     * @return list<array{string, ?string, int}> path, title, views
     */
    private static function rows(array $report): array
    {
        return array_map(static fn (array $row): array => [$row['key'], $row['title'], $row['views']], $report['rows']);
    }
}
