<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Report;

use TomVondracek\RequestAnalytics\Query\DayRange;
use TomVondracek\RequestAnalytics\Query\SummaryQuery;
use TomVondracek\RequestAnalytics\SiteTime;

/**
 * The headline of the analytics page: the totals of the days the trend chart
 * shows, up to today, and of the days it compares them with.
 *
 * @phpstan-type Totals array{pageviews: int, visits: int, notFound: int, bots: int}
 */
final readonly class SummaryReport
{
    public function __construct(
        private SummaryQuery $query,
        private SiteTime $siteTime,
    ) {
    }

    /**
     * @param string|null $compareFrom first day compared with, as the trend chart compares; null = as many days before
     * @param bool $compare false = no comparison
     *
     * @return array{from: string, to: string, previousFrom: ?string, previousTo: ?string, current: Totals, previous: ?Totals}
     */
    public function summary(string $from, string $to, ?string $compareFrom = null, ?string $compareTo = null, bool $compare = true): array
    {
        [$range, $previous] = $this->ranges($from, $to, $compareFrom, $compareTo, $compare);

        return [
            'from' => $range->from,
            'to' => $range->to,
            'previousFrom' => $previous?->from,
            'previousTo' => $previous?->to,
            'current' => $this->query->totals($range),
            'previous' => $previous instanceof DayRange ? $this->query->totals($previous) : null,
        ];
    }

    /**
     * Page views, visits, broken links and bots of a range.
     *
     * @return Totals
     */
    public function totals(DayRange $range): array
    {
        return $this->query->totals($range);
    }

    /**
     * The days of a report up to today, and the days they are compared with:
     * those the trend chart compares with when it says so (previous period or
     * last year), else as many days right before; none without a comparison.
     *
     * @return array{DayRange, ?DayRange}
     */
    public function ranges(string $from, string $to, ?string $compareFrom = null, ?string $compareTo = null, bool $compare = true): array
    {
        $range = $this->comparable(DayRange::of($from, $to));
        if (! $compare) {
            return [$range, null];
        }

        if ($compareFrom !== null && $compareTo !== null) {
            return [$range, DayRange::of($compareFrom, $compareTo)];
        }

        return [$range, $range->previous()];
    }

    /**
     * A range cut at today, so it can be compared with the one before.
     */
    public function comparable(DayRange $range): DayRange
    {
        return $range->until($this->siteTime->now()->format('Y-m-d'));
    }
}
