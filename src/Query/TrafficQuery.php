<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Query;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * Requests by bot family (`ra_bots`) and page views by hour of the day
 * (`ra_hourly`).
 */
final readonly class TrafficQuery
{
    public function __construct(
        #[Autowire(expression: AnalyticsDatabase::CONNECTION)]
        private Connection $connection,
        private TableNames $tables,
    ) {
    }

    /**
     * @return array<string, int> bot family => requests
     */
    public function bots(DayRange $range): array
    {
        $bots = [];
        foreach ($this->rows('SELECT bot AS name, SUM(hits) AS hits FROM %s WHERE day >= :from AND day <= :to GROUP BY bot', $this->tables->bots(), $range) as $row) {
            if (is_scalar($row['name'] ?? null) && is_numeric($row['hits'] ?? null) && (int) $row['hits'] > 0) {
                $bots[(string) $row['name']] = (int) $row['hits'];
            }
        }

        return $bots;
    }

    /**
     * @return list<array{day: string, hour: int, hits: int}> page views per day and hour (site timezone)
     */
    public function hours(DayRange $range): array
    {
        $hours = [];
        foreach ($this->rows('SELECT day, hour, SUM(hits) AS hits FROM %s WHERE day >= :from AND day <= :to GROUP BY day, hour', $this->tables->hourly(), $range) as $row) {
            if (is_scalar($row['day'] ?? null) && is_numeric($row['hour'] ?? null) && is_numeric($row['hits'] ?? null)) {
                $hours[] = [
                    'day' => mb_substr((string) $row['day'], 0, 10),
                    'hour' => (int) $row['hour'],
                    'hits' => (int) $row['hits'],
                ];
            }
        }

        return $hours;
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
}
