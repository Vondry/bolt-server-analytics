<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Every text the statistics hold, in every table, for the security tests.
 */
final class StoredValues
{
    /**
     * Columns that the Analytics page turns into links (`href`): only paths
     * of the site may be in them.
     */
    private const LINKS = ['path', 'from_path', 'to_path'];

    /**
     * @return list<string> the tables, with the raw table
     */
    public static function tables(Database $database): array
    {
        $tables = $database->tables;

        return [$tables->hit(), $tables->daily(), $tables->hourly(), $tables->pages(), $tables->clients(), $tables->locales(), $tables->sources(), $tables->transitions(), $tables->search(), $tables->aliases(), $tables->notFound(), $tables->bots()];
    }

    /**
     * Fails with the first unsafe value of any table.
     *
     * @return int the values checked
     */
    public static function assertAllSafe(Database $database, string $context): int
    {
        $checked = 0;
        foreach (self::tables($database) as $table) {
            foreach ($database->connection->fetchAllAssociative(sprintf('SELECT * FROM %s', $table)) as $row) {
                foreach ($row as $column => $value) {
                    if (! is_string($value) || $column === 'day') {
                        continue;
                    }

                    $where = sprintf('%s: %s.%s = %s', $context, $table, $column, json_encode(mb_substr($value, 0, 120), JSON_INVALID_UTF8_SUBSTITUTE));
                    $problem = Payloads::unsafeIn($value);
                    Assert::assertNull($problem, $where . ' holds ' . $problem);

                    // The referrer of a broken link is a path of the site or a host.
                    if (in_array($column, self::LINKS, true) || ($column === 'ref' && str_starts_with($value, '/'))) {
                        Assert::assertTrue(Payloads::isSitePath($value), $where . ' would be a link out of the site');
                    }

                    ++$checked;
                }
            }
        }

        return $checked;
    }

    /**
     * The names of all tables of the database: nothing created, nothing
     * dropped or renamed by injected SQL.
     *
     * @return list<string>
     */
    public static function tableNames(Database $database): array
    {
        $names = $database->connection->createSchemaManager()->introspectTableNames();
        $names = array_map(static fn (object $name): string => (string) $name->getUnqualifiedName()->getValue(), $names);
        sort($names);

        return $names;
    }

    /**
     * Requests the statistics counted: page views, broken links, bots and alias hops.
     */
    public static function counted(Database $database): int
    {
        $total = $database->connection->fetchOne(sprintf('SELECT SUM(pageviews) + SUM(not_found) + SUM(bots) + SUM(alias) FROM %s', $database->tables->daily()));

        return is_numeric($total) ? (int) $total : 0;
    }
}
