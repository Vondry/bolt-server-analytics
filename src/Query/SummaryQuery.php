<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Query;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * The totals of a range of days, from `ra_daily`.
 */
final readonly class SummaryQuery
{
    public function __construct(
        #[Autowire(expression: AnalyticsDatabase::CONNECTION)]
        private Connection $connection,
        private TableNames $tables,
    ) {
    }

    /**
     * @return array{pageviews: int, visits: int, notFound: int, bots: int}
     */
    public function totals(DayRange $range): array
    {
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT SUM(pageviews) AS pageviews, SUM(entries) AS visits, SUM(not_found) AS not_found, SUM(bots) AS bots FROM %s WHERE day >= :from AND day <= :to', $this->tables->daily()),
            [
                'from' => $range->from,
                'to' => $range->to,
            ]
        );

        $int = static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0;

        return [
            'pageviews' => $int($row['pageviews'] ?? null),
            'visits' => $int($row['visits'] ?? null),
            'notFound' => $int($row['not_found'] ?? null),
            'bots' => $int($row['bots'] ?? null),
        ];
    }

    /**
     * The totals of every day of a range that has a row.
     *
     * @return array<string, array{pageviews: int, visits: int, bots: int}> day (Y-m-d) => totals
     */
    public function daily(DayRange $range): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT day, SUM(pageviews) AS pageviews, SUM(entries) AS visits, SUM(bots) AS bots FROM %s WHERE day >= :from AND day <= :to GROUP BY day', $this->tables->daily()),
            [
                'from' => $range->from,
                'to' => $range->to,
            ]
        );

        $int = static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0;
        $days = [];
        foreach ($rows as $row) {
            if (is_scalar($row['day'] ?? null)) {
                $days[mb_substr((string) $row['day'], 0, 10)] = [
                    'pageviews' => $int($row['pageviews'] ?? null),
                    'visits' => $int($row['visits'] ?? null),
                    'bots' => $int($row['bots'] ?? null),
                ];
            }
        }

        return $days;
    }

    /**
     * The first day with statistics (Y-m-d); null without any.
     */
    public function firstDay(): ?string
    {
        $day = $this->connection->fetchOne(sprintf('SELECT MIN(day) FROM %s', $this->tables->daily()));

        return is_string($day) && $day !== '' ? mb_substr($day, 0, 10) : null;
    }
}
