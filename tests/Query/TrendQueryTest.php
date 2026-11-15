<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;
use TomVondracek\RequestAnalytics\Tests\Support\Project;

/**
 * "Today" is Friday 2026-11-13 (ISO week 46) in these tests.
 */
#[Group('database')]
final class TrendQueryTest extends TestCase
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

    public function test30DaysByDayComparedWithThe30Before(): void
    {
        $this->pipeline->daily([
            '2026-11-13' => 7,
            '2026-11-12' => 5,
            '2026-10-15' => 3,
            // The comparison period: 2026-09-15 … 2026-10-14.
            '2026-10-14' => 4,
            '2026-10-13' => 2,
            '2026-09-15' => 1,
            // Out of both ranges.
            '2026-09-14' => 100,
        ]);

        $trend = $this->pipeline->trendQuery()
            ->trend('30d', 'pageviews', 'previous');

        self::assertSame(['30d', 'day', ['day', 'week'], 'pageviews', 'previous'], [$trend['range'], $trend['interval'], $trend['intervals'], $trend['metric'], $trend['compare']]);
        self::assertCount(30, $trend['buckets']);
        self::assertSame([
            'key' => '2026-10-15',
            'from' => '2026-10-15',
            'to' => '2026-10-15',
            'value' => 3,
            'compareValue' => 1,
            'incomplete' => false,
            'partial' => false,
        ], $trend['buckets'][0]);
        self::assertSame([
            'key' => '2026-11-13',
            'from' => '2026-11-13',
            'to' => '2026-11-13',
            'value' => 7,
            'compareValue' => 4,
            'incomplete' => true,
            'partial' => false,
        ], $trend['buckets'][29]);
        self::assertSame([5, 2], [$trend['buckets'][28]['value'], $trend['buckets'][28]['compareValue']]);
        self::assertSame(0, $trend['buckets'][1]['value'], 'days without a row count as zero');
        self::assertSame([
            'value' => 15,
            'compareValue' => 7,
            'deltaPct' => 114.3,
        ], $trend['total']);
        self::assertSame([
            'from' => '2026-10-15',
            'to' => '2026-11-13',
            'compareFrom' => '2026-09-15',
            'compareTo' => '2026-10-14',
        ], $trend['period']);
    }

    /**
     * @return iterable<string, array{string, string, string, list<string>, string}>
     */
    public static function presets(): iterable
    {
        yield 'last 7 days' => ['7d', '2026-11-07', 'day', ['day'], '2026-10-31'];
        yield 'last 30 days' => ['30d', '2026-10-15', 'day', ['day', 'week'], '2026-09-15'];
        yield 'last 3 months' => ['3m', '2026-08-14', 'week', ['day', 'week', 'month'], '2026-05-14'];
        yield 'year to date' => ['ytd', '2026-01-01', 'month', ['week', 'month'], '2025-02-18'];
        yield 'last 12 months' => ['1y', '2025-11-14', 'month', ['week', 'month'], '2024-11-14'];
        yield 'last 3 years' => ['3y', '2023-11-14', 'month', ['month'], '2020-11-13'];
    }

    /**
     * @param list<string> $intervals
     */
    #[DataProvider('presets')]
    public function testPresetsEndToday(string $range, string $from, string $interval, array $intervals, string $compareFrom): void
    {
        $trend = $this->pipeline->trendQuery()
            ->trend($range, 'pageviews', 'previous');

        self::assertSame([$from, '2026-11-13'], [$trend['period']['from'], $trend['period']['to']]);
        self::assertSame([$interval, $intervals], [$trend['interval'], $trend['intervals']]);
        self::assertSame($compareFrom, $trend['period']['compareFrom'], 'as many days right before');
        self::assertSame($trend['buckets'][0]['from'], $from);
        self::assertSame('2026-11-13', $trend['buckets'][count($trend['buckets']) - 1]['to']);
    }

    public function testMonthsBackStayInShortMonths(): void
    {
        // 31 May − 3 months is 28 February, so the period starts on 1 March.
        $pipeline = new Pipeline(new Project('2026-05-31 12:00:00'), $this->pipeline->database);

        try {
            $trend = $pipeline->trendQuery()->trend('3m', 'pageviews', 'none');
        } finally {
            $pipeline->remove();
        }

        self::assertSame('2026-03-01', $trend['period']['from']);
    }

    /**
     * Weeks and months are cut by the start of the period, and compared with
     * as many days.
     */
    public function testWeeksAreIsoWeeksCutToThePeriod(): void
    {
        $this->pipeline->daily([
            // Week 46: Monday 9th … Friday 13th, today.
            '2026-11-09' => 10,
            '2026-11-13' => 5,
            // Week 45.
            '2026-11-02' => 6,
            // Friday 14 August starts the period, in week 33.
            '2026-08-14' => 2,
            '2026-08-16' => 1,
            // The comparison: 2026-05-14 … 2026-08-13, 92 days earlier.
            '2026-08-13' => 1,
            '2026-05-14' => 3,
            '2026-05-13' => 100,
        ]);

        $trend = $this->pipeline->trendQuery()
            ->trend('3m', 'pageviews', 'previous', 'week');

        self::assertCount(14, $trend['buckets']);
        self::assertSame([
            'key' => '2026-W33',
            'from' => '2026-08-14',
            'to' => '2026-08-16',
            'value' => 3,
            'compareValue' => 3,
            'incomplete' => false,
            'partial' => true,
        ], $trend['buckets'][0]);
        self::assertSame(['2026-W34', '2026-08-17', '2026-08-23', false], [$trend['buckets'][1]['key'], $trend['buckets'][1]['from'], $trend['buckets'][1]['to'], $trend['buckets'][1]['partial']]);
        self::assertSame([
            'key' => '2026-W46',
            'from' => '2026-11-09',
            'to' => '2026-11-13',
            'value' => 15,
            'compareValue' => 1,
            'incomplete' => true,
            'partial' => true,
        ], $trend['buckets'][13]);
        self::assertSame([24, 4, 500.0], array_values($trend['total']));
    }

    public function testMonthsAreCalendarMonthsCutToThePeriod(): void
    {
        $this->pipeline->daily([
            '2026-11-01' => 3,
            '2026-11-13' => 4,
            '2026-02-28' => 5,
            '2025-12-01' => 6,
            '2025-11-14' => 2,
            // A year earlier, date for date.
            '2025-02-28' => 1,
            '2024-11-14' => 9,
            '2024-11-13' => 100,
        ]);

        $trend = $this->pipeline->trendQuery()
            ->trend('1y', 'pageviews', 'year');

        self::assertCount(13, $trend['buckets']);
        self::assertSame(['2025-11', '2025-11-14', '2025-11-30', 2, 9, false, true], array_values($trend['buckets'][0]));
        self::assertSame(['2025-12', '2025-12-01', '2025-12-31', 6, 0, false, false], array_values($trend['buckets'][1]));
        self::assertSame(['2026-02', '2026-02-01', '2026-02-28', 5, 1, false, false], array_values($trend['buckets'][3]));
        self::assertSame(['2026-11', '2026-11-01', '2026-11-13', 7, 0, true, true], array_values($trend['buckets'][12]));
        self::assertSame([20, 10, 100.0], array_values($trend['total']));
        self::assertSame([
            'from' => '2025-11-14',
            'to' => '2026-11-13',
            'compareFrom' => '2024-11-14',
            'compareTo' => '2025-11-13',
        ], $trend['period']);
    }

    public function testYearToDateComparedWithTheSameDatesLastYear(): void
    {
        $this->pipeline->daily([
            '2026-01-01' => 4,
            '2026-11-13' => 6,
            '2025-01-01' => 2,
            '2025-11-13' => 3,
            '2025-11-14' => 100,
            '2024-12-31' => 100,
        ]);

        $trend = $this->pipeline->trendQuery()
            ->trend('ytd', 'pageviews', 'year');

        self::assertSame(['2025-01-01', '2025-11-13'], [$trend['period']['compareFrom'], $trend['period']['compareTo']]);
        self::assertSame([10, 5, 100.0], array_values($trend['total']));
        self::assertSame([4, 2], [$trend['buckets'][0]['value'], $trend['buckets'][0]['compareValue']]);
    }

    public function testShortPeriodsComparedWithTheSameWeekdaysLastYear(): void
    {
        $this->pipeline->daily([
            '2026-11-13' => 8,
            // 52 weeks earlier, also a Friday.
            '2025-11-14' => 6,
        ]);

        $trend = $this->pipeline->trendQuery()
            ->trend('7d', 'pageviews', 'year');

        self::assertSame([8, 6], [$trend['buckets'][6]['value'], $trend['buckets'][6]['compareValue']]);
        self::assertSame(['2025-11-08', '2025-11-14'], [$trend['period']['compareFrom'], $trend['period']['compareTo']]);
        self::assertSame(33.3, $trend['total']['deltaPct']);
    }

    public function testWeekKeyUsesTheIsoYear(): void
    {
        $trend = $this->pipeline->trendQuery()
            ->trend('custom', 'pageviews', 'none', 'week', '2026-12-14', '2027-01-03');

        self::assertSame(['2026-W53', '2026-12-28', '2027-01-03'], array_slice(array_values($trend['buckets'][2]), 0, 3));
    }

    public function testEntriesMetric(): void
    {
        $this->pipeline->daily([
            '2026-11-13' => [10, 4],
            '2026-11-12' => [10, 3],
        ]);

        $trend = $this->pipeline->trendQuery()
            ->trend('7d', 'entries', 'none');

        self::assertSame('entries', $trend['metric']);
        self::assertSame(7, $trend['total']['value']);
    }

    public function testWithoutComparison(): void
    {
        $this->pipeline->daily([
            '2026-11-13' => 7,
        ]);

        $trend = $this->pipeline->trendQuery()
            ->trend('3m', 'pageviews', 'none');

        self::assertNull($trend['buckets'][13]['compareValue']);
        self::assertSame([
            'value' => 7,
            'compareValue' => null,
            'deltaPct' => null,
        ], $trend['total']);
        self::assertSame([null, null], [$trend['period']['compareFrom'], $trend['period']['compareTo']], 'nothing for the other cards to compare with');
    }

    public function testNoChangeWithoutAnythingToCompareWith(): void
    {
        $this->pipeline->daily([
            '2026-11-13' => 7,
        ]);

        self::assertSame([
            'value' => 7,
            'compareValue' => 0,
            'deltaPct' => null,
        ], $this->pipeline->trendQuery()->trend('30d', 'pageviews', 'previous')['total']);
    }

    public function testNegativeChange(): void
    {
        $this->pipeline->daily([
            '2026-11-13' => 3,
            '2026-10-14' => 4,
        ]);

        self::assertSame(-25.0, $this->pipeline->trendQuery()->trend('30d', 'pageviews', 'previous')['total']['deltaPct']);
    }

    /**
     * Clicking a month bar shows that month by day.
     */
    public function testCustomPeriodOfAPastMonth(): void
    {
        $this->pipeline->daily([
            '2026-02-01' => 1,
            '2026-02-28' => 2,
            '2026-01-31' => 4,
            '2026-01-04' => 8,
            '2026-03-01' => 100,
        ]);

        $trend = $this->pipeline->trendQuery()
            ->trend('custom', 'pageviews', 'previous', null, '2026-02-01', '2026-02-28');

        self::assertSame(['custom', 'day'], [$trend['range'], $trend['interval']]);
        self::assertCount(28, $trend['buckets']);
        self::assertSame([1, 8], [$trend['buckets'][0]['value'], $trend['buckets'][0]['compareValue']], 'compared with the 28 days before');
        self::assertSame([2, 4], [$trend['buckets'][27]['value'], $trend['buckets'][27]['compareValue']]);
        self::assertFalse($trend['buckets'][27]['incomplete'], 'a past period is complete');
        self::assertSame([
            'from' => '2026-02-01',
            'to' => '2026-02-28',
            'compareFrom' => '2026-01-04',
            'compareTo' => '2026-01-31',
        ], $trend['period'], 'a past period whole');
        self::assertSame(12, $trend['total']['compareValue']);
    }

    /**
     * The week of today, clicked: the days after today are on the chart, but
     * neither the period nor its comparison count them.
     */
    public function testCustomPeriodReachingPastToday(): void
    {
        $trend = $this->pipeline->trendQuery()
            ->trend('custom', 'pageviews', 'previous', null, '2026-11-09', '2026-11-15');

        self::assertSame(['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15'], array_column($trend['buckets'], 'key'));
        self::assertSame([false, false, false, false, true, true, true], array_column($trend['buckets'], 'incomplete'));
        self::assertSame([
            'from' => '2026-11-09',
            'to' => '2026-11-13',
            'compareFrom' => '2026-11-02',
            'compareTo' => '2026-11-06',
        ], $trend['period']);
    }

    public function testTodayIsTheDayInTheSiteTimezone(): void
    {
        // 23:30 UTC is already the next day in Prague.
        $pipeline = new Pipeline(new Project('2026-11-13 23:30:00'), $this->pipeline->database);

        try {
            $trend = $pipeline->trendQuery()
                ->trend('7d', 'pageviews', 'none');
        } finally {
            $pipeline->remove();
        }

        self::assertSame('2026-11-14', $trend['buckets'][6]['key']);
    }

    /**
     * @param array{string, string, string, ?string, ?string, ?string} $arguments
     */
    #[DataProvider('invalidArguments')]
    public function testInvalidArguments(array $arguments, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->pipeline->trendQuery()
            ->trend(...$arguments);
    }

    /**
     * @return iterable<string, array{array{string, string, string, ?string, ?string, ?string}, string}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'range' => [['weeks', 'pageviews', 'none', null, null, null], '"range" must be one of 7d | 30d | 3m | ytd | 1y | 3y | custom, "weeks" given.'];
        yield 'metric' => [['30d', 'hits; DROP TABLE x', 'none', null, null, null], '"metric" must be one of pageviews | entries'];
        yield 'compare' => [['30d', 'pageviews', 'yesterday', null, null, null], '"compare" must be one of previous | year | none'];
        yield 'interval' => [['30d', 'pageviews', 'none', 'hour', null, null], '"interval" must be one of day | week, "hour" given.'];
        yield 'interval too fine' => [['3y', 'pageviews', 'none', 'day', null, null], '"interval" must be one of month, "day" given.'];
        yield 'custom without days' => [['custom', 'pageviews', 'none', null, '2026-11-01', null], '"from" and "to" are required for a custom range.'];
        yield 'to' => [['custom', 'pageviews', 'none', null, '2026-11-01', '13.11.2026'], '"to" must be a date such as 2026-11-13'];
        yield 'impossible date' => [['custom', 'pageviews', 'none', null, '2026-02-30', '2026-03-01'], '"from" must be a date'];
        yield 'from after to' => [['custom', 'pageviews', 'none', null, '2026-11-02', '2026-11-01'], '"from" must be at most 1096 days before "to".'];
        yield 'too long' => [['custom', 'pageviews', 'none', null, '2020-01-01', '2026-11-13'], '"from" must be at most 1096 days before "to".'];
    }

    public function testFromAndToAreIgnoredForAPreset(): void
    {
        self::assertCount(7, $this->pipeline->trendQuery()->trend('7d', 'pageviews', 'none', null, 'nonsense', 'nonsense')['buckets']);
    }
}
