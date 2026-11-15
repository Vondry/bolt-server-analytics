<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Insight;

use TomVondracek\RequestAnalytics\Query\DayRange;

/**
 * The aggregates the insights are computed from. DatabaseInsightData reads
 * them from the tables; tests give them in memory.
 */
interface InsightData
{
    /**
     * When the rollup last ran: UTC, `Y-m-d H:i:s`; null before the first run.
     */
    public function lastRollupAt(): ?string;

    /**
     * The first day `ra_daily` has (Y-m-d); null without any.
     */
    public function firstDay(): ?string;

    /**
     * The periods whose statistics were deleted on purpose, oldest first.
     *
     * @return list<array{from: string, to: string}> Y-m-d
     */
    public function erased(): array;

    /**
     * Only days that have a row.
     *
     * @return array<string, array{pageviews: int, visits: int, bots: int}> day (Y-m-d) => totals
     */
    public function daily(DayRange $range): array;

    /**
     * @return list<array{channel: string, source: string, path: string, visits: int}> visits by channel, source and landing page
     */
    public function sources(DayRange $range): array;

    /**
     * @return array<string, array{views: int, entries: int}> stored path => page views, and those that began a visit
     */
    public function pages(DayRange $range): array;

    /**
     * @return array<string, array{views: int, entries: int}> language the browser asks for first (`und` unknown) => page views and visits
     */
    public function languages(DayRange $range): array;

    /**
     * @return list<array{day: string, device: string, browser: string, views: int, entries: int}>
     */
    public function clients(DayRange $range): array;

    /**
     * Moves within the site to `$prefix` or a path below it (`/de`, `/de/…`).
     *
     * @return list<array{from: string, to: string, hits: int}>
     */
    public function movesInto(DayRange $range, string $prefix): array;

    /**
     * @return list<array{path: string, ref: string, hits: int}> `ref`: a path of this site, a host, or '' (typed, bookmarked)
     */
    public function notFound(DayRange $range): array;

    /**
     * @return list<array{term: string, hits: int}>
     */
    public function searches(DayRange $range): array;
}
