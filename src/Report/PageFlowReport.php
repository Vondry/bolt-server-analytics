<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Report;

use InvalidArgumentException;
use TomVondracek\RequestAnalytics\Query\FlowQuery;

/**
 * Where the visitors of a page came from and where they went next, within
 * the site: the page in all its languages, like a row of the pages report.
 *
 * - Came from: the visits that began on the page (from outside the site), and
 *   the pages of the site that led to it.
 * - Went next: the pages opened from it. Its page views without a next page
 *   ended the visit there, or went on in a way the browser did not tell
 *   (another tab without a referrer, the back button to a cached page).
 * - A move to the same page in another language is a language switch; a
 *   reload is not counted.
 *
 * A page with fewer moves than `report_threshold_k` is counted in a remainder,
 * so a rare path through the site does not trace one visitor.
 *
 * @phpstan-type Step array{key: string, title: ?string, hits: int}
 * @phpstan-type Flow array{path: string, title: ?string, from: string, to: string, views: int, entries: int, cameFrom: list<Step>, cameFromOther: int, wentTo: list<Step>, wentToOther: int, wentToTotal: int, languageSwitches: int}
 */
final readonly class PageFlowReport
{
    public const MAX_STEPS = 8;
    private const MAX_PATH = 255;

    public function __construct(
        private FlowQuery $query,
        private PageCatalogInterface $catalog,
        private SummaryReport $summary,
    ) {
    }

    /**
     * @param string $path the page as the pages report keys it: without a language prefix
     *
     * @return Flow
     *
     * @throws InvalidArgumentException for an invalid path or range
     */
    public function flow(string $path, string $from, string $to, int $threshold = 1): array
    {
        if (! str_starts_with($path, '/') || mb_strlen($path) > self::MAX_PATH || preg_match('~[\x00-\x1f\x7f]~', $path) === 1) {
            throw new InvalidArgumentException(sprintf('"path" must be a path of the site such as /page/about, "%s" given.', mb_substr($path, 0, 80)));
        }

        [$range] = $this->summary->ranges($from, $to, compare: false);
        $threshold = max(1, $threshold);
        $defaultLocale = $this->catalog->defaultLocale();
        $prefixes = $this->catalog->locales();
        $titles = $this->catalog->pages();

        $paths = [$path];
        foreach ($prefixes as $prefix) {
            $paths[] = '/' . $prefix . ($path === '/' ? '' : $path);
        }

        $paths = array_values(array_unique($paths));

        $cameFrom = [];
        $wentTo = [];
        $switches = 0;
        foreach ($this->query->around($range, $paths) as $move) {
            [$fromKey] = PagesReport::split($move['from'], $prefixes, $defaultLocale);
            [$toKey] = PagesReport::split($move['to'], $prefixes, $defaultLocale);
            if ($fromKey === $path && $toKey === $path) {
                // The same page again: a reload, or the same page in another language.
                $switches += $move['from'] === $move['to'] ? 0 : $move['hits'];
            } elseif ($toKey === $path) {
                $cameFrom[$fromKey] = ($cameFrom[$fromKey] ?? 0) + $move['hits'];
            } else {
                $wentTo[$toKey] = ($wentTo[$toKey] ?? 0) + $move['hits'];
            }
        }

        $views = $this->query->views($range, $paths);
        [$cameFromSteps, $cameFromOther] = self::steps($cameFrom, $titles, $threshold);
        [$wentToSteps, $wentToOther] = self::steps($wentTo, $titles, $threshold);

        return [
            'path' => $path,
            'title' => $titles[$path] ?? null,
            'from' => $range->from,
            'to' => $range->to,
            'views' => $views['views'],
            'entries' => $views['entries'],
            'cameFrom' => $cameFromSteps,
            'cameFromOther' => $cameFromOther,
            'wentTo' => $wentToSteps,
            'wentToOther' => $wentToOther,
            'wentToTotal' => array_sum($wentTo),
            'languageSwitches' => $switches,
        ];
    }

    /**
     * The most frequent first; the rest, and those below the threshold, as a count.
     *
     * @param array<string, int> $moves page => moves
     * @param array<string, string> $titles
     *
     * @return array{list<Step>, int}
     */
    private static function steps(array $moves, array $titles, int $threshold): array
    {
        uksort($moves, static fn (string $a, string $b): int => [$moves[$b], $a] <=> [$moves[$a], $b]);

        $steps = [];
        $other = 0;
        foreach ($moves as $key => $hits) {
            if ($hits < $threshold || count($steps) >= self::MAX_STEPS) {
                $other += $hits;

                continue;
            }

            $steps[] = [
                'key' => (string) $key,
                'title' => $titles[$key] ?? null,
                'hits' => $hits,
            ];
        }

        return [$steps, $other];
    }
}
