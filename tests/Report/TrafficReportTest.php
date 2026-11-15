<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Report;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Report\TrafficReport;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;

/**
 * "Today" is 2026-11-13, a Friday; the range 2026-11-09 … 2026-11-15 is
 * compared with 2026-11-04 … 2026-11-08.
 */
#[Group('database')]
final class TrafficReportTest extends TestCase
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

    public function testBotsByWhatTheyAreForMostFirst(): void
    {
        $this->pipeline->bots('2026-11-10', 'googlebot', 40);
        $this->pipeline->bots('2026-11-10', 'bingbot', 10);
        $this->pipeline->bots('2026-11-10', 'gptbot', 30);
        $this->pipeline->bots('2026-11-11', 'claudebot', 25);
        $this->pipeline->bots('2026-11-11', 'disguised', 3);
        $this->pipeline->bots('2026-11-11', 'generic', 4);
        $this->pipeline->bots('2026-11-11', 'somethingnew', 1);
        $this->pipeline->bots('2026-11-05', 'gptbot', 5);
        // Gone since: in the change of its category only.
        $this->pipeline->bots('2026-11-05', 'perplexitybot', 15);
        $this->pipeline->bots('2026-11-05', 'googlebot', 50);

        $bots = $this->pipeline->trafficReport()
            ->bots('2026-11-09', '2026-11-15');

        self::assertSame(['2026-11-09', '2026-11-13', '2026-11-04', '2026-11-08', 113, 70], [$bots['from'], $bots['to'], $bots['previousFrom'], $bots['previousTo'], $bots['requests'], $bots['previousRequests']]);
        self::assertSame([0, null, null], [$bots['allRequests'], $bots['share'], $bots['previousShare']], 'no daily totals in this test');
        self::assertSame([
            TrafficReport::CATEGORY_AI,
            TrafficReport::CATEGORY_SEARCH,
            TrafficReport::CATEGORY_UNKNOWN,
            TrafficReport::CATEGORY_DISGUISED,
        ], array_column($bots['categories'], 'key'));
        self::assertSame([
            'key' => TrafficReport::CATEGORY_AI,
            'hits' => 55,
            'previous' => 20,
            'bots' => [
                [
                    'key' => 'gptbot',
                    'hits' => 30,
                    'previous' => 5,
                ],
                [
                    'key' => 'claudebot',
                    'hits' => 25,
                    'previous' => 0,
                ],
            ],
        ], $bots['categories'][0]);
        self::assertSame([['generic', 4], ['somethingnew', 1]], array_map(static fn (array $bot): array => [$bot['key'], $bot['hits']], $bots['categories'][2]['bots']));
    }

    /**
     * Bots of all requests (page views, broken links and bots), as the
     * headline counts them.
     */
    public function testBotShareOfAllRequests(): void
    {
        $daily = $this->pipeline->database->tables->daily();
        $connection = $this->pipeline->database->connection;
        $connection->insert($daily, ['day' => '2026-11-10', 'pageviews' => 60, 'not_found' => 15, 'bots' => 25]);
        $connection->insert($daily, ['day' => '2026-11-05', 'pageviews' => 90, 'not_found' => 0, 'bots' => 10]);

        $bots = $this->pipeline->trafficReport()
            ->bots('2026-11-09', '2026-11-15');

        self::assertSame([100, 25.0, 10.0], [$bots['allRequests'], $bots['share'], $bots['previousShare']]);
        self::assertNull($this->pipeline->trafficReport()->bots('2026-11-09', '2026-11-15', compare: false)['previousShare']);
    }

    public function testBotsWithoutComparison(): void
    {
        $this->pipeline->bots('2026-11-10', 'googlebot', 4);

        $bots = $this->pipeline->trafficReport()
            ->bots('2026-11-09', '2026-11-15', compare: false);

        self::assertSame([null, null, null], [$bots['previousRequests'], $bots['categories'][0]['previous'], $bots['categories'][0]['bots'][0]['previous']]);
    }

    /**
     * Summed by weekday (Monday first) and hour, with how many of each weekday
     * the range has up to today, for an average day.
     */
    public function testPageViewsByWeekdayAndHour(): void
    {
        // Monday 9 and 10 November, Friday 13 November.
        $this->pipeline->hourly('2026-11-09', 8, 4);
        $this->pipeline->hourly('2026-11-09', 20, 1);
        $this->pipeline->hourly('2026-11-13', 8, 6);
        $this->pipeline->hourly('2026-11-02', 8, 6);
        $this->pipeline->hourly('2026-11-16', 8, 100);

        $hours = $this->pipeline->trafficReport()
            ->hours('2026-11-02', '2026-11-30');

        self::assertSame(['2026-11-02', '2026-11-13', 17, 10], [$hours['from'], $hours['to'], $hours['total'], $hours['max']]);
        self::assertSame([2, 2, 2, 2, 2, 1, 1], $hours['days']);
        self::assertSame(4 + 6, $hours['cells'][0][8]);
        self::assertSame(1, $hours['cells'][0][20]);
        self::assertSame(6, $hours['cells'][4][8]);
        self::assertCount(7, $hours['cells']);
        self::assertCount(24, $hours['cells'][6]);
        self::assertNotSame('', $hours['timezone']);
    }
}
