<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Query;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * What visitors looked for and did not find: requests for missing pages with
 * the page that linked to them (`ra_not_found`), and site-search terms
 * (`ra_search`).
 */
final readonly class ContentGapsQuery
{
    public function __construct(
        #[Autowire(expression: AnalyticsDatabase::CONNECTION)]
        private Connection $connection,
        private TableNames $tables,
    ) {
    }

    /**
     * @return list<array{path: string, ref: string, hits: int}> `ref`: a path of this site, a host, or '' (typed, bookmarked)
     */
    public function notFound(DayRange $range): array
    {
        return array_map(static fn (array $row): array => [
            'path' => self::text($row['path'] ?? null),
            'ref' => self::text($row['ref'] ?? null),
            'hits' => self::int($row['hits'] ?? null),
        ], $this->rows('SELECT path, ref, SUM(hits) AS hits FROM %s WHERE day >= :from AND day <= :to GROUP BY path, ref', $this->tables->notFound(), $range));
    }

    /**
     * @return list<array{term: string, hits: int}>
     */
    public function searches(DayRange $range): array
    {
        return array_map(static fn (array $row): array => [
            'term' => self::text($row['term'] ?? null),
            'hits' => self::int($row['hits'] ?? null),
        ], $this->rows('SELECT term, SUM(hits) AS hits FROM %s WHERE day >= :from AND day <= :to GROUP BY term', $this->tables->search(), $range));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, string $table, DayRange $range): array
    {
        return $this->connection->fetchAllAssociative(sprintf($sql, $table), [
            'from' => $range->from,
            'to' => $range->to,
        ]);
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
