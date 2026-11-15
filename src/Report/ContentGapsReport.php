<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Report;

use TomVondracek\RequestAnalytics\Collector\Normalizer;
use TomVondracek\RequestAnalytics\Query\ContentGapsQuery;
use TomVondracek\RequestAnalytics\Query\DayRange;

/**
 * Broken links and site search: what visitors wanted and did not find.
 *
 * - A missing page linked from a page of this site is listed whatever its
 *   count: the address comes from the site's own markup, and the fix is to
 *   change that link. One known only from other sites or typed in is listed
 *   from `report_threshold_k` requests on, since a typed address can hold
 *   anything; the rest is a count.
 * - Search terms are stored redacted; terms below the threshold are a count.
 *
 * @phpstan-type BrokenLink array{path: string, hits: int, previous: ?int, internal: list<array{page: string, title: ?string, hits: int}>, external: list<array{host: string, hits: int}>, typed: int}
 */
final readonly class ContentGapsReport
{
    public const PER_PAGE = 20;
    public const OTHER = '(other)';

    public function __construct(
        private ContentGapsQuery $query,
        private PageCatalogInterface $catalog,
        private SummaryReport $summary,
    ) {
    }

    /**
     * Missing pages, those with a link on this site first, then by requests.
     *
     * @return array{from: string, to: string, previousFrom: ?string, previousTo: ?string, requests: int, previousRequests: ?int, page: int, pages: int, perPage: int, total: int, hidden: int, max: int, rows: list<BrokenLink>}
     */
    public function brokenLinks(string $from, string $to, int $threshold, int $page = 1, ?string $compareFrom = null, ?string $compareTo = null, bool $compare = true): array
    {
        [$range, $previous] = $this->summary->ranges($from, $to, $compareFrom, $compareTo, $compare);

        $before = [];
        foreach ($previous instanceof DayRange ? $this->query->notFound($previous) : [] as $row) {
            $before[$row['path']] = ($before[$row['path']] ?? 0) + $row['hits'];
        }

        $titles = $this->catalog->pages();
        $paths = [];
        foreach ($this->query->notFound($range) as $row) {
            $path = $row['path'];
            $paths[$path] ??= [
                'path' => $path,
                'hits' => 0,
                'previous' => $previous instanceof DayRange ? $before[$path] ?? 0 : null,
                'internal' => [],
                'external' => [],
                'typed' => 0,
            ];
            $paths[$path]['hits'] += $row['hits'];
            if (str_starts_with($row['ref'], '/')) {
                $paths[$path]['internal'][$row['ref']] = ($paths[$path]['internal'][$row['ref']] ?? 0) + $row['hits'];
            } elseif ($row['ref'] !== '') {
                $paths[$path]['external'][$row['ref']] = ($paths[$path]['external'][$row['ref']] ?? 0) + $row['hits'];
            } else {
                $paths[$path]['typed'] += $row['hits'];
            }
        }

        $requests = array_sum(array_column($paths, 'hits'));
        $rows = [];
        $hidden = 0;
        foreach ($paths as $path) {
            if ($path['internal'] === [] && $path['hits'] < $threshold) {
                ++$hidden;

                continue;
            }

            $rows[] = [
                'path' => $path['path'],
                'hits' => $path['hits'],
                'previous' => $path['previous'],
                'internal' => self::pagesOf($path['internal'], $titles),
                'external' => self::hostsOf($path['external'], $threshold),
                'typed' => $path['typed'],
            ];
        }

        // Most actionable first: a link of the site itself can be fixed right away.
        usort($rows, static fn (array $a, array $b): int => [$b['internal'] !== [], $b['hits'], $a['path']] <=> [$a['internal'] !== [], $a['hits'], $b['path']]);

        $pages = max(1, (int) ceil(count($rows) / self::PER_PAGE));
        $page = min(max(1, $page), $pages);

        return [
            'from' => $range->from,
            'to' => $range->to,
            'previousFrom' => $previous?->from,
            'previousTo' => $previous?->to,
            'requests' => $requests,
            'previousRequests' => $previous instanceof DayRange ? array_sum($before) : null,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
            'total' => count($rows),
            'hidden' => $hidden,
            'max' => $rows === [] ? 0 : max(array_column($rows, 'hits')),
            'rows' => array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
        ];
    }

    /**
     * Search terms, most searched first.
     *
     * @return array{from: string, to: string, previousFrom: ?string, previousTo: ?string, searches: int, page: int, pages: int, perPage: int, total: int, hidden: int, max: int, rows: list<array{term: string, searches: int, previous: ?int, redacted: bool}>}
     */
    public function searches(string $from, string $to, int $threshold, int $page = 1, ?string $compareFrom = null, ?string $compareTo = null, bool $compare = true): array
    {
        [$range, $previous] = $this->summary->ranges($from, $to, $compareFrom, $compareTo, $compare);

        $before = [];
        foreach ($previous instanceof DayRange ? $this->query->searches($previous) : [] as $row) {
            $before[$row['term']] = ($before[$row['term']] ?? 0) + $row['hits'];
        }

        $searches = 0;
        $rows = [];
        $hidden = 0;
        foreach ($this->query->searches($range) as $row) {
            $searches += $row['hits'];
            if ($row['hits'] < $threshold) {
                ++$hidden;

                continue;
            }

            $rows[] = [
                'term' => $row['term'],
                'searches' => $row['hits'],
                'previous' => $previous instanceof DayRange ? $before[$row['term']] ?? 0 : null,
                'redacted' => str_contains($row['term'], Normalizer::REDACTED),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['searches'], $a['term']] <=> [$a['searches'], $b['term']]);
        $pages = max(1, (int) ceil(count($rows) / self::PER_PAGE));
        $page = min(max(1, $page), $pages);

        return [
            'from' => $range->from,
            'to' => $range->to,
            'previousFrom' => $previous?->from,
            'previousTo' => $previous?->to,
            'searches' => $searches,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
            'total' => count($rows),
            'hidden' => $hidden,
            'max' => $rows === [] ? 0 : max(array_column($rows, 'searches')),
            'rows' => array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
        ];
    }

    /**
     * @param array<string, int> $pages path => requests
     * @param array<string, string> $titles
     *
     * @return list<array{page: string, title: ?string, hits: int}>
     */
    private static function pagesOf(array $pages, array $titles): array
    {
        arsort($pages);
        $list = [];
        foreach ($pages as $page => $hits) {
            $list[] = [
                'page' => (string) $page,
                'title' => $titles[$page] ?? null,
                'hits' => $hits,
            ];
        }

        return $list;
    }

    /**
     * @param array<string, int> $hosts host => requests
     *
     * @return list<array{host: string, hits: int}>
     */
    private static function hostsOf(array $hosts, int $threshold): array
    {
        arsort($hosts);
        $list = [];
        $other = 0;
        foreach ($hosts as $host => $hits) {
            if ($hits < $threshold) {
                $other += $hits;

                continue;
            }

            $list[] = [
                'host' => (string) $host,
                'hits' => $hits,
            ];
        }

        if ($other > 0) {
            $list[] = [
                'host' => self::OTHER,
                'hits' => $other,
            ];
        }

        return $list;
    }
}
