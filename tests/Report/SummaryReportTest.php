<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Report;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;

/**
 * "Today" is Friday 2026-11-13 in these tests.
 */
#[Group('database')]
final class SummaryReportTest extends TestCase
{
    private Pipeline $pipeline;

    protected function setUp(): void
    {
        $this->pipeline = new Pipeline();
    }

    protected function tearDown(): void
    {
        $this->pipeline->remove();
    }

    /**
     * The trend's current week runs until Sunday: the days still to come
     * would make it look worse than the week before, so both are cut at today.
     */
    public function testRangeUpToTodayComparedWithAsManyDaysBefore(): void
    {
        $this->pipeline->daily([
            '2026-11-09' => [10, 4],
            '2026-11-13' => [6, 2],
            // The days before: 2026-11-04 … 2026-11-08.
            '2026-11-04' => [5, 5],
            '2026-11-08' => [3, 1],
            // Neither: the day before the previous range.
            '2026-11-03' => [100, 100],
        ]);
        $this->pipeline->database->connection->executeStatement(sprintf("UPDATE %s SET not_found = 2, bots = 7 WHERE day = '2026-11-09'", $this->pipeline->database->tables->daily()));

        $summary = $this->pipeline->summaryReport()
            ->summary('2026-11-09', '2026-11-15');

        self::assertSame(['2026-11-09', '2026-11-13', '2026-11-04', '2026-11-08'], [$summary['from'], $summary['to'], $summary['previousFrom'], $summary['previousTo']]);
        self::assertSame([
            'pageviews' => 16,
            'visits' => 6,
            'notFound' => 2,
            'bots' => 7,
        ], $summary['current']);
        self::assertSame([
            'pageviews' => 8,
            'visits' => 6,
            'notFound' => 0,
            'bots' => 0,
        ], $summary['previous']);
    }

    /**
     * The trend chart says what it compares with (here: the same days last
     * year), and the headline compares the same.
     */
    public function testComparesWithWhatTheTrendComparesWith(): void
    {
        $this->pipeline->daily([
            '2026-11-10' => [10, 5],
            '2025-11-11' => [4, 2],
        ]);

        $summary = $this->pipeline->summaryReport()
            ->summary('2026-11-09', '2026-11-15', '2025-11-10', '2025-11-14');

        self::assertSame(['2025-11-10', '2025-11-14'], [$summary['previousFrom'], $summary['previousTo']]);
        self::assertSame(4, $summary['previous']['pageviews'] ?? null);
    }

    public function testNoComparison(): void
    {
        $summary = $this->pipeline->summaryReport()
            ->summary('2026-11-09', '2026-11-15', compare: false);

        self::assertSame([null, null, null], [$summary['previousFrom'], $summary['previousTo'], $summary['previous']]);
    }

    public function testRangeInThePastStaysAsItIs(): void
    {
        $summary = $this->pipeline->summaryReport()
            ->summary('2026-10-01', '2026-10-31');

        self::assertSame(['2026-10-01', '2026-10-31', '2026-08-31', '2026-09-30'], [$summary['from'], $summary['to'], $summary['previousFrom'], $summary['previousTo']]);
    }
}
