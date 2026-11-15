<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Aggregation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Exception\LockConflictedException;
use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;

/**
 * Today is 2026-11-13 (Project); "the last 7 days" start on 2026-11-07.
 */
#[Group('database')]
final class StatisticsEraserTest extends TestCase
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

    public function testFirstDayCountsTodayAsTheFirstOfTheDays(): void
    {
        $eraser = $this->pipeline->eraser();

        self::assertSame('2026-11-13', $eraser->firstDay(1));
        self::assertSame('2026-11-07', $eraser->firstDay(7));
        self::assertSame('2026-08-16', $eraser->firstDay(90));
        self::assertNull($eraser->firstDay(null), 'everything');
    }

    public function testFirstDayRejectsAPeriodThatIsNoPeriod(): void
    {
        foreach ([0, -1, 36_501] as $days) {
            try {
                $this->pipeline->eraser()
                    ->firstDay($days);
                self::fail(sprintf('%d days were accepted.', $days));
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('"days"', $exception->getMessage());
            }
        }
    }

    public function testDeletesTheDaysOfThePeriodFromEveryTableAndKeepsTheOlderOnes(): void
    {
        $this->fillEveryTable('2026-11-06');
        $this->fillEveryTable('2026-11-07');
        $this->fillEveryTable('2026-11-13');

        $result = $this->pipeline->eraser()
            ->erase(7);

        self::assertSame('2026-11-07', $result['from']);
        self::assertSame(2 * 12, $result['rows'], 'one row per table and day, the raw row included');
        foreach ($this->tables() as $table) {
            self::assertSame(['2026-11-06'], $this->days($table), $table);
        }
    }

    /**
     * The insights must not take the deleted days for an outage: the period
     * is remembered, merged with one it overlaps or touches.
     */
    public function testRemembersTheDeletedPeriod(): void
    {
        $eraser = $this->pipeline->eraser();
        $state = $this->pipeline->state();

        $eraser->erase(7);
        self::assertSame([[
            'from' => '2026-11-07',
            'to' => '2026-11-13',
        ]], $state->erased(), 'without a rollup before: the bookkeeping row is created');

        $eraser->erase(3);
        self::assertSame([[
            'from' => '2026-11-07',
            'to' => '2026-11-13',
        ]], $state->erased(), 'within the period');

        $state->erase('2026-09-01', '2026-09-30');
        $state->erase('2026-10-01', '2026-10-05');
        $state->erase('2026-03-01', '2026-03-31');
        self::assertSame([[
            'from' => '2026-09-01',
            'to' => '2026-10-05',
        ], [
            'from' => '2026-11-07',
            'to' => '2026-11-13',
        ]], $state->erased(), 'the touching ones merged; one that ended half a year before is forgotten');

        $eraser->erase(null);
        self::assertSame([], $state->erased(), 'nothing is left to doubt');
    }

    public function testDeletesEverything(): void
    {
        $this->fillEveryTable('2023-01-01');
        $this->fillEveryTable('2026-11-13');
        $this->pipeline->state()
            ->ensure();

        $result = $this->pipeline->eraser()
            ->erase(null);

        self::assertNull($result['from']);
        self::assertSame(2 * 12, $result['rows']);
        foreach ($this->tables() as $table) {
            self::assertSame(0, $this->pipeline->database->count($table), $table);
        }
        self::assertSame(1, $this->pipeline->database->count($this->pipeline->database->tables->state()), 'the bookkeeping stays');
    }

    /**
     * Hits that wait in the spool were not counted yet: those of the period
     * go, unread, those of older days stay for the rollup.
     */
    public function testDeletesTheSpoolFilesOfThePeriod(): void
    {
        $writer = $this->pipeline->spoolWriter();
        $writer->write(new Hit('2026-11-06', 23, Hit::KIND_PAGEVIEW, '/old'));
        $writer->write(new Hit('2026-11-07', 0, Hit::KIND_PAGEVIEW, '/new'));
        $writer->write(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/today'));
        // Claimed by a rollup that stopped halfway, with its position saved.
        $spool = $this->pipeline->project->spoolDir();
        rename($spool . '/2026111310.ndjson', $spool . '/2026111310.ndjson.abcd1234.ingest');
        $this->pipeline->state()
            ->saveSpoolOffset('2026111310.ndjson.abcd1234.ingest', 10);
        // The file of another web server, in the same database.
        $this->pipeline->state()
            ->saveSpoolOffset('2026111309.ndjson.ffff0000.ingest', 20);

        $result = $this->pipeline->eraser()
            ->erase(7);

        self::assertSame(2, $result['spoolFiles']);
        self::assertSame(['2026110623.ndjson'], array_values(array_diff((array) scandir($spool), ['.', '..'])));
        self::assertSame([
            '2026111309.ndjson.ffff0000.ingest' => 20,
        ], $this->pipeline->state()->spoolOffsets(), 'the position went with its file; that of another server stays');

        $this->pipeline->rollupService()
            ->rollup();
        self::assertSame([['day' => '2026-11-06', 'path' => '/old']], $this->pipeline->database->connection->fetchAllAssociative(sprintf('SELECT day, path FROM %s', $this->pipeline->database->tables->pages())));
    }

    public function testKeepsThePositionOfAnOlderSpoolFile(): void
    {
        $this->pipeline->spoolWriter()
            ->write(new Hit('2026-11-01', 8, Hit::KIND_PAGEVIEW, '/old'));
        $this->pipeline->state()
            ->saveSpoolOffset('2026110108.ndjson.abcd1234.ingest', 5);

        $this->pipeline->eraser()
            ->erase(7);

        self::assertSame([
            '2026110108.ndjson.abcd1234.ingest' => 5,
        ], $this->pipeline->state()->spoolOffsets());
    }

    public function testWaitsForNoRollupButGivesUpWhileOneRuns(): void
    {
        $this->fillEveryTable('2026-11-13');
        $rollup = $this->pipeline->rollupService()
            ->lock();
        self::assertTrue($rollup->acquire());

        try {
            $this->pipeline->eraser(50)
                ->erase(1);
            self::fail('Deleted while a rollup ran.');
        } catch (LockConflictedException) {
            self::assertSame(1, $this->pipeline->database->count($this->pipeline->database->tables->daily()), 'nothing deleted');
        } finally {
            $rollup->release();
        }

        self::assertSame(12, $this->pipeline->eraser()->erase(1)['rows'], 'once the rollup is done');
    }

    public function testPreviewCountsThePageViewsAndVisitsOfThePeriodAlsoThoseNotAggregatedYet(): void
    {
        $this->pipeline->daily([
            '2026-11-06' => [100, 40],
            '2026-11-07' => [10, 4],
            '2026-11-13' => [5, 2],
        ]);
        $writer = $this->pipeline->rawHitWriter();
        $writer->write(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/', isEntry: true));
        $writer->write(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/next'));
        $writer->write(new Hit('2026-11-13', 10, Hit::KIND_BOT, '', bot: 'curl'));

        $eraser = $this->pipeline->eraser();

        self::assertSame([
            'from' => '2026-11-07',
            'to' => '2026-11-13',
            'firstDay' => '2026-11-07',
            'pageviews' => 17,
            'visits' => 7,
        ], $eraser->preview(7));
        self::assertSame([
            'from' => null,
            'to' => '2026-11-13',
            'firstDay' => '2026-11-06',
            'pageviews' => 117,
            'visits' => 47,
        ], $eraser->preview(null));
    }

    public function testPreviewOfNoStatistics(): void
    {
        self::assertSame([
            'from' => '2026-11-13',
            'to' => '2026-11-13',
            'firstDay' => null,
            'pageviews' => 0,
            'visits' => 0,
        ], $this->pipeline->eraser()->preview(1));
    }

    /**
     * One row in every table with a day: the raw table and the aggregates.
     */
    private function fillEveryTable(string $day): void
    {
        $this->pipeline->rawHitWriter()
            ->write(new Hit($day, 10, Hit::KIND_PAGEVIEW, '/raw'));
        $this->pipeline->daily([
            $day => [3, 1],
        ]);
        $this->pipeline->hourly($day, 10, 3);
        $this->pipeline->pages($day, '/', 3);
        $this->pipeline->clients($day, 3);
        $this->pipeline->locales($day, 'en', 3);
        $this->pipeline->sources($day, '/', 1, 'search', 'google');
        $this->pipeline->transitions($day, '/', '/about', 1);
        $this->pipeline->search($day, 'price', 1);
        $this->pipeline->aliases($day, 'example.org', '/', 1);
        $this->pipeline->notFound($day, '/missing', 1);
        $this->pipeline->bots($day, 'curl', 1);
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        $tables = $this->pipeline->database->tables;

        return [$tables->hit(), $tables->daily(), $tables->hourly(), $tables->pages(), $tables->clients(), $tables->locales(), $tables->sources(), $tables->transitions(), $tables->search(), $tables->aliases(), $tables->notFound(), $tables->bots()];
    }

    /**
     * @return list<string>
     */
    private function days(string $table): array
    {
        return array_map(static fn (mixed $day): string => is_string($day) ? mb_substr($day, 0, 10) : '', $this->pipeline->database->connection->fetchFirstColumn(sprintf('SELECT DISTINCT day FROM %s ORDER BY day', $table)));
    }
}
