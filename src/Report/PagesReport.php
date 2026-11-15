<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Report;

use InvalidArgumentException;
use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Query\DayRange;
use TomVondracek\RequestAnalytics\Query\PagesQuery;

/**
 * The pages report: which pages are viewed most and least, and in which
 * languages.
 *
 * The language of a view is in its path: Bolt prefixes a localized page with
 * its language (`/nl/page/about`), the default language has no prefix. The
 * languages of one page are one row (`/page/about`), titled like the record.
 * Published pages nobody opened are rows too, with 0: for "least viewed" they
 * are the ones that matter.
 *
 * Page views of a page say nothing about a visitor, so the counts are exact.
 *
 * @phpstan-type Language array{code: string, visitors: int, pages: int, offered: bool}
 * @phpstan-type Row array{key: string, title: ?string, views: int, previous: ?int, locales: array<string, int>}
 * @phpstan-type Report array{metric: string, from: string, to: string, order: string, search: string, page: int, pages: int, perPage: int, previousFrom: ?string, previousTo: ?string, total: int, max: int, totalViews: int, defaultLocale: string, languages: list<Language>, locales: list<array{code: string, views: int}>, rows: list<Row>}
 */
final readonly class PagesReport
{
    public const ORDER_MOST = 'most';
    public const ORDER_LEAST = 'least';
    public const PER_PAGE = 20;
    public const OTHER = '(other)';
    private const MAX_SEARCH = 100;

    public function __construct(
        private PagesQuery $query,
        private PageCatalogInterface $catalog,
        private SummaryReport $summary,
    ) {
    }

    /**
     * @param int $page 1 = the first PER_PAGE rows
     * @param string $search part of the path or the title; empty = every page
     *
     * @return Report
     *
     * @throws InvalidArgumentException for invalid arguments
     */
    public function report(string $metric, string $from, string $to, string $order = self::ORDER_MOST, int $page = 1, string $search = '', ?string $compareFrom = null, ?string $compareTo = null, bool $compare = true, int $threshold = 1): array
    {
        // Up to today, compared with the days the trend chart compares with.
        [$range, $previous] = $this->summary->ranges($from, $to, $compareFrom, $compareTo, $compare);
        if (! in_array($order, [self::ORDER_MOST, self::ORDER_LEAST], true)) {
            throw new InvalidArgumentException(sprintf('"order" must be one of %s | %s, "%s" given.', self::ORDER_MOST, self::ORDER_LEAST, $order));
        }

        $search = mb_substr(mb_trim($search), 0, self::MAX_SEARCH);
        $defaultLocale = $this->catalog->defaultLocale();
        $titles = $this->catalog->pages();
        [$rows, $locales] = $this->rows($this->query->views($metric, $range->from, $range->to), $previous instanceof DayRange ? $this->query->views($metric, $previous->from, $previous->to) : null, $titles, $defaultLocale);

        $totalViews = array_sum(array_column($rows, 'views'));
        $languages = $this->languages($this->query->visitorLanguages($metric, $range->from, $range->to), $locales, $threshold);
        $max = $rows === [] ? 0 : max(array_column($rows, 'views'));

        if ($search !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => mb_stripos($row['key'], $search) !== false
                || ($row['title'] !== null && mb_stripos($row['title'], $search) !== false)));
        }

        $sign = $order === self::ORDER_MOST ? -1 : 1;
        usort($rows, static fn (array $a, array $b): int => [$sign * $a['views'], $a['key']] <=> [$sign * $b['views'], $b['key']]);

        $pages = max(1, (int) ceil(count($rows) / self::PER_PAGE));
        $page = min(max(1, $page), $pages);

        // Most viewed first, the default language first among equals.
        uksort($locales, static fn (string $a, string $b): int => [-$locales[$a], $a !== $defaultLocale, $a] <=> [-$locales[$b], $b !== $defaultLocale, $b]);

        return [
            'metric' => $metric,
            'from' => $from,
            'to' => $to,
            'order' => $order,
            'search' => $search,
            'page' => $page,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
            'previousFrom' => $previous?->from,
            'previousTo' => $previous?->to,
            'total' => count($rows),
            'max' => $max,
            'totalViews' => $totalViews,
            'defaultLocale' => $defaultLocale,
            'languages' => $languages,
            'locales' => array_map(static fn (string $code, int $views): array => [
                'code' => $code,
                'views' => $views,
            ], array_keys($locales), array_values($locales)),
            'rows' => array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
        ];
    }

    /**
     * One row per page, its languages merged; every published page included.
     *
     * @param array<string, int> $views path => views
     * @param array<string, int>|null $previousViews path => views of the period compared with; null = no comparison
     * @param array<string, string> $titles published path => title
     *
     * @return array{list<Row>, array<string, int>} the rows, and the views per language
     */
    private function rows(array $views, ?array $previousViews, array $titles, string $defaultLocale): array
    {
        $prefixes = $this->catalog->locales();
        $rows = [];
        $locales = [];

        $before = [];
        foreach ($previousViews ?? [] as $path => $count) {
            [$key] = self::split((string) $path, $prefixes, $defaultLocale);
            $before[$key] = ($before[$key] ?? 0) + $count;
        }

        foreach ($views as $path => $count) {
            [$key, $locale] = self::split((string) $path, $prefixes, $defaultLocale);
            $rows[$key] ??= [
                'key' => $key,
                'title' => $titles[$key] ?? null,
                'views' => 0,
                'previous' => $previousViews === null ? null : $before[$key] ?? 0,
                'locales' => [],
            ];
            $rows[$key]['views'] += $count;
            $rows[$key]['locales'][$locale] = ($rows[$key]['locales'][$locale] ?? 0) + $count;
            $locales[$locale] = ($locales[$locale] ?? 0) + $count;
        }

        foreach ($titles as $path => $title) {
            $rows[$path] ??= [
                'key' => (string) $path,
                'title' => $title,
                'views' => 0,
                'previous' => $previousViews === null ? null : $before[$path] ?? 0,
                'locales' => [],
            ];
        }

        foreach ($rows as &$row) {
            arsort($row['locales']);
        }
        unset($row);

        return [array_values($rows), $locales];
    }

    /**
     * The languages visitors prefer next to the languages of the pages they
     * viewed, by the language alone (`pt_BR` pages serve visitors asking for
     * `pt`): do visitors who prefer a language find pages in it?
     *
     * A language few visitors prefer is "other"; an unknown one (`und`, also
     * every opt-out) counts on neither side.
     *
     * @param array<string, int> $visitors language => page views of the visitors who prefer it
     * @param array<string, int> $locales page language (`pt_BR`) => page views
     *
     * @return list<Language>
     */
    private function languages(array $visitors, array $locales, int $threshold): array
    {
        $offered = [];
        foreach ($this->catalog->locales() as $locale) {
            $offered[self::language($locale)] = true;
        }

        $pages = [];
        foreach ($locales as $locale => $views) {
            $language = self::language((string) $locale);
            $pages[$language] = ($pages[$language] ?? 0) + $views;
        }

        unset($visitors[Hit::UNKNOWN_LANGUAGE]);
        $rows = [];
        $other = 0;
        foreach (array_unique([...array_map(strval(...), array_keys($visitors)), ...array_map(strval(...), array_keys($pages))]) as $language) {
            $count = $visitors[$language] ?? 0;
            if ($count < $threshold && ! isset($pages[$language])) {
                $other += $count;

                continue;
            }

            $rows[] = [
                'code' => $language,
                'visitors' => $count < $threshold ? 0 : $count,
                'pages' => $pages[$language] ?? 0,
                'offered' => isset($offered[$language]),
            ];
            if ($count < $threshold) {
                $other += $count;
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$b['visitors'], $b['pages'], $a['code']] <=> [$a['visitors'], $a['pages'], $b['code']]);
        if ($other > 0) {
            $rows[] = [
                'code' => self::OTHER,
                'visitors' => $other,
                'pages' => 0,
                'offered' => true,
            ];
        }

        return $rows;
    }

    /**
     * The language of a locale: `pt_BR` → `pt`.
     */
    private static function language(string $locale): string
    {
        return mb_strtolower(preg_split('/[_-]/', $locale)[0] ?? $locale);
    }

    /**
     * `/nl/page/about` → [`/page/about`, `nl`]; `/nl` → [`/`, `nl`];
     * `/page/about` → [`/page/about`, the default language].
     *
     * @param list<string> $prefixes
     *
     * @return array{string, string}
     */
    public static function split(string $path, array $prefixes, string $defaultLocale): array
    {
        if (preg_match('~^/([^/]+)(/.*)?$~', $path, $matches) === 1 && in_array($matches[1], $prefixes, true)) {
            $rest = $matches[2] ?? '';

            return [$rest === '' ? '/' : $rest, $matches[1]];
        }

        return [$path, $defaultLocale];
    }
}
