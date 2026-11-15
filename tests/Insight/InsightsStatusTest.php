<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Insight;

use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Insight\InsightsStatus;
use TomVondracek\RequestAnalytics\Tests\Support\Insights;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryInsightData;

/**
 * When no insight fires: waiting for statistics, too few visits, or nothing
 * to act on. The last complete day is 12 Nov 2026.
 */
final class InsightsStatusTest extends TestCase
{
    public function testWithoutStatisticsItWaits(): void
    {
        $data = new MemoryInsightData();
        $data->firstDay = null;

        $status = InsightsStatus::of(Insights::context($data));

        self::assertSame(InsightsStatus::WAITING, $status->state);
        self::assertSame('No statistics yet. Insights need 28 complete days of them and then appear here on their own.', Insights::plain($status->message));
    }

    public function testBefore28DaysItSaysWhenTheFirstCanAppear(): void
    {
        $data = new MemoryInsightData();
        $data->firstDay = '2026-11-03';
        $data->everyDay(Insights::recent(), 10_000, 5_000);

        $status = InsightsStatus::of(Insights::context($data));

        self::assertSame(InsightsStatus::WAITING, $status->state, 'however many visits');
        self::assertSame('Insights need 28 complete days of statistics (so far: 10). The first ones can appear on Dec 1.', Insights::plain($status->message));
    }

    public function testTooFewVisitsAreNotAllClear(): void
    {
        $data = new MemoryInsightData();
        $data->everyDay(Insights::structural(), 5, 3);

        $status = InsightsStatus::of(Insights::context($data));

        self::assertSame(InsightsStatus::QUIET, $status->state);
        self::assertSame('Too few visits to tell a real problem from chance (last 90 days: 270). Insights appear as the site gets more visits.', Insights::plain($status->message));
    }

    public function testThePrivacyThresholdRaisesTheVisitsNeeded(): void
    {
        $data = new MemoryInsightData();
        $data->everyDay(Insights::structural(), 100, 50);

        self::assertSame(InsightsStatus::CLEAR, InsightsStatus::of(Insights::context($data))->state);
        self::assertSame(InsightsStatus::QUIET, InsightsStatus::of(Insights::context($data, k: 5_000))->state, '4 500 visits');
    }

    public function testBefore90DaysTheLongerChecksWait(): void
    {
        $data = new MemoryInsightData();
        $data->firstDay = '2026-09-01';
        $data->everyDay(Insights::recent(), 100, 50);

        $status = InsightsStatus::of(Insights::context($data));

        self::assertSame(InsightsStatus::PARTIAL, $status->state);
        self::assertSame('Nothing needs attention so far. Checks that compare longer periods start once there are 90 days of statistics, on Nov 30.', Insights::plain($status->message));
    }

    public function testEnoughDataAndNothingFound(): void
    {
        $data = new MemoryInsightData();
        $data->everyDay(Insights::structural(), 100, 50);

        $status = InsightsStatus::of(Insights::context($data));

        self::assertSame(InsightsStatus::CLEAR, $status->state);
        self::assertSame('Nothing needs attention. Broken links, site search, languages, visits and browsers of the last 90 days look fine.', Insights::plain($status->message));
    }
}
