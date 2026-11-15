<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Report;

use TomVondracek\RequestAnalytics\Query\DayRange;
use TomVondracek\RequestAnalytics\Query\SourcesQuery;

/**
 * Where the visits of a range of days came from: channels and sources,
 * campaigns (`utm_campaign`), and domain aliases and markers such as QR codes.
 * Always visits: a source belongs to the arrival, not to every page view.
 *
 * Totals of a channel, a campaign or an alias are exact: the site owner made
 * them. A referring site can be a small private page, and a landing page of a
 * source can single out a visit, so sources and landing pages below
 * `report_threshold_k` are counted as "other".
 *
 * @phpstan-type Landing list<array{path: string, title: ?string, visits: int}>
 * @phpstan-type Origin array{channel: string, source: string, visits: int}
 */
final readonly class SourcesReport
{
    public const OTHER = '(other)';
    public const PER_PAGE = 20;
    private const LANDING_PAGES = 3;
    private const MAX_CAMPAIGNS = 50;
    private const MAX_SEARCH = 100;

    public function __construct(
        private SourcesQuery $query,
        private PageCatalogInterface $catalog,
        private SummaryReport $summary,
    ) {
    }

    /**
     * Visits per channel, and a page of the list of sources.
     *
     * @param string|null $channel only the sources of this channel
     * @param string $search part of a source or channel; empty = all
     *
     * @return array{from: string, to: string, previousFrom: ?string, previousTo: ?string, visits: int, channels: list<array{channel: string, visits: int, previous: ?int}>, channel: ?string, search: string, page: int, pages: int, perPage: int, total: int, max: int, rows: list<array{channel: string, source: string, visits: int, previous: ?int, landing: Landing}>, campaigns: int, aliases: int}
     */
    public function sources(string $from, string $to, int $threshold, ?string $channel = null, string $search = '', int $page = 1, ?string $compareFrom = null, ?string $compareTo = null, bool $compare = true): array
    {
        // Up to today, compared with the days the trend chart compares with.
        [$range, $previous] = $this->summary->ranges($from, $to, $compareFrom, $compareTo, $compare);
        $search = mb_substr(mb_trim($search), 0, self::MAX_SEARCH);

        $channelsBefore = [];
        $sourcesBefore = [];
        foreach ($previous instanceof DayRange ? $this->query->sources($previous) : [] as $row) {
            $channelsBefore[$row['channel']] = ($channelsBefore[$row['channel']] ?? 0) + $row['visits'];
            $key = $row['channel'] . "\0" . $row['source'];
            $sourcesBefore[$key] = ($sourcesBefore[$key] ?? 0) + $row['visits'];
        }

        $channels = [];
        $sources = [];
        foreach ($this->query->sources($range) as $row) {
            $channels[$row['channel']] = ($channels[$row['channel']] ?? 0) + $row['visits'];
            $key = $row['channel'] . "\0" . $row['source'];
            $sources[$key] ??= [
                'channel' => $row['channel'],
                'source' => $row['source'],
                'visits' => 0,
                'previous' => $previous instanceof DayRange ? $sourcesBefore[$key] ?? 0 : null,
                'paths' => [],
            ];
            $sources[$key]['visits'] += $row['visits'];
            $sources[$key]['paths'][$row['path']] = ($sources[$key]['paths'][$row['path']] ?? 0) + $row['visits'];
        }

        $rows = [];
        foreach (self::foldSources($sources, $threshold) as $source) {
            $rows[] = [
                'channel' => $source['channel'],
                'source' => $source['source'],
                'visits' => $source['visits'],
                'previous' => $source['previous'],
                'landing' => $this->landing($source['paths'], $threshold),
            ];
        }

        $max = $rows === [] ? 0 : max(array_column($rows, 'visits'));
        $rows = array_values(array_filter($rows, static fn (array $row): bool => ($channel === null || $row['channel'] === $channel)
            && ($search === '' || mb_stripos($row['source'], $search) !== false || mb_stripos($row['channel'], $search) !== false)));
        usort($rows, static fn (array $a, array $b): int => [$b['visits'], $a['channel'], $a['source']] <=> [$a['visits'], $b['channel'], $b['source']]);

        $pages = max(1, (int) ceil(count($rows) / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        arsort($channels);

        return [
            'from' => $range->from,
            'to' => $range->to,
            'previousFrom' => $previous?->from,
            'previousTo' => $previous?->to,
            'visits' => array_sum($channels),
            'channels' => array_map(static fn (string $name, int $visits): array => [
                'channel' => $name,
                'visits' => $visits,
                'previous' => $previous instanceof DayRange ? $channelsBefore[$name] ?? 0 : null,
            ], array_map(strval(...), array_keys($channels)), array_values($channels)),
            'channel' => $channel,
            'search' => $search,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
            'total' => count($rows),
            'max' => $max,
            'rows' => array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            ...$this->query->counts($range),
        ];
    }

    /**
     * Campaigns, most visits first, with their visits per day.
     *
     * @return array{from: string, to: string, days: list<string>, rows: list<array{campaign: string, visits: int, first: string, last: string, series: list<int>, origins: list<Origin>, landing: Landing}>}
     */
    public function campaigns(string $from, string $to, int $threshold): array
    {
        $range = DayRange::of($from, $to);
        $days = $range->days();
        $index = array_flip($days);

        /** @var array<string, array{campaign: string, visits: int, first: string, last: string, series: list<int>, origins: array<string, int>, paths: array<string, int>}> $campaigns */
        $campaigns = [];
        foreach ($this->query->campaigns($range) as $row) {
            $name = $row['campaign'];
            $campaign = $campaigns[$name] ?? [
                'campaign' => $name,
                'visits' => 0,
                'first' => $row['day'],
                'last' => $row['day'],
                'series' => array_fill(0, count($days), 0),
                'origins' => [],
                'paths' => [],
            ];
            $campaign['visits'] += $row['visits'];
            $campaign['first'] = min($campaign['first'], $row['day']);
            $campaign['last'] = max($campaign['last'], $row['day']);
            if (isset($index[$row['day']])) {
                $campaign['series'][$index[$row['day']]] += $row['visits'];
            }
            $origin = $row['channel'] . "\0" . $row['source'];
            $campaign['origins'][$origin] = ($campaign['origins'][$origin] ?? 0) + $row['visits'];
            $campaign['paths'][$row['path']] = ($campaign['paths'][$row['path']] ?? 0) + $row['visits'];
            $campaigns[$name] = $campaign;
        }

        usort($campaigns, static fn (array $a, array $b): int => [$b['visits'], $a['campaign']] <=> [$a['visits'], $b['campaign']]);

        return [
            'from' => $range->from,
            'to' => $range->to,
            'days' => $days,
            'rows' => array_map(fn (array $campaign): array => [
                'campaign' => $campaign['campaign'],
                'visits' => $campaign['visits'],
                'first' => $campaign['first'],
                'last' => $campaign['last'],
                'series' => array_values($campaign['series']),
                'origins' => self::origins($campaign['origins'], $threshold),
                'landing' => $this->landing($campaign['paths'], $threshold),
            ], array_slice($campaigns, 0, self::MAX_CAMPAIGNS)),
        ];
    }

    /**
     * Aliases and markers, most visits first, with how the visitors got to
     * them; configured ones nobody used yet with 0.
     *
     * @param list<string> $configured `aliases.hosts`
     *
     * @return array{from: string, to: string, rows: list<array{via: string, visits: int, origins: list<Origin>, landing: Landing}>}
     */
    public function aliases(string $from, string $to, int $threshold, array $configured = []): array
    {
        $range = DayRange::of($from, $to);

        $aliases = [];
        foreach ($this->query->aliases($range) as $row) {
            $via = $row['via'];
            $aliases[$via] ??= [
                'via' => $via,
                'visits' => 0,
                'origins' => [],
                'paths' => [],
            ];
            $aliases[$via]['visits'] += $row['visits'];
            $origin = $row['channel'] . "\0" . $row['source'];
            $aliases[$via]['origins'][$origin] = ($aliases[$via]['origins'][$origin] ?? 0) + $row['visits'];
            $aliases[$via]['paths'][$row['path']] = ($aliases[$via]['paths'][$row['path']] ?? 0) + $row['visits'];
        }

        foreach ($configured as $via) {
            $aliases[$via] ??= [
                'via' => $via,
                'visits' => 0,
                'origins' => [],
                'paths' => [],
            ];
        }

        usort($aliases, static fn (array $a, array $b): int => [$b['visits'], $a['via']] <=> [$a['visits'], $b['via']]);

        return [
            'from' => $range->from,
            'to' => $range->to,
            'rows' => array_map(fn (array $alias): array => [
                'via' => (string) $alias['via'],
                'visits' => $alias['visits'],
                'origins' => self::origins($alias['origins'], $threshold),
                'landing' => $this->landing($alias['paths'], $threshold),
            ], $aliases),
        ];
    }

    /**
     * Sources of a channel below the threshold become its "other" source.
     *
     * @param array<string, array{channel: string, source: string, visits: int, previous: ?int, paths: array<string, int>}> $sources
     *
     * @return list<array{channel: string, source: string, visits: int, previous: ?int, paths: array<string, int>}>
     */
    private static function foldSources(array $sources, int $threshold): array
    {
        $kept = [];
        $other = [];
        foreach ($sources as $source) {
            if ($source['visits'] >= $threshold || $source['source'] === '') {
                $kept[] = $source;

                continue;
            }

            $channel = $source['channel'];
            $other[$channel] ??= [
                'channel' => $channel,
                'source' => self::OTHER,
                'visits' => 0,
                'previous' => $source['previous'] === null ? null : 0,
                'paths' => [],
            ];
            $other[$channel]['visits'] += $source['visits'];
            if ($source['previous'] !== null && $other[$channel]['previous'] !== null) {
                $other[$channel]['previous'] += $source['previous'];
            }
            foreach ($source['paths'] as $path => $visits) {
                $other[$channel]['paths'][$path] = ($other[$channel]['paths'][$path] ?? 0) + $visits;
            }
        }

        return [...$kept, ...array_values($other)];
    }

    /**
     * Channel and source of the visits, largest first; small ones as "other".
     *
     * @param array<string, int> $origins "channel\0source" => visits
     *
     * @return list<Origin>
     */
    private static function origins(array $origins, int $threshold): array
    {
        arsort($origins);
        $list = [];
        $other = 0;
        foreach ($origins as $key => $visits) {
            [$channel, $source] = explode("\0", (string) $key, 2);
            if ($visits < $threshold && $source !== '') {
                $other += $visits;

                continue;
            }

            $list[] = [
                'channel' => $channel,
                'source' => $source,
                'visits' => $visits,
            ];
        }

        if ($other > 0) {
            $list[] = [
                'channel' => self::OTHER,
                'source' => '',
                'visits' => $other,
            ];
        }

        return $list;
    }

    /**
     * The top landing pages, titled; pages below the threshold are left out.
     *
     * @param array<string, int> $paths
     *
     * @return Landing
     */
    private function landing(array $paths, int $threshold): array
    {
        $titles = $this->catalog->pages();
        $prefixes = $this->catalog->locales();
        $default = $this->catalog->defaultLocale();

        // The languages of a page are one page, like in the pages report.
        $pages = [];
        foreach ($paths as $path => $visits) {
            [$key] = PagesReport::split((string) $path, $prefixes, $default);
            $pages[$key] = ($pages[$key] ?? 0) + $visits;
        }

        $pages = array_filter($pages, static fn (int $visits): bool => $visits >= $threshold);
        uksort($pages, static fn (string $a, string $b): int => [$pages[$b], $a] <=> [$pages[$a], $b]);

        $landing = [];
        foreach (array_slice($pages, 0, self::LANDING_PAGES, true) as $path => $visits) {
            $landing[] = [
                'path' => (string) $path,
                'title' => $titles[$path] ?? null,
                'visits' => $visits,
            ];
        }

        return $landing;
    }
}
