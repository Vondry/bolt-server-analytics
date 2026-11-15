<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Query;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TomVondracek\RequestAnalytics\Collector\UserAgentParser;
use TomVondracek\RequestAnalytics\Config\RequestAnalyticsConfig;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;
use TomVondracek\RequestAnalytics\Storage\TableNames;

/**
 * Shares of devices, browsers and operating systems in a range of days, for
 * the share lists of the analytics page, with the count of every value in the
 * period compared with. Reads `ra_clients`: a few dozen rows a day, whatever
 * the number of pages.
 *
 * A value with fewer hits than `report_threshold_k` is counted as `other`, so a
 * row never singles out a handful of visitors; so is everything after the
 * largest few, so a list stays readable. The same goes for the period compared
 * with: a listed value seen less often then has `previous` 0 (new), and
 * `other` there is everything not listed.
 *
 * @phpstan-type Slice array{key: string, value: int, previous: ?int}
 * @phpstan-type Breakdown array{metric: string, from: string, to: string, previousFrom: ?string, previousTo: ?string, total: int, previousTotal: ?int, dimensions: array{device: list<Slice>, browser: list<Slice>, os: list<Slice>}}
 */
final readonly class BreakdownQuery
{
    /**
     * Columns of `ra_clients`.
     */
    public const DIMENSIONS = ['device', 'browser', 'os'];

    /**
     * Slices of a chart, `other` included.
     */
    private const MAX_SLICES = 7;

    public function __construct(
        #[Autowire(expression: AnalyticsDatabase::CONNECTION)]
        private Connection $connection,
        private TableNames $tables,
    ) {
    }

    /**
     * @param string $from first day (Y-m-d)
     * @param string $to last day (Y-m-d)
     * @param int $threshold smallest number of hits a value is shown with
     * @param ?DayRange $previous the days compared with; none without a comparison
     *
     * @return Breakdown
     *
     * @throws InvalidArgumentException for an unknown metric and invalid dates
     */
    public function breakdown(string $metric, string $from, string $to, int $threshold, ?DayRange $previous = null): array
    {
        if (! in_array($metric, RequestAnalyticsConfig::METRICS, true)) {
            throw new InvalidArgumentException(sprintf('"metric" must be one of %s, "%s" given.', implode(' | ', RequestAnalyticsConfig::METRICS), $metric));
        }

        $range = DayRange::of($from, $to);
        $threshold = max(1, $threshold);

        $dimensions = [];
        $total = 0;
        $previousTotal = null;
        foreach (self::DIMENSIONS as $dimension) {
            $values = $this->values($dimension, $metric, $range);
            $before = $previous instanceof DayRange ? $this->values($dimension, $metric, $previous) : null;
            // Every dimension adds up to the same total, every page view.
            $total = array_sum($values);
            $previousTotal = $before === null ? null : array_sum($before);
            $dimensions[$dimension] = self::slices($values, $before, $threshold);
        }

        return [
            'metric' => $metric,
            'from' => $range->from,
            'to' => $range->to,
            'previousFrom' => $previous?->from,
            'previousTo' => $previous?->to,
            'total' => $total,
            'previousTotal' => $previousTotal,
            'dimensions' => $dimensions,
        ];
    }

    /**
     * Page views and visits per day, device and browser, for the insights:
     * how pages per visit of a browser changed, and since when.
     *
     * @return list<array{day: string, device: string, browser: string, views: int, entries: int}>
     */
    public function daily(DayRange $range): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT day, device, browser, SUM(hits) AS views, SUM(CASE WHEN is_entry THEN hits ELSE 0 END) AS entries FROM %s WHERE day >= :from AND day <= :to GROUP BY day, device, browser',
                $this->tables->clients(),
            ),
            [
                'from' => $range->from,
                'to' => $range->to,
            ]
        );

        $clients = [];
        foreach ($rows as $row) {
            if (is_scalar($row['day'] ?? null) && is_scalar($row['device'] ?? null) && is_scalar($row['browser'] ?? null)) {
                $clients[] = [
                    'day' => mb_substr((string) $row['day'], 0, 10),
                    'device' => (string) $row['device'],
                    'browser' => (string) $row['browser'],
                    'views' => is_numeric($row['views'] ?? null) ? (int) $row['views'] : 0,
                    'entries' => is_numeric($row['entries'] ?? null) ? (int) $row['entries'] : 0,
                ];
            }
        }

        return $clients;
    }

    /**
     * @return array<string, int> value of the dimension => hits
     */
    private function values(string $dimension, string $metric, DayRange $range): array
    {
        // The dimension is one of the known column names, see DIMENSIONS.
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %1$s AS name, SUM(hits) AS value FROM %2$s WHERE day >= :from AND day <= :to%3$s GROUP BY %1$s',
                $dimension,
                $this->tables->clients(),
                $metric === 'entries' ? ' AND is_entry' : '',
            ),
            [
                'from' => $range->from,
                'to' => $range->to,
            ]
        );

        $values = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            $value = $row['value'] ?? null;
            if (is_string($name) && is_numeric($value) && (int) $value > 0) {
                $key = $name === '' ? UserAgentParser::OTHER : $name;
                $values[$key] = ($values[$key] ?? 0) + (int) $value;
            }
        }

        return $values;
    }

    /**
     * Largest first; `other` last.
     *
     * @param array<string, int> $values
     * @param ?array<string, int> $before the values compared with, null for none
     *
     * @return list<Slice>
     */
    private static function slices(array $values, ?array $before, int $threshold): array
    {
        $other = $values[UserAgentParser::OTHER] ?? 0;
        unset($values[UserAgentParser::OTHER]);

        uksort($values, static fn (string $a, string $b): int => [$values[$b], $a] <=> [$values[$a], $b]);

        $slices = [];
        $listedBefore = 0;
        foreach ($values as $key => $value) {
            if ($value < $threshold || count($slices) >= self::MAX_SLICES - 1) {
                $other += $value;

                continue;
            }

            $previous = null;
            if ($before !== null) {
                // Seen too seldom then to be told apart: new.
                $previous = ($before[$key] ?? 0) >= $threshold ? $before[$key] ?? 0 : 0;
                $listedBefore += $previous;
            }

            $slices[] = [
                'key' => (string) $key,
                'value' => $value,
                'previous' => $previous,
            ];
        }

        if ($other > 0) {
            $slices[] = [
                'key' => UserAgentParser::OTHER,
                'value' => $other,
                'previous' => $before === null ? null : array_sum($before) - $listedBefore,
            ];
        }

        return $slices;
    }
}
