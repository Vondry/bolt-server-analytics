<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Insight;

use DateTimeImmutable;
use DateTimeZone;
use TomVondracek\RequestAnalytics\Query\DayRange;
use TomVondracek\RequestAnalytics\Report\PageCatalogInterface;
use TomVondracek\RequestAnalytics\Report\PagesReport;

/**
 * What the rules share: the fixed windows, the privacy threshold, the pages
 * of the site, and the aggregates, each read once however many rules ask.
 *
 * The windows do not follow the period bar, so an insight stays the same
 * between visits. Today is never in them, nor a day the rollup has not
 * finished yet.
 *
 * - Change rules compare the last 28 complete days with the 28 days before
 *   (the same weekdays). They need statistics for both.
 * - Structural rules use the last 90 complete days.
 */
final class InsightContext
{
    public const RECENT_DAYS = 28;
    public const STRUCTURAL_DAYS = 90;

    /**
     * Totals per day: wrong while a day is missing.
     */
    public const DATA_COUNTS = 'counts';

    /**
     * Visits and where they came from: wrong while clicks within the site count as visits.
     */
    public const DATA_REFERRERS = 'referrers';

    /** The last 28 complete days. */
    public readonly DayRange $recent;

    /** The 28 days before them. */
    public readonly DayRange $before;

    /** The last 90 complete days. */
    public readonly DayRange $structural;

    /** @var array<string, mixed> */
    private array $memo = [];

    /**
     * @param string $lastDay the last complete day (Y-m-d)
     * @param int $threshold `report_threshold_k`
     * @param list<string> $ownHosts hosts of this site, without `www.`
     */
    public function __construct(
        private readonly InsightData $data,
        private readonly PageCatalogInterface $catalog,
        public readonly string $lastDay,
        public readonly int $threshold = 1,
        public readonly array $ownHosts = [],
    ) {
        $this->recent = self::window($lastDay, self::RECENT_DAYS);
        $this->before = $this->recent->previous();
        $this->structural = self::window($lastDay, self::STRUCTURAL_DAYS);
    }

    /**
     * The smallest count a value may be named or used with: the privacy
     * threshold, or the minimum of the rule when that is higher.
     */
    public function k(int $minimum = 1): int
    {
        return max(1, $this->threshold, $minimum);
    }

    /**
     * Is the difference between two counts of equally long windows more than
     * chance: at least 3 standard deviations, and at least 20 %?
     */
    public static function isRealChange(int $before, int $after): bool
    {
        if ($before <= 0 || $before + $after <= 0) {
            return false;
        }

        return abs($before - $after) / sqrt($before + $after) >= 3
            && abs($after - $before) / $before >= 0.2;
    }

    /**
     * The statistics cover the last 28 complete days.
     */
    public function coversRecent(): bool
    {
        return $this->covers($this->recent->from);
    }

    /**
     * The statistics cover the 28 days compared with as well.
     */
    public function coversChange(): bool
    {
        return $this->covers($this->before->from);
    }

    /**
     * The statistics cover the last 90 complete days.
     */
    public function coversStructural(): bool
    {
        return $this->covers($this->structural->from);
    }

    /**
     * The statistics of $day were deleted on purpose (on the Analytics page):
     * that it has none says nothing about the site.
     */
    public function isErased(string $day): bool
    {
        return array_any($this->erased(), fn ($period): bool => $period['from'] <= $day && $day <= $period['to']);
    }

    /**
     * Some days of $range were deleted on purpose: its totals are too low.
     */
    public function isErasedIn(DayRange $range): bool
    {
        return array_any($this->erased(), fn ($period): bool => $period['from'] <= $range->to && $range->from <= $period['to']);
    }

    /**
     * @return array<string, array{pageviews: int, visits: int, bots: int}> only days with a row
     */
    public function daily(DayRange $range): array
    {
        /** @var array<string, array{pageviews: int, visits: int, bots: int}> */
        return $this->memo('daily', $range, fn (): array => $this->data->daily($range));
    }

    /**
     * @return array{pageviews: int, visits: int, bots: int}
     */
    public function totals(DayRange $range): array
    {
        $totals = [
            'pageviews' => 0,
            'visits' => 0,
            'bots' => 0,
        ];
        foreach ($this->daily($range) as $day) {
            $totals['pageviews'] += $day['pageviews'];
            $totals['visits'] += $day['visits'];
            $totals['bots'] += $day['bots'];
        }

        return $totals;
    }

    /**
     * @return list<array{channel: string, source: string, path: string, visits: int}>
     */
    public function sources(DayRange $range): array
    {
        /** @var list<array{channel: string, source: string, path: string, visits: int}> */
        return $this->memo('sources', $range, fn (): array => $this->data->sources($range));
    }

