<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Support;

use Throwable;
use TomVondracek\RequestAnalytics\Insight\InsightData;
use TomVondracek\RequestAnalytics\Query\DayRange;

/**
 * Aggregates of a site in memory, as the tables would hold them: rows with
 * their day, summed per range when read. Counts every read.
 */
final class MemoryInsightData implements InsightData
{
    public ?string $lastRollupAt = '2026-11-13 08:00:00';

    /** Thrown by every read, e.g. a database that cannot be reached. */
    public ?Throwable $failure = null;

    /** First day of the statistics; null = the first day of $daily. */
    public ?string $firstDay = '2025-01-01';

    /** @var list<array{from: string, to: string}> periods deleted on purpose */
    public array $erased = [];

    /** @var array<string, array{pageviews: int, visits: int, bots: int}> */
    public array $daily = [];

    /** @var list<array{day: string, channel: string, source: string, path: string, visits: int}> */
    public array $sources = [];

    /** @var list<array{day: string, path: string, views: int, entries: int}> */
    public array $pages = [];

    /** @var list<array{day: string, lang: string, views: int, entries: int}> */
    public array $languages = [];

    /** @var list<array{day: string, device: string, browser: string, views: int, entries: int}> */
    public array $clients = [];

    /** @var list<array{day: string, from: string, to: string, hits: int}> */
    public array $moves = [];

    /** @var list<array{day: string, path: string, ref: string, hits: int}> */
    public array $notFound = [];

    /** @var list<array{day: string, term: string, hits: int}> */
    public array $searches = [];

    /** @var array<string, int> method => reads */
    public array $reads = [];

    /**
     * The same totals on every day of a range.
     */
    public function everyDay(DayRange $range, int $pageviews, int $visits, int $bots = 0): self
    {
        foreach ($range->days() as $day) {
            $this->daily[$day] = [
                'pageviews' => $pageviews,
                'visits' => $visits,
                'bots' => $bots,
            ];
        }

        return $this;
    }

    public function lastRollupAt(): ?string
    {
        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        return $this->lastRollupAt;
    }

    public function firstDay(): ?string
    {
        $this->read(__FUNCTION__);
        if ($this->firstDay !== null) {
            return $this->firstDay;
        }

        $days = array_keys($this->daily);
        sort($days);

        return $days[0] ?? null;
    }

    public function erased(): array
    {
        $this->read(__FUNCTION__);

        return $this->erased;
    }

    public function daily(DayRange $range): array
    {
        $this->read(__FUNCTION__);

        return array_filter($this->daily, static fn (string $day): bool => $day >= $range->from && $day <= $range->to, ARRAY_FILTER_USE_KEY);
    }

    public function sources(DayRange $range): array
    {
        $this->read(__FUNCTION__);
        $sums = [];
        foreach (self::in($this->sources, $range) as $row) {
            $key = $row['channel'] . "\0" . $row['source'] . "\0" . $row['path'];
            $sums[$key] ??= [
                'channel' => $row['channel'],
                'source' => $row['source'],
                'path' => $row['path'],
                'visits' => 0,
            ];
            $sums[$key]['visits'] += $row['visits'];
        }

        return array_values($sums);
    }

    public function pages(DayRange $range): array
    {
        $this->read(__FUNCTION__);
        $sums = [];
        foreach (self::in($this->pages, $range) as $row) {
            $sums[$row['path']]['views'] = ($sums[$row['path']]['views'] ?? 0) + $row['views'];
            $sums[$row['path']]['entries'] = ($sums[$row['path']]['entries'] ?? 0) + $row['entries'];
        }

        return $sums;
    }

    public function languages(DayRange $range): array
    {
        $this->read(__FUNCTION__);
        $sums = [];
        foreach (self::in($this->languages, $range) as $row) {
            $sums[$row['lang']]['views'] = ($sums[$row['lang']]['views'] ?? 0) + $row['views'];
            $sums[$row['lang']]['entries'] = ($sums[$row['lang']]['entries'] ?? 0) + $row['entries'];
        }

        return $sums;
    }

    public function clients(DayRange $range): array
    {
        $this->read(__FUNCTION__);

        return self::in($this->clients, $range);
    }

    public function movesInto(DayRange $range, string $prefix): array
    {
        $this->read(__FUNCTION__);
        $sums = [];
        foreach (self::in($this->moves, $range) as $row) {
            if ($row['to'] === $prefix || str_starts_with($row['to'], $prefix . '/')) {
                $key = $row['from'] . "\0" . $row['to'];
                $sums[$key] ??= [
                    'from' => $row['from'],
                    'to' => $row['to'],
                    'hits' => 0,
                ];
                $sums[$key]['hits'] += $row['hits'];
            }
        }

        return array_values($sums);
    }

    public function notFound(DayRange $range): array
    {
        $this->read(__FUNCTION__);
        $sums = [];
        foreach (self::in($this->notFound, $range) as $row) {
            $key = $row['path'] . "\0" . $row['ref'];
            $sums[$key] ??= [
                'path' => $row['path'],
                'ref' => $row['ref'],
                'hits' => 0,
            ];
            $sums[$key]['hits'] += $row['hits'];
        }

        return array_values($sums);
    }

    public function searches(DayRange $range): array
    {
        $this->read(__FUNCTION__);
        $sums = [];
        foreach (self::in($this->searches, $range) as $row) {
            $sums[$row['term']] = ($sums[$row['term']] ?? 0) + $row['hits'];
        }

        return array_map(static fn (string $term, int $hits): array => [
            'term' => $term,
            'hits' => $hits,
        ], array_map(strval(...), array_keys($sums)), array_values($sums));
    }

    /**
     * @template T of array{day: string}
     *
     * @param list<T> $rows
     *
     * @return list<T>
     */
    private static function in(array $rows, DayRange $range): array
    {
        return array_values(array_filter($rows, static fn (array $row): bool => $row['day'] >= $range->from && $row['day'] <= $range->to));
    }

    private function read(string $method): void
    {
        $this->reads[$method] = ($this->reads[$method] ?? 0) + 1;
    }
}
