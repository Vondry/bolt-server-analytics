<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Query;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * Where visits came from, for a range of days: `ra_sources` (channel, source,
 * campaign and landing page of every visit) and `ra_aliases` (visits that
 * came through a domain alias or a marker such as a QR code).
 *
 * Grouped rows only; SourcesReport shapes them.
 */
final readonly class SourcesQuery
{
    public function __construct(
        #[Autowire(expression: AnalyticsDatabase::CONNECTION)]
        private Connection $connection,
        private TableNames $tables,
    ) {
    }

    /**
     * @return list<array{channel: string, source: string, path: string, visits: int}>
     */
    public function sources(DayRange $range): array
    {
        return array_map(static fn (array $row): array => [
            'channel' => self::text($row['channel'] ?? null),
            'source' => self::text($row['source'] ?? null),
            'path' => self::text($row['path'] ?? null),
            'visits' => self::int($row['visits'] ?? null),
        ], $this->rows('SELECT channel, source, path, SUM(hits) AS visits FROM %s WHERE day >= :from AND day <= :to GROUP BY channel, source, path', $this->tables->sources(), $range));
    }

    /**
     * Visits of every campaign per day and landing page.
     *
     * @return list<array{campaign: string, channel: string, source: string, day: string, path: string, visits: int}>
     */
    public function campaigns(DayRange $range): array
    {
        return array_map(static fn (array $row): array => [
            'campaign' => self::text($row['campaign'] ?? null),
            'channel' => self::text($row['channel'] ?? null),
            'source' => self::text($row['source'] ?? null),
            'day' => mb_substr(self::text($row['day'] ?? null), 0, 10),
            'path' => self::text($row['path'] ?? null),
            'visits' => self::int($row['visits'] ?? null),
        ], $this->rows("SELECT campaign, channel, source, day, path, SUM(hits) AS visits FROM %s WHERE day >= :from AND day <= :to AND campaign <> '' GROUP BY campaign, channel, source, day, path", $this->tables->sources(), $range));
    }

    /**
     * @return list<array{via: string, channel: string, source: string, path: string, visits: int}>
     */
    public function aliases(DayRange $range): array
    {
        return array_map(static fn (array $row): array => [
            'via' => self::text($row['via'] ?? null),
            'channel' => self::text($row['channel'] ?? null),
            'source' => self::text($row['source'] ?? null),
            'path' => self::text($row['path'] ?? null),
            'visits' => self::int($row['visits'] ?? null),
        ], $this->rows('SELECT via, channel, source, path, SUM(hits) AS visits FROM %s WHERE day >= :from AND day <= :to GROUP BY via, channel, source, path', $this->tables->aliases(), $range));
    }

    /**
     * How many campaigns and aliases the range has, for the tabs.
     *
     * @return array{campaigns: int, aliases: int}
     */
    public function counts(DayRange $range): array
    {
        $parameters = [
            'from' => $range->from,
            'to' => $range->to,
        ];

        return [
            'campaigns' => self::int($this->connection->fetchOne(sprintf("SELECT COUNT(DISTINCT campaign) FROM %s WHERE day >= :from AND day <= :to AND campaign <> ''", $this->tables->sources()), $parameters)),
            'aliases' => self::int($this->connection->fetchOne(sprintf('SELECT COUNT(DISTINCT via) FROM %s WHERE day >= :from AND day <= :to', $this->tables->aliases()), $parameters)),
        ];
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
