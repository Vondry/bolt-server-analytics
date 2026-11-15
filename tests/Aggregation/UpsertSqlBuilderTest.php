<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Aggregation;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Aggregation\Aggregate;
use TomVondracek\RequestAnalytics\Aggregation\UpsertSqlBuilder;

final class UpsertSqlBuilderTest extends TestCase
{
    private const SOURCE = "SELECT day, COALESCE(ref, '') AS ref, COUNT(*) AS hits FROM bolt_ra_hit WHERE id <= :b AND kind = 'not_found' GROUP BY day, COALESCE(ref, '')";

    #[DataProvider('conflictPlatforms')]
    public function testPostgresAndSqliteUseOnConflict(AbstractPlatform $platform): void
    {
        self::assertSame(
            'INSERT INTO bolt_ra_not_found (day, ref, hits) ' . self::SOURCE
            . ' ON CONFLICT (day, ref) DO UPDATE SET hits = bolt_ra_not_found.hits + EXCLUDED.hits',
            (new UpsertSqlBuilder())->build($platform, self::aggregate(), 'bolt_ra_hit')
        );
    }

    /**
     * @return iterable<string, array{AbstractPlatform}>
     */
    public static function conflictPlatforms(): iterable
    {
        yield 'PostgreSQL' => [new PostgreSQLPlatform()];
        yield 'SQLite' => [new SQLitePlatform()];
    }

    #[DataProvider('duplicateKeyPlatforms')]
    public function testMysqlAndMariadbUseOnDuplicateKey(AbstractPlatform $platform): void
    {
        self::assertSame(
            'INSERT INTO bolt_ra_not_found (day, ref, hits) SELECT * FROM (' . self::SOURCE . ') AS n'
            . ' ON DUPLICATE KEY UPDATE hits = bolt_ra_not_found.hits + n.hits',
            (new UpsertSqlBuilder())->build($platform, self::aggregate(), 'bolt_ra_hit')
        );
    }

    /**
     * @return iterable<string, array{AbstractPlatform}>
     */
    public static function duplicateKeyPlatforms(): iterable
    {
        yield 'MySQL' => [new MySQLPlatform()];
        yield 'MariaDB' => [new MariaDBPlatform()];
    }

    public function testEveryCounterIsAdded(): void
    {
        $aggregate = new Aggregate('ra_daily', [
            'day' => 'day',
        ], [
            'pageviews' => "SUM(CASE WHEN kind = 'pageview' THEN 1 ELSE 0 END)",
            'bots' => "SUM(CASE WHEN kind = 'bot' THEN 1 ELSE 0 END)",
        ]);

        $sql = (new UpsertSqlBuilder())->build(new SQLitePlatform(), $aggregate, 'ra_hit');

        self::assertStringContainsString('FROM ra_hit WHERE id <= :b GROUP BY day ON CONFLICT (day)', $sql, 'the WHERE clause is there without a filter too');
        self::assertStringEndsWith('DO UPDATE SET pageviews = ra_daily.pageviews + EXCLUDED.pageviews, bots = ra_daily.bots + EXCLUDED.bots', $sql);
    }

    public function testOtherPlatformsAreNotSupported(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Request Analytics supports MySQL, MariaDB, PostgreSQL and SQLite');

        (new UpsertSqlBuilder())->build(new SQLServerPlatform(), self::aggregate(), 'ra_hit');
    }

    private static function aggregate(): Aggregate
    {
        return new Aggregate('bolt_ra_not_found', [
            'day' => 'day',
            'ref' => "COALESCE(ref, '')",
        ], [
            'hits' => 'COUNT(*)',
        ], "kind = 'not_found'");
    }
}