    /**
     * @return array<string, array{views: int, entries: int}> stored path => counts
     */
    public function pages(DayRange $range): array
    {
        /** @var array<string, array{views: int, entries: int}> */
        return $this->memo('pages', $range, fn (): array => $this->data->pages($range));
    }

    /**
     * @return array<string, array{views: int, entries: int}> preferred language => counts
     */
    public function languages(DayRange $range): array
    {
        /** @var array<string, array{views: int, entries: int}> */
        return $this->memo('languages', $range, fn (): array => $this->data->languages($range));
    }

    /**
     * @return list<array{day: string, device: string, browser: string, views: int, entries: int}>
     */
    public function clients(DayRange $range): array
    {
        /** @var list<array{day: string, device: string, browser: string, views: int, entries: int}> */
        return $this->memo('clients', $range, fn (): array => $this->data->clients($range));
    }

    /**
     * @return list<array{from: string, to: string, hits: int}>
     */
    public function movesInto(DayRange $range, string $prefix): array
    {
        /** @var list<array{from: string, to: string, hits: int}> */
        return $this->memo('moves' . $prefix, $range, fn (): array => $this->data->movesInto($range, $prefix));
    }

    /**
     * @return list<array{path: string, ref: string, hits: int}>
     */
    public function notFound(DayRange $range): array
    {
        /** @var list<array{path: string, ref: string, hits: int}> */
        return $this->memo('notFound', $range, fn (): array => $this->data->notFound($range));
    }

    /**
     * @return list<array{term: string, hits: int}>
     */
    public function searches(DayRange $range): array
    {
        /** @var list<array{term: string, hits: int}> */
        return $this->memo('searches', $range, fn (): array => $this->data->searches($range));
    }

    /**
     * Published pages, by their path in the default language.
     *
     * @return array<string, string> path => title
     */
    public function titles(): array
    {
        /** @var array<string, string> */
        return $this->memo['titles'] ??= $this->catalog->pages();
    }

    /**
     * The page of a stored path, a link to it for the text of an insight.
     */
    public function page(string $path): Param
    {
        [$key] = $this->split($path);

        return Param::page($path, $this->titles()[$key] ?? null, home: $key === '/');
    }

    public function defaultLocale(): string
    {
        return $this->catalog->defaultLocale();
    }

    /**
     * @return list<string> codes that can prefix a path, the default included
     */
    public function locales(): array
    {
        /** @var list<string> */
        return $this->memo['locales'] ??= $this->catalog->locales();
    }

    /**
     * `/nl/page/about` → [`/page/about`, `nl`].
     *
     * @return array{string, string} the page in the default language, and the locale of the path
     */
    public function split(string $path): array
    {
        return PagesReport::split($path, $this->locales(), $this->defaultLocale());
    }

    /**
     * The language of a locale: `pt_BR` → `pt`.
     */
    public static function language(string $locale): string
    {
        return mb_strtolower(preg_split('/[_-]/', $locale)[0] ?? $locale);
    }

    /**
     * The languages the site has pages in.
     *
     * @return list<string>
     */
    public function siteLanguages(): array
    {
        return array_values(array_unique(array_map(self::language(...), $this->locales())));
    }

    /**
     * @return list<array{from: string, to: string}>
     */
    private function erased(): array
    {
        /** @var list<array{from: string, to: string}> */
        return array_key_exists('erased', $this->memo) ? $this->memo['erased'] : ($this->memo['erased'] = $this->data->erased());
    }

    /**
     * The first day of the statistics (Y-m-d); null without any.
     */
    public function firstDay(): ?string
    {
        /** @var ?string */
        return array_key_exists('firstDay', $this->memo) ? $this->memo['firstDay'] : ($this->memo['firstDay'] = $this->data->firstDay());
    }

    private function covers(string $day): bool
    {
        $first = $this->firstDay();

        return $first !== null && $first <= $day;
    }

    /**
     * @template T
     *
     * @param callable(): T $read
     *
     * @return T
     */
    private function memo(string $name, DayRange $range, callable $read): mixed
    {
        $key = $name . "\0" . $range->from . "\0" . $range->to;
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $read();
        }

        /** @var T */
        return $this->memo[$key];
    }

    /**
     * The days up to and including $lastDay.
     */
    private static function window(string $lastDay, int $days): DayRange
    {
        $last = new DateTimeImmutable($lastDay, new DateTimeZone('UTC'));

        return DayRange::of($last->modify(sprintf('-%d days', $days - 1))->format('Y-m-d'), $lastDay);
    }
}
