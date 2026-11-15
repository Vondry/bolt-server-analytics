<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Query;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TomVondracek\RequestAnalytics\Config\RequestAnalyticsConfig;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * Page views (or visits) per stored path in a range of days, from `ra_pages`.
 * The path is as requested, with the language prefix of a localized page
 * (`/nl/page/about`); PagesReport groups the languages of a page.
 */
final readonly class PagesQuery
{
    public function __construct(
        #[Autowire(expression: AnalyticsDatabase::CONNECTION)]
        private Connection $connection,
        private TableNames $tables,
    ) {
    }

    /**
     * @param string $from first day (Y-m-d), validated by the caller
     * @param string $to last day (Y-m-d), validated by the caller
     *
     * @return array<string, int> path => page views (or visits), only paths with any
     */
    public function views(string $metric, string $from, string $to): array
    {
        if (! in_array($metric, RequestAnalyticsConfig::METRICS, true)) {
            throw new InvalidArgumentException(sprintf('"metric" must be one of %s, "%s" given.', implode(' | ', RequestAnalyticsConfig::METRICS), $metric));
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT path, SUM(hits) AS views FROM %s WHERE day >= :from AND day <= :to%s GROUP BY path',
                $this->tables->pages(),
                $metric === 'entries' ? ' AND is_entry' : '',
            ),
            [
                'from' => $from,
                'to' => $to,
            ]
        );

        $views = [];
        foreach ($rows as $row) {
            $path = $row['path'] ?? null;
            $count = $row['views'] ?? null;
            if (is_string($path) && is_numeric($count) && (int) $count > 0) {
                $views[$path] = (int) $count;
            }
        }

        return $views;
    }

    /**
     * Page views (or visits) by the language the visitor's browser asks for
     * first (`Accept-Language`), from `ra_locales`; `und` when unknown.
     *
     * @return array<string, int> language => page views (or visits)
     */
    public function visitorLanguages(string $metric, string $from, string $to): array
    {
        if (! in_array($metric, RequestAnalyticsConfig::METRICS, true)) {
            throw new InvalidArgumentException(sprintf('"metric" must be one of %s, "%s" given.', implode(' | ', RequestAnalyticsConfig::METRICS), $metric));
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT lang, SUM(hits) AS views FROM %s WHERE day >= :from AND day <= :to%s GROUP BY lang',
                $this->tables->locales(),
                $metric === 'entries' ? ' AND is_entry' : '',
            ),
            [
                'from' => $from,
                'to' => $to,
            ]
        );

        $languages = [];
        foreach ($rows as $row) {
            $lang = $row['lang'] ?? null;
            $count = $row['views'] ?? null;
            if (is_string($lang) && $lang !== '' && is_numeric($count) && (int) $count > 0) {
                $languages[$lang] = ($languages[$lang] ?? 0) + (int) $count;
            }
        }

        return $languages;
    }
}
