<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Query;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TomVondracek\RequestAnalytics\Config\RequestAnalyticsConfig;
use TomVondracek\RequestAnalytics\SiteTime;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * The page-visit trend of the analytics page and the dashboard card: the daily
 * totals of a period, grouped into days, ISO weeks or calendar months, with the
 * same groups of the period it is compared with.
 *
 * A period is one of the presets of the page, like the range buttons of a stock
 * chart (the last 7 or 30 days, 3 months, the year to date, the last 12 months
 * or 3 years, always up to today), or any days (`custom`, e.g. one bar clicked).
 * How the chart groups its days is a separate choice (`interval`); only the
 * groupings that make sense for its length are offered, and the period, its
 * total and every other card of the page are the same whichever is chosen.
 *
 * A group cut by the start or end of the period holds only the days within it
 * (`partial`), so its comparison is cut the same way.
 *
 * Reads only `ra_daily` (one row per day); the grouping happens here, and days
 * without a row count as zero.
 *
 * @phpstan-type Bucket array{key: string, from: string, to: string, value: int, compareValue: ?int, incomplete: bool, partial: bool}
 * @phpstan-type Period array{from: string, to: string, compareFrom: ?string, compareTo: ?string}
 * @phpstan-type Trend array{range: string, interval: string, intervals: list<string>, metric: string, compare: string, buckets: list<Bucket>, total: array{value: int, compareValue: ?int, deltaPct: ?float}, period: Period}
 */
