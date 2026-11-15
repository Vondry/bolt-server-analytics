<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Insight\Rule;

use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Collector\Channel;
use TomVondracek\RequestAnalytics\Insight\Insight;
use TomVondracek\RequestAnalytics\Insight\InsightContext;
use TomVondracek\RequestAnalytics\Insight\Rule\DataTrustRule;
use TomVondracek\RequestAnalytics\Query\DayRange;
use TomVondracek\RequestAnalytics\Tests\Support\Insights;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryInsightData;

final class DataTrustRuleTest extends TestCase
{
    public function testADayWithoutPageViewsButWithBotsPointsAtACache(): void
    {
        $data = self::steady(100);
        $data->daily['2026-11-04'] = [
            'pageviews' => 0,
            'visits' => 0,
            'bots' => 30,
        ];

        $insights = (new DataTrustRule())->evaluate(Insights::context($data));

        self::assertSame(['tracking-gap'], Insights::ids($insights));
        self::assertSame([Insight::SECTION_HEADLINE, Insight::KIND_SETUP, 'ra-trend', [InsightContext::DATA_COUNTS]], [$insights[0]->section, $insights[0]->kind, $insights[0]->target(), $insights[0]->suppresses]);
        self::assertSame('Bolt recorded no page views on Nov 4, but bots came: a cache or CDN probably answers without Bolt, or collection is off. → Check that page requests reach Bolt, and that the extension is enabled.', Insights::text($insights[0]));
    }

    public function testADayWithoutAnyRequestPointsAtTheSiteOrTheRollup(): void
    {
        $data = self::steady(100);
        unset($data->daily['2026-10-20'], $data->daily['2026-11-04']);

        $insights = (new DataTrustRule())->evaluate(Insights::context($data));

        self::assertSame('No requests at all on Nov 4: the site was probably down, or the rollup did not run. → Check the server log of that day, and that the rollup runs.', Insights::text($insights[0]), 'the latest gap');
        self::assertSame(1, $insights[0]->more);
    }

    /**
     * Days deleted on the Analytics page are empty on purpose: no gap.
     */
    public function testDeletedDaysAreNoGap(): void
    {
        $data = self::steady(100);
        foreach (DayRange::of('2026-11-06', '2026-11-12')->days() as $day) {
            unset($data->daily[$day]);
        }
        $data->erased = [[
            'from' => '2026-11-06',
            'to' => '2026-11-13',
        ]];

        self::assertSame([], (new DataTrustRule())->evaluate(Insights::context($data)));
    }

    /**
     * The days before a gap tell whether the site is busy enough to notice
     * it; deleted ones say nothing about that.
     */
    public function testAGapAfterDeletedDaysIsMeasuredAgainstTheDaysNotDeleted(): void
    {
        $data = self::steady(100);
        foreach ([...DayRange::of('2026-10-25', '2026-11-02')->days(), '2026-11-04'] as $day) {
            unset($data->daily[$day]);
        }
        $data->erased = [[
            'from' => '2026-10-25',
            'to' => '2026-11-02',
        ]];

        $insights = (new DataTrustRule())->evaluate(Insights::context($data));

        self::assertSame('No requests at all on Nov 4: the site was probably down, or the rollup did not run. → Check the server log of that day, and that the rollup runs.', Insights::text($insights[0]), '5 days of 100 page views and 9 deleted before it');
        self::assertSame(0, $insights[0]->more);
    }

    /**
     * A quiet site has days without page views; that is no gap.
     */
    public function testNoGapBelowAMedianOf20(): void
    {
        $data = self::steady(19);
        $data->daily['2026-11-04']['pageviews'] = 0;

        self::assertSame([], (new DataTrustRule())->evaluate(Insights::context($data)));
    }

    public function testOwnSiteCountedAsReferral(): void
    {
        $data = self::steady(100);
        $data->sources[] = self::source('2026-11-01', Channel::REFERRAL, 'example.cz', 310);
        $data->sources[] = self::source('2026-11-01', Channel::REFERRAL, 'partner.cz', 900);

        $insights = (new DataTrustRule())->evaluate(Insights::context($data, ownHosts: ['example.cz']));

        self::assertSame(['own-referral'], Insights::ids($insights));
        self::assertSame([InsightContext::DATA_REFERRERS], $insights[0]->suppresses);
        self::assertSame('example.cz is counted as a referral (310 visits): moves between pages of this site probably count as new visits. → Add example.cz, www.example.cz to own_hosts in the config of Request Analytics.', Insights::text($insights[0]));
    }

    public function testOwnSiteBelowThePrivacyThresholdIsNotNamed(): void
    {
        $data = self::steady(100);
        $data->sources[] = self::source('2026-11-01', Channel::REFERRAL, 'example.cz', 4);

        self::assertSame([], (new DataTrustRule())->evaluate(Insights::context($data, k: 5, ownHosts: ['example.cz'])));
    }

    public function testHiddenReferrersMakeEveryClickAVisit(): void
    {
        // 90 days × 10 visits, 1.0 page per visit, a third without a referrer.
        $data = self::steady(10, 10, Insights::structural());
        $data->sources[] = self::source('2026-10-01', Channel::UNKNOWN, '', 300);
        $data->sources[] = self::source('2026-10-01', Channel::ORGANIC_SEARCH, 'google', 600);

        $insights = (new DataTrustRule())->evaluate(Insights::context($data));

        self::assertSame(['hidden-referrers'], Insights::ids($insights));
        self::assertSame(InsightContext::STRUCTURAL_DAYS, $insights[0]->days);
        self::assertSame('33% of visits arrive without a referrer, and a visit opens only 1.0 pages: clicks between pages probably arrive without one too, so each counts as a new visit. → Send the header Referrer-Policy: strict-origin-when-cross-origin.', Insights::text($insights[0]));
    }

    public function testHiddenReferrersNeedEveryThreshold(): void
    {
        $rule = new DataTrustRule();

        $few = self::steady(10, 10, Insights::structural());
        $few->sources[] = self::source('2026-10-01', Channel::UNKNOWN, '', 269);
        self::assertSame([], $rule->evaluate(Insights::context($few)), 'below 30 %');

        $deep = self::steady(11, 10, Insights::structural());
        $deep->sources[] = self::source('2026-10-01', Channel::UNKNOWN, '', 300);
        self::assertSame([], $rule->evaluate(Insights::context($deep)), '1.1 pages per visit');

        $small = self::steady(3, 3, Insights::structural());
        $small->sources[] = self::source('2026-10-01', Channel::UNKNOWN, '', 270);
        self::assertSame([], $rule->evaluate(Insights::context($small)), 'below 300 visits');
    }

    public function testNothingUntilTheStatisticsCoverTheWindow(): void
    {
        $data = self::steady(100);
        $data->daily['2026-11-04']['pageviews'] = 0;
        $data->firstDay = '2026-10-17';

        self::assertSame([], (new DataTrustRule())->evaluate(Insights::context($data)));
    }

    private static function steady(int $pageviews, ?int $visits = null, ?DayRange $range = null): MemoryInsightData
    {
        return (new MemoryInsightData())->everyDay($range ?? DayRange::of(Insights::BEFORE_FROM, Insights::LAST_DAY), $pageviews, $visits ?? intdiv($pageviews, 2), 5);
    }

    /**
     * @return array{day: string, channel: string, source: string, path: string, visits: int}
     */
    private static function source(string $day, string $channel, string $source, int $visits, string $path = '/'): array
    {
        return [
            'day' => $day,
            'channel' => $channel,
            'source' => $source,
            'path' => $path,
            'visits' => $visits,
        ];
    }
}
