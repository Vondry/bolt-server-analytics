<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Insight;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Aggregation\RollupState;
use TomVondracek\RequestAnalytics\Insight\DatabaseInsightData;
use TomVondracek\RequestAnalytics\Query\BreakdownQuery;
use TomVondracek\RequestAnalytics\Query\ContentGapsQuery;
use TomVondracek\RequestAnalytics\Query\DayRange;
use TomVondracek\RequestAnalytics\Query\FlowQuery;
use TomVondracek\RequestAnalytics\Query\PagesQuery;
use TomVondracek\RequestAnalytics\Query\SourcesQuery;
use TomVondracek\RequestAnalytics\Query\SummaryQuery;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;

/**
 * The aggregates the insights read, from the extension's tables.
 */
#[Group('database')]
final class DatabaseInsightDataTest extends TestCase
{
    private Pipeline $pipeline;
    private DatabaseInsightData $data;

    protected function setUp(): void
    {
        $this->pipeline = new Pipeline();
        $connection = $this->pipeline->database->connection;
        $tables = $this->pipeline->database->tables;
        $this->data = new DatabaseInsightData(
            new RollupState($connection, $tables),
            new SummaryQuery($connection, $tables),
            new SourcesQuery($connection, $tables),
            new PagesQuery($connection, $tables),
            new BreakdownQuery($connection, $tables),
            new FlowQuery($connection, $tables),
            new ContentGapsQuery($connection, $tables),
        );
    }

    protected function tearDown(): void
    {
        $this->pipeline->remove();
    }

    public function testDailyTotalsAndTheFirstDay(): void
    {
        self::assertNull($this->data->firstDay());

        $this->pipeline->daily([
            '2026-11-01' => [100, 40],
            '2026-11-03' => [80, 30],
            '2026-11-09' => [5, 1],
        ]);

        self::assertSame('2026-11-01', $this->data->firstDay());
        self::assertSame([
            '2026-11-01' => [
                'pageviews' => 100,
                'visits' => 40,
                'bots' => 0,
            ],
            '2026-11-03' => [
                'pageviews' => 80,
                'visits' => 30,
                'bots' => 0,
            ],
        ], $this->data->daily(DayRange::of('2026-11-01', '2026-11-08')), 'only days with a row');
    }

    public function testClientsPerDayWithTheirVisits(): void
    {
        $this->pipeline->clients('2026-11-01', 6, 'mobile', 'safari', 'ios', entry: true);
        $this->pipeline->clients('2026-11-01', 9, 'mobile', 'safari', 'ios');
        $this->pipeline->clients('2026-11-01', 4, 'mobile', 'safari', 'macos', entry: true);
        $this->pipeline->clients('2026-11-02', 3, 'desktop', 'chrome', 'windows', entry: true);

        $rows = $this->data->clients(DayRange::of('2026-11-01', '2026-11-02'));
        usort($rows, static fn (array $a, array $b): int => [$a['day'], $a['browser']] <=> [$b['day'], $b['browser']]);

        self::assertSame([
            [
                'day' => '2026-11-01',
                'device' => 'mobile',
                'browser' => 'safari',
                'views' => 19,
                'entries' => 10,
            ],
            [
                'day' => '2026-11-02',
                'device' => 'desktop',
                'browser' => 'chrome',
                'views' => 3,
                'entries' => 3,
            ],
        ], $rows);
    }

    /**
     * `_` of `pt_BR` is a wildcard of LIKE: `/ptXBR/…` is no move into it.
     */
    public function testMovesIntoTheLanguageOfAPrefix(): void
    {
        $this->pipeline->transitions('2026-11-01', '/program', '/pt_BR/program', 4);
        $this->pipeline->transitions('2026-11-02', '/program', '/pt_BR/program', 3);
        $this->pipeline->transitions('2026-11-01', '/', '/pt_BR', 2);
        $this->pipeline->transitions('2026-11-01', '/', '/ptXBR/program', 9);
        $this->pipeline->transitions('2026-11-01', '/', '/pt_BRx', 9);
        $this->pipeline->transitions('2026-11-01', '/pt_BR', '/program', 9);

        $moves = $this->data->movesInto(DayRange::of('2026-11-01', '2026-11-02'), '/pt_BR');
        usort($moves, static fn (array $a, array $b): int => $a['to'] <=> $b['to']);

        self::assertSame([
            [
                'from' => '/',
                'to' => '/pt_BR',
                'hits' => 2,
            ],
            [
                'from' => '/program',
                'to' => '/pt_BR/program',
                'hits' => 7,
            ],
        ], $moves);
    }

    public function testPageViewsAndVisitsOfPagesAndLanguages(): void
    {
        $this->pipeline->pages('2026-11-01', '/program', 5, entry: true);
        $this->pipeline->pages('2026-11-01', '/program', 7);
        $this->pipeline->pages('2026-11-01', '/kontakt', 2);
        $this->pipeline->locales('2026-11-01', 'cs', 8, entry: true);
        $this->pipeline->locales('2026-11-01', 'cs', 4);

        $range = DayRange::of('2026-11-01', '2026-11-01');
        $pages = $this->data->pages($range);
        ksort($pages);

        self::assertSame([
            '/kontakt' => [
                'views' => 2,
                'entries' => 0,
            ],
            '/program' => [
                'views' => 12,
                'entries' => 5,
            ],
        ], $pages);
        self::assertSame([
            'cs' => [
                'views' => 12,
                'entries' => 8,
            ],
        ], $this->data->languages($range));
    }
}