final readonly class TrendQuery
{
    public const RANGE_CUSTOM = 'custom';
    public const INTERVAL_DAY = 'day';
    public const INTERVAL_WEEK = 'week';
    public const INTERVAL_MONTH = 'month';
    public const INTERVALS = [self::INTERVAL_DAY, self::INTERVAL_WEEK, self::INTERVAL_MONTH];
    public const COMPARE_PREVIOUS = 'previous';
    public const COMPARE_YEAR = 'year';
    public const COMPARE_NONE = 'none';

    /**
     * Longest period shown by day, and shortest by month.
     */
    private const MAX_DAYS = 92;

    private const MIN_MONTHS_DAYS = 60;

    /**
     * Shortest and longest period shown by week.
     */
    private const MIN_WEEKS_DAYS = 14;

    private const MAX_WEEKS_DAYS = 400;

    /**
     * Longest custom period: three years and a leap day.
     */
    private const MAX_CUSTOM_DAYS = 1097;

    /**
     * 52 weeks: the same weekdays a year earlier, for periods up to MAX_DAYS.
     */
    private const YEAR_IN_DAYS = 364;

    public function __construct(
        #[Autowire(expression: AnalyticsDatabase::CONNECTION)]
        private Connection $connection,
        private TableNames $tables,
        private SiteTime $siteTime,
    ) {
    }

    /**
     * @param string $range one of RequestAnalyticsConfig::RANGES, or `custom` with $from and $to
     * @param string|null $interval how the chart groups the days; null = what suits the length of the period
     * @param string|null $from first day (Y-m-d) of a custom period
     * @param string|null $to last day (Y-m-d) of a custom period; may lie after today
     *
     * @return Trend
     *
     * @throws InvalidArgumentException for unknown values and invalid dates
     */
    public function trend(string $range, string $metric, string $compare, ?string $interval = null, ?string $from = null, ?string $to = null): array
    {
        self::assertOneOf('range', $range, [...RequestAnalyticsConfig::RANGES, self::RANGE_CUSTOM]);
        self::assertOneOf('metric', $metric, RequestAnalyticsConfig::METRICS);
        self::assertOneOf('compare', $compare, RequestAnalyticsConfig::COMPARISONS);

        $today = self::date($this->siteTime->now()->format('Y-m-d'), 'today');
        [$first, $last] = $range === self::RANGE_CUSTOM ? self::custom($from, $to) : self::preset($range, $today);
        $length = self::days($first, $last);

        $intervals = self::intervals($length);
        $interval ??= self::defaultInterval($length);
        self::assertOneOf('interval', $interval, $intervals);

        $ranges = self::buckets($interval, $first, $last);
        $shift = self::shift($compare, $length);

        // The days shown up to today, and the same days of the comparison.
        $until = min($last, max($today, $first));
        $compareFrom = $shift === null ? null : $shift($first);
        $compareTo = $shift === null ? null : $shift($until);

        $values = $this->values($metric, $compareFrom === null ? $first : min($first, $compareFrom), $last);

        $buckets = [];
        $total = 0;
        foreach ($ranges as [$bucketFrom, $bucketTo, $partial]) {
            $value = self::sum($values, $bucketFrom, $bucketTo);
            $total += $value;

            $buckets[] = [
                'key' => self::key($interval, $bucketFrom),
                'from' => $bucketFrom->format('Y-m-d'),
                'to' => $bucketTo->format('Y-m-d'),
                'value' => $value,
                'compareValue' => $shift === null ? null : self::sum($values, $shift($bucketFrom), $shift($bucketTo)),
                // The day is not over yet, so the value will still grow.
                'incomplete' => $bucketTo >= $today,
                'partial' => $partial,
            ];
        }

        // The total compares as many days as have passed: days after today
        // must not count against the period.
        $compareTotal = $compareFrom === null || $compareTo === null ? null : self::sum($values, $compareFrom, $compareTo);

        return [
            'range' => $range,
            'interval' => $interval,
            'intervals' => $intervals,
            'metric' => $metric,
            'compare' => $compare,
            'buckets' => $buckets,
            // Every card of the page shows and compares these days, so every change on the page agrees.
            'period' => [
                'from' => $first->format('Y-m-d'),
                'to' => $until->format('Y-m-d'),
                'compareFrom' => $compareFrom?->format('Y-m-d'),
                'compareTo' => $compareTo?->format('Y-m-d'),
            ],
            'total' => [
                'value' => $total,
                'compareValue' => $compareTotal,
                'deltaPct' => $compareTotal === null || $compareTotal === 0 ? null : round(($total - $compareTotal) / $compareTotal * 100, 1),
            ],
        ];
    }

    /**
     * The first and last day of a preset, up to today.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private static function preset(string $range, DateTimeImmutable $today): array
    {
        $first = match ($range) {
            '7d' => $today->modify('-6 days'),
            '30d' => $today->modify('-29 days'),
            '3m' => self::months($today, -3)->modify('+1 day'),
            'ytd' => $today->modify('first day of january this year'),
            '1y' => self::months($today, -12)->modify('+1 day'),
            default => self::months($today, -36)->modify('+1 day'),
        };

        return [$first, $today];
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private static function custom(?string $from, ?string $to): array
    {
        if ($from === null || $to === null) {
            throw new InvalidArgumentException('"from" and "to" are required for a custom range.');
        }

        $first = self::date($from, 'from');
        $last = self::date($to, 'to');
        if ($first > $last || self::days($first, $last) > self::MAX_CUSTOM_DAYS) {
            throw new InvalidArgumentException(sprintf('"from" must be at most %d days before "to".', self::MAX_CUSTOM_DAYS - 1));
        }

        return [$first, $last];
    }

    /**
     * The groupings that suit a period of $length days: not a handful of
     * months, nor hundreds of days.
     *
     * @return list<string>
     */
    private static function intervals(int $length): array
    {
        return array_values(array_filter([
            $length <= self::MAX_DAYS ? self::INTERVAL_DAY : null,
            $length >= self::MIN_WEEKS_DAYS && $length <= self::MAX_WEEKS_DAYS ? self::INTERVAL_WEEK : null,
            $length >= self::MIN_MONTHS_DAYS ? self::INTERVAL_MONTH : null,
        ]));
    }

    private static function defaultInterval(int $length): string
    {
        return match (true) {
            $length <= 31 => self::INTERVAL_DAY,
            $length <= 183 => self::INTERVAL_WEEK,
            default => self::INTERVAL_MONTH,
        };
    }

    /**
     * The days, ISO weeks or calendar months from $first to $last, those at
     * the ends cut to the period.
     *
     * @return non-empty-list<array{DateTimeImmutable, DateTimeImmutable, bool}> first and last day of every bucket, and whether it is cut; oldest first
     */
    private static function buckets(string $interval, DateTimeImmutable $first, DateTimeImmutable $last): array
    {
        $buckets = [];
        $start = $first;
        while ($start <= $last) {
            [$natural, $end] = match ($interval) {
                self::INTERVAL_DAY => [$start, $start],
                self::INTERVAL_WEEK => [
                    $start->modify(sprintf('-%d days', (int) $start->format('N') - 1)),
                    $start->modify(sprintf('+%d days', 7 - (int) $start->format('N'))),
                ],
                default => [$start->modify('first day of this month'), $start->modify('last day of this month')],
            };
            $partial = $natural < $start || $end > $last;
            $end = min($end, $last);
            $buckets[] = [$start, $end, $partial];
            $start = $end->modify('+1 day');
        }

        /** @var non-empty-list<array{DateTimeImmutable, DateTimeImmutable, bool}> $buckets the period has at least one day */
        return $buckets;
    }

    /**
     * What a day of the period is compared with.
     *
     * - `previous`: the day as many days before as the period is long, so
     *   the comparison ends the day before the period starts
     * - `year`: up to MAX_DAYS, the same weekday 52 weeks earlier (a Monday
     *   with a Monday); a longer period, the same date a year earlier
     *
     * @return (callable(DateTimeImmutable): DateTimeImmutable)|null
     */
    private static function shift(string $compare, int $length): ?callable
    {
        return match (true) {
            $compare === self::COMPARE_NONE => null,
            $compare === self::COMPARE_PREVIOUS => static fn (DateTimeImmutable $day): DateTimeImmutable => $day->modify(sprintf('-%d days', $length)),
            $length <= self::MAX_DAYS => static fn (DateTimeImmutable $day): DateTimeImmutable => $day->modify(sprintf('-%d days', self::YEAR_IN_DAYS)),
            default => static fn (DateTimeImmutable $day): DateTimeImmutable => self::months($day, -12),
        };
    }

    /**
     * The same day $months months later (or earlier), the last day of a
     * shorter month: 31 May − 3 months = 28 February, not 3 March.
     */
    private static function months(DateTimeImmutable $day, int $months): DateTimeImmutable
    {
        $month = $day->modify('first day of this month')->modify(sprintf('%+d months', $months));

        return $month->setDate((int) $month->format('Y'), (int) $month->format('n'), min((int) $day->format('j'), (int) $month->format('t')));
    }

    /**
     * Number of days from $first to $last, both included.
     */
    private static function days(DateTimeImmutable $first, DateTimeImmutable $last): int
    {
        return (int) $first->diff($last)->days + 1;
    }

    /**
     * @return array<string, int> day (Y-m-d) => value
     */
    private function values(string $metric, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        // The metric is one of two known column names, see trend().
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT day, %s AS value FROM %s WHERE day >= :from AND day <= :to', $metric, $this->tables->daily()),
            [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ]
        );

        $values = [];
        foreach ($rows as $row) {
            $day = $row['day'] ?? null;
            $value = $row['value'] ?? null;
            if (is_string($day) && is_numeric($value)) {
                $values[mb_substr($day, 0, 10)] = (int) $value;
            }
        }

        return $values;
    }

    /**
     * @param array<string, int> $values
     */
    private static function sum(array $values, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $sum = 0;
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            $sum += $values[$day->format('Y-m-d')] ?? 0;
        }

        return $sum;
    }

    private static function key(string $interval, DateTimeImmutable $from): string
    {
        return match ($interval) {
            self::INTERVAL_DAY => $from->format('Y-m-d'),
            self::INTERVAL_WEEK => $from->format('o-\WW'),
            default => $from->format('Y-m'),
        };
    }

    /**
     * A calendar day, as midnight UTC: days are compared and shifted as dates,
     * without daylight-saving steps.
     */
    private static function date(string $value, string $name): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException(sprintf('"%s" must be a date such as 2026-11-13, "%s" given.', $name, $value));
        }

        return $date;
    }

    /**
     * @param list<string> $allowed
     */
    private static function assertOneOf(string $name, string $value, array $allowed): void
    {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(sprintf('"%s" must be one of %s, "%s" given.', $name, implode(' | ', $allowed), $value));
        }
    }
}
