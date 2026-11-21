<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Aggregation;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * Keeps a flood of made-up values from filling the database. The paths,
 * Referers, search terms and campaigns of the aggregate tables come from
 * visitors, and anybody can send a million different ones a day (random
 * missing pages, Referers of the site with random paths, random terms). Each
 * would be a row of its own, kept for `retention_months`.
 *
 * So a table that has more than MAX_ROWS rows of one day keeps of that day
 * only the values seen at least `report_threshold_k` times (2 at least):
 * those below are shown as "Other" anyway. A flood of values sent once, or a
 * few times, is gone, and a value of the site seen k times that day stays
 * however many made-up ones arrive: a flood never pushes real values out.
 * (One who sends every value k times pays k requests per row.) A real site
 * gets nowhere near that many different values a day. The daily totals
 * (`ra_daily`) stay as they are: they hold no values.
 *
 * Runs once a day with the retention cleanup, on the days before today that
 * a backlog of the spool can still reach.
 */
final readonly class DailyCap
{
    /**
     * Rows of a day in one table.
     */
    public const MAX_ROWS = 20_000;

    /**
     * Days before today that are checked: longer than a backlog of the spool
     * usually is (a visit starts a rollup every hour).
     */
    private const DAYS_CHECKED = 40;

    public function __construct(
        #[Autowire(expression: AnalyticsDatabase::CONNECTION)]
        private Connection $connection,
        private TableNames $tables,
        private int $maxRows = self::MAX_ROWS,
    ) {
    }

    /**
     * @param string $today Y-m-d, site timezone; today itself is not checked
     * @param int $threshold `report_threshold_k`: rows of a day over the limit with fewer hits go
     *
     * @return int rows deleted
     */
    public function apply(string $today, int $threshold): int
    {
        $below = max(2, $threshold);
        $from = (new DateTimeImmutable($today, new DateTimeZone('UTC')))
            ->modify(sprintf('-%d days', self::DAYS_CHECKED))
            ->format('Y-m-d');
        $deleted = 0;

        foreach ($this->tables() as $table) {
            // The table names and the limit are the extension's own.
            $days = $this->connection->fetchFirstColumn(sprintf('SELECT day FROM %s WHERE day >= :from AND day < :today GROUP BY day HAVING COUNT(*) > %d', $table, $this->maxRows), [
                'from' => $from,
                'today' => $today,
            ]);

            foreach ($days as $day) {
                if (! is_string($day)) {
                    continue;
                }

                // Y-m-d; MySQL may add a time to a DATE in some drivers.
                $deleted += (int) $this->connection->executeStatement(sprintf('DELETE FROM %s WHERE day = :day AND hits < :below', $table), [
                    'day' => mb_substr($day, 0, 10),
                    'below' => $below,
                ], [
                    'below' => ParameterType::INTEGER,
                ]);
            }
        }

        return $deleted;
    }

    /**
     * The aggregate tables keyed by values a visitor sends.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        return [
            $this->tables->pages(),
            $this->tables->sources(),
            $this->tables->transitions(),
            $this->tables->search(),
            $this->tables->aliases(),
            $this->tables->notFound(),
        ];
    }
}
