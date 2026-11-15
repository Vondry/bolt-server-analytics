<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Aggregation;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use LogicException;

/**
 * Builds the one statement that rolls raw rows into an aggregate table: an
 * `INSERT … SELECT … GROUP BY` that adds to the counters of rows that already
 * exist. The "add to existing" part is the only SQL that differs per platform.
 */
final class UpsertSqlBuilder
{
    /**
     * Named parameter of the statement: the highest `ra_hit.id` to include.
     */
    public const UPPER_BOUND = 'b';

    /**
     * @param string $hitTable the table the rows come from
     * @param string|null $range the rows to take; null = those up to `:b` (UPPER_BOUND) of `ra_hit`
     */
    public function build(AbstractPlatform $platform, Aggregate $aggregate, string $hitTable, ?string $range = null): string
    {
        $columns = [...array_keys($aggregate->keys), ...array_keys($aggregate->counters)];

        $select = [];
        foreach ([...$aggregate->keys, ...$aggregate->counters] as $column => $expression) {
            $select[] = $expression === $column ? $column : $expression . ' AS ' . $column;
        }

        // The WHERE clause is always there: SQLite needs one to tell the SELECT
        // from the upsert clause that follows it.
        $source = sprintf(
            'SELECT %s FROM %s WHERE %s%s GROUP BY %s',
            implode(', ', $select),
            $hitTable,
            $range ?? 'id <= :' . self::UPPER_BOUND,
            $aggregate->where === null ? '' : ' AND ' . $aggregate->where,
            implode(', ', $aggregate->keys)
        );

        $insert = sprintf('INSERT INTO %s (%s) ', $aggregate->table, implode(', ', $columns));
        $counters = array_keys($aggregate->counters);

        if ($platform instanceof AbstractMySQLPlatform) {
            // The derived table gives the new values a name to refer to.
            return $insert . sprintf(
                'SELECT * FROM (%s) AS n ON DUPLICATE KEY UPDATE %s',
                $source,
                self::additions($counters, $aggregate->table, 'n')
            );
        }

        if ($platform instanceof PostgreSQLPlatform || $platform instanceof SQLitePlatform) {
            return $insert . sprintf(
                '%s ON CONFLICT (%s) DO UPDATE SET %s',
                $source,
                implode(', ', array_keys($aggregate->keys)),
                self::additions($counters, $aggregate->table, 'EXCLUDED')
            );
        }

        throw new LogicException(sprintf('Request Analytics supports MySQL, MariaDB, PostgreSQL and SQLite; "%s" is not one of them.', $platform::class));
    }

    /**
     * @param list<string> $counters
     */
    private static function additions(array $counters, string $table, string $new): string
    {
        return implode(', ', array_map(
            static fn (string $counter): string => sprintf('%1$s = %2$s.%1$s + %3$s.%1$s', $counter, $table, $new),
            $counters
        ));
    }
}
