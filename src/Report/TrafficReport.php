<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Report;

use DateTimeImmutable;
use DateTimeZone;
use TomVondracek\RequestAnalytics\Collector\HitClassifier;
use TomVondracek\RequestAnalytics\Collector\UserAgentParser;
use TomVondracek\RequestAnalytics\Query\DayRange;
use TomVondracek\RequestAnalytics\Query\TrafficQuery;
use TomVondracek\RequestAnalytics\SiteTime;

/**
 * When people visit, and which machines do.
 *
 * - Hours: page views by weekday and hour of the day, in the site's timezone,
 *   summed over the days of the range; with the number of each weekday in the
 *   range, so an average day can be told.
 * - Bots: requests by bot family, grouped by what the bots are for. A bot is
 *   not a person, so its counts are exact.
 *
 * @phpstan-type Bot array{key: string, hits: int, previous: ?int}
 * @phpstan-type Category array{key: string, hits: int, previous: ?int, bots: list<Bot>}
 */
final readonly class TrafficReport
{
    public const CATEGORY_SEARCH = 'search';
    public const CATEGORY_AI = 'ai';
    public const CATEGORY_SEO = 'seo';
    public const CATEGORY_PREVIEW = 'preview';
    public const CATEGORY_MONITORING = 'monitoring';
    public const CATEGORY_SCRIPTS = 'scripts';
    public const CATEGORY_DISGUISED = 'disguised';
    public const CATEGORY_UNKNOWN = 'unknown';

    /**
     * Bot family => what it is for; a family not listed is unknown.
     */
    private const CATEGORIES = [
        'googlebot' => self::CATEGORY_SEARCH,
        'bingbot' => self::CATEGORY_SEARCH,
        'seznambot' => self::CATEGORY_SEARCH,
        'applebot' => self::CATEGORY_SEARCH,
        'duckduckbot' => self::CATEGORY_SEARCH,
        'yandexbot' => self::CATEGORY_SEARCH,
        'baiduspider' => self::CATEGORY_SEARCH,
        'petalbot' => self::CATEGORY_SEARCH,
        'qwant' => self::CATEGORY_SEARCH,
        'mojeek' => self::CATEGORY_SEARCH,
        'kagi' => self::CATEGORY_SEARCH,
        'yahoo' => self::CATEGORY_SEARCH,
        'naver' => self::CATEGORY_SEARCH,
        'daum' => self::CATEGORY_SEARCH,
        'coccoc' => self::CATEGORY_SEARCH,
        'seekport' => self::CATEGORY_SEARCH,
        'mailru' => self::CATEGORY_SEARCH,
        'sogou' => self::CATEGORY_SEARCH,
        'qihoo' => self::CATEGORY_SEARCH,
        'yisou' => self::CATEGORY_SEARCH,
        'gptbot' => self::CATEGORY_AI,
        'claudebot' => self::CATEGORY_AI,
        'perplexitybot' => self::CATEGORY_AI,
        'gemini' => self::CATEGORY_AI,
        'mistral' => self::CATEGORY_AI,
        'bytespider' => self::CATEGORY_AI,
        'amazonbot' => self::CATEGORY_AI,
        'ccbot' => self::CATEGORY_AI,
        'ahrefs' => self::CATEGORY_SEO,
        'semrush' => self::CATEGORY_SEO,
        'mj12bot' => self::CATEGORY_SEO,
        'dotbot' => self::CATEGORY_SEO,
        'screamingfrog' => self::CATEGORY_SEO,
        'sitebulb' => self::CATEGORY_SEO,
        'blexbot' => self::CATEGORY_SEO,
        'serpstat' => self::CATEGORY_SEO,
        'dataforseo' => self::CATEGORY_SEO,
        'barkrowler' => self::CATEGORY_SEO,
        'seranking' => self::CATEGORY_SEO,
        'sistrix' => self::CATEGORY_SEO,
        'seobility' => self::CATEGORY_SEO,
        'xovi' => self::CATEGORY_SEO,
        'oncrawl' => self::CATEGORY_SEO,
        'megaindex' => self::CATEGORY_SEO,
        'siteimprove' => self::CATEGORY_SEO,
        'contentking' => self::CATEGORY_SEO,
        'facebook' => self::CATEGORY_PREVIEW,
        'twitterbot' => self::CATEGORY_PREVIEW,
        'linkedinbot' => self::CATEGORY_PREVIEW,
        'pinterestbot' => self::CATEGORY_PREVIEW,
        'slackbot' => self::CATEGORY_PREVIEW,
        'telegrambot' => self::CATEGORY_PREVIEW,
        'discordbot' => self::CATEGORY_PREVIEW,
        'uptimerobot' => self::CATEGORY_MONITORING,
        'lighthouse' => self::CATEGORY_MONITORING,
        'headless' => self::CATEGORY_SCRIPTS,
        'curl' => self::CATEGORY_SCRIPTS,
        'wget' => self::CATEGORY_SCRIPTS,
        'python' => self::CATEGORY_SCRIPTS,
        'perl' => self::CATEGORY_SCRIPTS,
        'go' => self::CATEGORY_SCRIPTS,
        'java' => self::CATEGORY_SCRIPTS,
        'node' => self::CATEGORY_SCRIPTS,
        HitClassifier::BOT_DISGUISED => self::CATEGORY_DISGUISED,
        UserAgentParser::BOT_GENERIC => self::CATEGORY_UNKNOWN,
    ];

    public function __construct(
        private TrafficQuery $query,
        private SummaryReport $summary,
        private SiteTime $siteTime,
    ) {
    }

    /**
     * @return array{from: string, to: string, timezone: string, total: int, max: int, days: list<int>, cells: list<list<int>>}
     *                `days`: how many Mondays … Sundays the range has; `cells`: page views by weekday (Monday first) and hour
     */
    public function hours(string $from, string $to): array
    {
        [$range] = $this->summary->ranges($from, $to, compare: false);

        $days = array_fill(0, 7, 0);
        foreach ($range->days() as $day) {
            ++$days[self::weekday($day)];
        }

        $cells = array_fill(0, 7, array_fill(0, 24, 0));
        foreach ($this->query->hours($range) as $row) {
            if ($row['hour'] >= 0 && $row['hour'] < 24) {
                $cells[self::weekday($row['day'])][$row['hour']] += $row['hits'];
            }
        }

        $cells = array_map(array_values(...), array_values($cells));
        $flat = array_merge([], ...$cells);

        return [
            'from' => $range->from,
            'to' => $range->to,
            'timezone' => $this->siteTime->timezone()->getName(),
            'total' => array_sum($flat),
            'max' => max([0, ...$flat]),
            'days' => array_values($days),
            'cells' => $cells,
        ];
    }

    /**
     * Bot requests by what the bots are for, the most frequent first, and
     * their share of all requests (page views, broken links and bots) in
     * percent, as the headline of the page counts it.
     *
     * @return array{from: string, to: string, previousFrom: ?string, previousTo: ?string, requests: int, previousRequests: ?int, allRequests: int, share: ?float, previousShare: ?float, categories: list<Category>}
     */
    public function bots(string $from, string $to, ?string $compareFrom = null, ?string $compareTo = null, bool $compare = true): array
    {
        [$range, $previous] = $this->summary->ranges($from, $to, $compareFrom, $compareTo, $compare);
        $now = $this->query->bots($range);
        $before = $previous instanceof DayRange ? $this->query->bots($previous) : null;

        // A bot gone since counts in its category's change, but is not listed.
        $previousOf = [];
        foreach ($before ?? [] as $bot => $hits) {
            $category = self::CATEGORIES[$bot] ?? self::CATEGORY_UNKNOWN;
            $previousOf[$category] = ($previousOf[$category] ?? 0) + $hits;
        }

        $categories = [];
        foreach ($now as $bot => $hits) {
            $bot = (string) $bot;
            $category = self::CATEGORIES[$bot] ?? self::CATEGORY_UNKNOWN;
            $categories[$category] ??= [
                'key' => $category,
                'hits' => 0,
                'previous' => $before === null ? null : $previousOf[$category] ?? 0,
                'bots' => [],
            ];
            $categories[$category]['hits'] += $hits;
            $categories[$category]['bots'][] = [
                'key' => $bot,
                'hits' => $hits,
                'previous' => $before === null ? null : $before[$bot] ?? 0,
            ];
        }

        $categories = array_values($categories);
        foreach ($categories as &$group) {
            usort($group['bots'], static fn (array $a, array $b): int => [$b['hits'], $a['key']] <=> [$a['hits'], $b['key']]);
        }
        unset($group);

        usort($categories, static fn (array $a, array $b): int => [$b['hits'], $a['key']] <=> [$a['hits'], $b['key']]);

        $totals = $this->summary->totals($range);
        $previousTotals = $previous instanceof DayRange ? $this->summary->totals($previous) : null;

        return [
            'from' => $range->from,
            'to' => $range->to,
            'previousFrom' => $previous?->from,
            'previousTo' => $previous?->to,
            'requests' => array_sum($now),
            'previousRequests' => $before === null ? null : array_sum($before),
            'allRequests' => self::requests($totals),
            'share' => self::share($totals),
            'previousShare' => $previousTotals === null ? null : self::share($previousTotals),
            'categories' => $categories,
        ];
    }

    /**
     * @param array{pageviews: int, visits: int, notFound: int, bots: int} $totals
     */
    private static function requests(array $totals): int
    {
        return $totals['pageviews'] + $totals['notFound'] + $totals['bots'];
    }

    /**
     * The bots of all requests in percent; null without any request.
     *
     * @param array{pageviews: int, visits: int, notFound: int, bots: int} $totals
     */
    private static function share(array $totals): ?float
    {
        $requests = self::requests($totals);

        return $requests === 0 ? null : $totals['bots'] / $requests * 100;
    }

    /**
     * 0 = Monday … 6 = Sunday.
     */
    private static function weekday(string $day): int
    {
        // Parenthesised: PHP before 8.4 needs it around `new` before `->`.
        return (int) (new DateTimeImmutable($day, new DateTimeZone('UTC')))->format('N') - 1;
    }
}
