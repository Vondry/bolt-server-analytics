<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * Moves from page to page within the site (`ra_transitions`) around a page,
 * and the page views and visits of that page (`ra_pages`).
 */
final readonly class FlowQuery
{
    public function __construct(
        #[Autowire(expression: AnalyticsDatabase::CONNECTION)]
        private Connection $connection,
        private TableNames $tables,
    ) {
    }

    /**
     * Moves to or from one of the paths.
     *
     * @param list<string> $paths the stored paths of a page, one per language
     *
     * @return list<array{from: string, to: string, hits: int}>
     */
    public function around(DayRange $range, array $paths): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT from_path, to_path, SUM(hits) AS hits FROM %s WHERE day >= :from AND day <= :to AND (from_path IN (:paths) OR to_path IN (:paths)) GROUP BY from_path, to_path',
                $this->tables->transitions(),
            ),
            [
                'from' => $range->from,
                'to' => $range->to,
                'paths' => $paths,
            ],
            [
                'paths' => ArrayParameterType::STRING,
            ],
        );

        $moves = [];
        foreach ($rows as $row) {
            $hits = $row['hits'] ?? null;
            if (is_numeric($hits) && (int) $hits > 0 && is_scalar($row['from_path'] ?? null) && is_scalar($row['to_path'] ?? null)) {
                $moves[] = [
                    'from' => (string) $row['from_path'],
                    'to' => (string) $row['to_path'],
                    'hits' => (int) $hits,
                ];
            }
        }

        return $moves;
    }

    /**
     * Moves to a path prefix or below it (`/de`, `/de/…`): how visits reach
     * the pages of a language.
     *
     * @return list<array{from: string, to: string, hits: int}>
     */
    public function into(DayRange $range, string $prefix): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT from_path, to_path, SUM(hits) AS hits FROM %s WHERE day >= :from AND day <= :to AND (to_path = :prefix OR to_path LIKE :below) GROUP BY from_path, to_path',
                $this->tables->transitions(),
            ),
            [
                'from' => $range->from,
                'to' => $range->to,
                'prefix' => $prefix,
                // `_` of `pt_BR` matches any character: checked again below.
                'below' => $prefix . '/%',
            ],
        );

        $moves = [];
        foreach ($rows as $row) {
            $hits = $row['hits'] ?? null;
            $to = $row['to_path'] ?? null;
            if (is_numeric($hits) && (int) $hits > 0 && is_scalar($row['from_path'] ?? null) && is_scalar($to)
                && ((string) $to === $prefix || str_starts_with((string) $to, $prefix . '/'))
            ) {
                $moves[] = [
                    'from' => (string) $row['from_path'],
                    'to' => (string) $to,
                    'hits' => (int) $hits,
                ];
            }
        }

        return $moves;
    }

    /**
     * @param list<string> $paths
     *
     * @return array{views: int, entries: int} page views, and those that began a visit
     */
    public function views(DayRange $range, array $paths): array
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT SUM(hits) AS views, SUM(CASE WHEN is_entry THEN hits ELSE 0 END) AS entries FROM %s WHERE day >= :from AND day <= :to AND path IN (:paths)',
                $this->tables->pages(),
            ),
            [
                'from' => $range->from,
                'to' => $range->to,
                'paths' => $paths,
            ],
            [
                'paths' => ArrayParameterType::STRING,
            ],
        );

        return [
            'views' => is_numeric($row['views'] ?? null) ? (int) $row['views'] : 0,
            'entries' => is_numeric($row['entries'] ?? null) ? (int) $row['entries'] : 0,
        ];
    }
}
