<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Aggregation;

use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * The aggregate tables. All grouping uses the stored `day` and `hour` columns,
 * so no database-specific date functions are needed.
 */
final readonly class Aggregates
{
    private const HITS = [
        'hits' => 'COUNT(*)',
    ];

    public function __construct(
        private TableNames $tables,
    ) {
    }

    /**
     * @return list<Aggregate>
     */
    public function all(): array
    {
        return [
            new Aggregate($this->tables->daily(), [
                'day' => 'day',
            ], [
                'pageviews' => self::count("kind = 'pageview'"),
                'entries' => self::count("kind = 'pageview' AND is_entry"),
                'not_found' => self::count("kind = 'not_found'"),
                'bots' => self::count("kind = 'bot'"),
                'alias' => self::count("kind = 'alias'"),
            ]),
            ...$this->detailed(),
        ];
    }

    /**
     * Everything but the daily totals: the tables the retention applies to.
     *
     * @return list<Aggregate>
     */
    public function detailed(): array
    {
        return [
            new Aggregate($this->tables->hourly(), [
                'day' => 'day',
                'hour' => 'hour',
            ], self::HITS, "kind = 'pageview'"),
            // Page views by one group of dimensions per table: their product
            // would single out visitors on a small site, and grow per day with
            // pages × devices × browsers × languages.
            new Aggregate($this->tables->pages(), [
                'day' => 'day',
                'path' => 'path',
                'is_entry' => 'is_entry',
            ], self::HITS, "kind = 'pageview'"),
            new Aggregate($this->tables->clients(), [
                'day' => 'day',
                'device' => 'device',
                'browser' => 'browser',
                'os' => 'os',
                'is_entry' => 'is_entry',
            ], self::HITS, "kind = 'pageview'"),
            new Aggregate($this->tables->locales(), [
                'day' => 'day',
                'lang' => 'lang',
                'is_entry' => 'is_entry',
            ], self::HITS, "kind = 'pageview'"),
            new Aggregate($this->tables->sources(), [
                'day' => 'day',
                'path' => 'path',
                'channel' => 'channel',
                'source' => 'source',
                'campaign' => 'campaign',
            ], self::HITS, "kind = 'pageview' AND is_entry"),
            new Aggregate($this->tables->transitions(), [
                'day' => 'day',
                'from_path' => 'ref',
                'to_path' => 'path',
            ], self::HITS, "kind = 'pageview' AND ref IS NOT NULL AND channel = 'internal'"),
            new Aggregate($this->tables->search(), [
                'day' => 'day',
                'term' => 'term',
            ], self::HITS, "kind = 'pageview' AND term IS NOT NULL"),
            // Alias hops and page views that carry the alias marker.
            new Aggregate($this->tables->aliases(), [
                'day' => 'day',
                'via' => 'via',
                'path' => 'path',
                'channel' => 'channel',
                'source' => 'source',
            ], self::HITS, 'via IS NOT NULL'),
            new Aggregate($this->tables->notFound(), [
                'day' => 'day',
                'path' => 'path',
                'ref' => "COALESCE(ref, '')",
            ], self::HITS, "kind = 'not_found'"),
            new Aggregate($this->tables->bots(), [
                'day' => 'day',
                'bot' => "COALESCE(bot, 'generic')",
            ], self::HITS, "kind = 'bot'"),
        ];
    }

    private static function count(string $condition): string
    {
        return sprintf('SUM(CASE WHEN %s THEN 1 ELSE 0 END)', $condition);
    }
}
