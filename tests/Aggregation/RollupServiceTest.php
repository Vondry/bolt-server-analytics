<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Aggregation;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Throwable;
use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Tests\Support\Database;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;

#[Group('database')]
final class RollupServiceTest extends TestCase
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

    public function testEveryAggregateGetsItsRows(): void
    {
        $this->write(...self::sample());

        $result = $this->pipeline->rollupService()
            ->rollup();

        self::assertSame([10, 0, false], [$result->processed, $result->pending, $result->busy]);

        $tables = $this->pipeline->database->tables;
        self::assertSame([], $this->rows($tables->hit()), 'raw rows are deleted once they are counted');
        self::assertSame([
            [
                'day' => '2026-11-12',
                'pageviews' => 1,
                'entries' => 1,
                'not_found' => 0,
                'bots' => 0,
                'alias' => 0,
            ],
            [
                'day' => '2026-11-13',
                'pageviews' => 5,
                'entries' => 3,
                'not_found' => 2,
                'bots' => 1,
                'alias' => 1,
            ],
        ], $this->rows($tables->daily(), 'day'));
        self::assertSame([
            [
                'day' => '2026-11-12',
                'hour' => 23,
                'hits' => 1,
            ],
            [
                'day' => '2026-11-13',
                'hour' => 9,
                'hits' => 1,
            ],
            [
                'day' => '2026-11-13',
                'hour' => 10,
                'hits' => 4,
            ],
        ], $this->rows($tables->hourly(), 'day, hour'));
        // One group of dimensions per table: a page, a client, a locale.
        self::assertSame([
            ['day' => '2026-11-12', 'path' => '/', 'is_entry' => 1, 'hits' => 1],
            ['day' => '2026-11-13', 'path' => '/', 'is_entry' => 1, 'hits' => 3],
            ['day' => '2026-11-13', 'path' => '/program', 'is_entry' => 0, 'hits' => 2],
        ], $this->rows($tables->pages(), 'day, path, is_entry'));
        self::assertSame([
            ['day' => '2026-11-12', 'device' => 'desktop', 'browser' => 'firefox', 'os' => 'linux', 'is_entry' => 1, 'hits' => 1],
            ['day' => '2026-11-13', 'device' => 'mobile', 'browser' => 'safari', 'os' => 'ios', 'is_entry' => 0, 'hits' => 2],
            ['day' => '2026-11-13', 'device' => 'mobile', 'browser' => 'safari', 'os' => 'ios', 'is_entry' => 1, 'hits' => 3],
        ], $this->rows($tables->clients(), 'day, is_entry'));
        self::assertSame([
            ['day' => '2026-11-12', 'lang' => 'sk', 'is_entry' => 1, 'hits' => 1],
            ['day' => '2026-11-13', 'lang' => 'cs', 'is_entry' => 0, 'hits' => 2],
            ['day' => '2026-11-13', 'lang' => 'cs', 'is_entry' => 1, 'hits' => 3],
        ], $this->rows($tables->locales(), 'day, is_entry'));
        self::assertSame([
            [
                'day' => '2026-11-12',
                'path' => '/',
                'channel' => 'direct',
                'source' => '',
                'campaign' => '',
                'hits' => 1,
            ],
            [
                'day' => '2026-11-13',
                'path' => '/',
                'channel' => 'social',
                'source' => 'facebook',
                'campaign' => 'léto',
                'hits' => 3,
            ],
        ], $this->rows($tables->sources(), 'day'));
        self::assertSame([[
            'day' => '2026-11-13',
            'from_path' => '/',
            'to_path' => '/program',
            'hits' => 2,
        ]], $this->rows($tables->transitions()));
        self::assertSame([[
            'day' => '2026-11-13',
            'term' => 'vstupenky',
            'hits' => 1,
        ]], $this->rows($tables->search()));
        self::assertSame([
            [
                'day' => '2026-11-13',
                'via' => 'rockfestkurim.cz',
                'path' => '/',
                'channel' => 'direct',
                'source' => '',
                'hits' => 1,
            ],
            [
                'day' => '2026-11-13',
                'via' => 'rockfestkurim.cz',
                'path' => '/',
                'channel' => 'social',
                'source' => 'facebook',
                'hits' => 1,
            ],
        ], $this->rows($tables->aliases(), 'channel'), 'the hop and the tagged page view');
        self::assertSame([
            [
                'day' => '2026-11-13',
                'path' => '/stary-program',
                'ref' => '',
                'hits' => 1,
            ],
            [
                'day' => '2026-11-13',
                'path' => '/stary-program',
                'ref' => '/kapely',
                'hits' => 1,
            ],
        ], $this->rows($tables->notFound(), 'ref'));
        self::assertSame([[
            'day' => '2026-11-13',
            'bot' => 'googlebot',
            'hits' => 1,
        ]], $this->rows($tables->bots()));
    }

    public function testLaterRunsAddToTheCounters(): void
    {
        $service = $this->pipeline->rollupService();

        $this->write(...self::sample());
        $service->rollup();
        $this->write(...self::sample());
        $result = $service->rollup();

        self::assertSame(10, $result->processed);
        $tables = $this->pipeline->database->tables;
        self::assertSame([2, 10], array_column($this->rows($tables->daily(), 'day'), 'pageviews'));
        self::assertSame([[
            'day' => '2026-11-13',
            'from_path' => '/',
            'to_path' => '/program',
            'hits' => 4,
        ]], $this->rows($tables->transitions()));
        self::assertCount(3, $this->rows($tables->pages()), 'no new rows, only higher counters');
    }

    public function testChunksGiveTheSameResultAsOneRun(): void
    {
        $this->write(...self::sample());
        $this->pipeline->rollupService()
            ->rollup();
        $expected = $this->snapshot();

        $chunked = new Pipeline();
        try {
            $chunked->project->configure([
                'aggregation' => [
                    'chunk_size' => 3,
                ],
            ]);
            foreach (self::sample() as $hit) {
                $chunked->rawHitWriter()
                    ->write($hit);
            }

            self::assertSame(10, $chunked->rollupService()->rollup()->processed);

            $this->pipeline = $chunked;
            self::assertSame($expected, $this->snapshot());
        } finally {
            $chunked->remove();
        }
    }

    public function testTimeBudgetStopsAfterAChunkAndTheNextRunContinues(): void
    {
        $this->pipeline->project->configure([
            'aggregation' => [
                'chunk_size' => 4,
                'time_budget_ms' => 0,
            ],
        ]);
        $this->write(...self::sample());
        $service = $this->pipeline->rollupService();

        $first = $service->rollup();
        self::assertSame([4, 6], [$first->processed, $first->pending], 'every run makes progress, even without a budget');

        $second = $service->rollup();
        self::assertSame([4, 2], [$second->processed, $second->pending]);

        $third = $service->rollup(60_000);
        self::assertSame([2, 0], [$third->processed, $third->pending], 'an explicit budget wins over the config');

        self::assertSame([1, 5], array_column($this->rows($this->pipeline->database->tables->daily(), 'day'), 'pageviews'));
    }

    public function testSpoolIsMovedIntoTheDatabaseFirst(): void
    {
        foreach (self::sample() as $hit) {
            $this->pipeline->spoolWriter()
                ->write($hit);
        }
        $service = $this->pipeline->rollupService();

        self::assertSame(10, $service->pending(), 'spool lines count as pending');

        $result = $service->rollup();

        self::assertSame([10, 0], [$result->processed, $result->pending]);
        self::assertSame([1, 5], array_column($this->rows($this->pipeline->database->tables->daily(), 'day'), 'pageviews'));
        self::assertSame([], glob($this->pipeline->project->spoolDir() . '/*'));
    }

    public function testNothingToDo(): void
    {
        $result = $this->pipeline->rollupService()
            ->rollup();

        self::assertSame([0, 0, false], [$result->processed, $result->pending, $result->busy]);
        self::assertSame([], $this->rows($this->pipeline->database->tables->daily()));
    }

    public function testRemembersWhenItRan(): void
    {
        $service = $this->pipeline->rollupService();
        self::assertNull($service->aggregatedUntil(), 'before the first run');

        $service->rollup();
        self::assertSame('2026-11-13T10:52:00+01:00', $service->aggregatedUntil()?->format(DATE_ATOM), 'in the site timezone');

        $this->pipeline->project->clock->modify('+1 hour');
        $this->write(...self::sample());
        $service->rollup();
        self::assertSame('2026-11-13T11:52:00+01:00', $service->aggregatedUntil()?->format(DATE_ATOM));

        self::assertSame(1, $this->pipeline->database->count($this->pipeline->database->tables->state()), 'one state row');
    }

    public function testSecondRollupAtTheSameTimeIsBusy(): void
    {
        $this->write(...self::sample());
        $lock = (new LockFactory(new FlockStore($this->lockDirectory())))->createLock('rollup');
        self::assertTrue($lock->acquire());

        try {
            $busy = $this->pipeline->rollupService()
                ->rollup();
        } finally {
            $lock->release();
        }

        self::assertSame([0, 10, true], [$busy->processed, $busy->pending, $busy->busy]);
        self::assertSame(10, $this->pipeline->rollupService()->rollup()->processed, 'free again');
    }

    public function testDetailedAggregatesAreDeletedAfterTheRetention(): void
    {
        $this->pipeline->project->configure([
            'privacy' => [
                'retention_months' => 2,
            ],
        ]);
        // Today is 2026-11-13: September is the oldest month that is kept.
        $this->write(
            new Hit('2026-08-31', 10, Hit::KIND_PAGEVIEW, '/old', 'desktop', 'chrome', 'windows', 'cs', 'direct', isEntry: true),
            new Hit('2026-09-01', 10, Hit::KIND_PAGEVIEW, '/kept', 'desktop', 'chrome', 'windows', 'cs', 'direct', isEntry: true),
            new Hit('2026-08-31', 10, Hit::KIND_BOT, '', bot: 'googlebot'),
        );

        $this->pipeline->rollupService()
            ->rollup();

        $tables = $this->pipeline->database->tables;
        self::assertSame(['/kept'], array_column($this->rows($tables->pages()), 'path'));
        self::assertSame(['/kept'], array_column($this->rows($tables->sources()), 'path'));
        self::assertSame(['2026-09-01'], array_column($this->rows($tables->hourly()), 'day'));
        self::assertSame([], $this->rows($tables->bots()));
        self::assertSame(['2026-08-31', '2026-09-01'], array_column($this->rows($tables->daily(), 'day'), 'day'), 'the daily totals behind the trend stay');
    }

    public function testRetentionCleanupRunsOnceADay(): void
    {
        $this->pipeline->project->configure([
            'privacy' => [
                'retention_months' => 2,
            ],
        ]);
        $service = $this->pipeline->rollupService();
        $service->rollup();

        // Old data that arrives later the same day (a spool file of a server
        // that was offline) stays until tomorrow's cleanup.
        $this->write(new Hit('2026-08-31', 10, Hit::KIND_PAGEVIEW, '/old', 'desktop', 'chrome', 'windows', 'cs', 'direct'));
        $service->rollup();
        self::assertSame(['/old'], array_column($this->rows($this->pipeline->database->tables->pages()), 'path'));

        $this->pipeline->project->clock->modify('+1 day');
        $service->rollup();
        self::assertSame([], $this->rows($this->pipeline->database->tables->pages()));
    }

    public function testRetentionOfZeroKeepsEverything(): void
    {
        $this->pipeline->project->configure([
            'privacy' => [
                'retention_months' => 0,
            ],
        ]);
        $this->write(new Hit('2001-01-01', 10, Hit::KIND_PAGEVIEW, '/old', 'desktop', 'chrome', 'windows', 'cs', 'direct'));

        $this->pipeline->rollupService()
            ->rollup();

        self::assertSame(['/old'], array_column($this->rows($this->pipeline->database->tables->pages()), 'path'));
    }

    public function testFailedChunkLeavesTheRawRowsForTheNextRun(): void
    {
        $this->write(...self::sample());
        $tables = $this->pipeline->database->tables;
        $connection = $this->pipeline->database->connection;
        $connection->executeStatement(sprintf('ALTER TABLE %s RENAME TO bolt_ra_bots_away', $tables->bots()));

        try {
            $this->pipeline->rollupService()
                ->rollup();
            self::fail('The rollup succeeded without one of its tables.');
        } catch (TableNotFoundException) {
        } finally {
            // The lock was released: with the table back, the next run does it all.
            $connection->executeStatement(sprintf('ALTER TABLE bolt_ra_bots_away RENAME TO %s', $tables->bots()));
        }

        self::assertFalse($this->pipeline->database->connection->isTransactionActive());
        self::assertSame(10, $this->pipeline->database->count($tables->hit()), 'nothing was deleted');
        self::assertSame([], $this->rows($tables->daily()), 'and nothing was counted');

        self::assertSame(10, $this->pipeline->rollupService()->rollup()->processed);
    }

    /**
     * A row with an id below the chunk's bound that commits while the chunk
     * runs (an INSERT that started earlier) must neither be deleted uncounted
     * nor counted twice: the chunk is rolled back and done again.
     */
    public function testRowCommittedDuringAChunkIsCountedExactlyOnce(): void
    {
        $ids = $this->writeWithFreeIds(5, [4]);
        $late = $this->lateWriter();
        $database = $this->pipeline->database;
        $database->log->after('~^SELECT COUNT\(\*\) FROM bolt_ra_hit WHERE id <=~', function () use (&$ids, $late): void {
            if ($ids !== []) {
                $late(array_shift($ids));
            }
        });

        $result = $this->pipeline->rollupService()
            ->rollup();

        // With a second connection the late row is committed and counted; within
        // the same connection (SQLite) the rollback takes it away again.
        $expected = $database->secondConnection() instanceof Connection ? 5 : 4;
        self::assertSame([$expected, 0], [$result->processed, $result->pending]);
        self::assertSame([$expected], array_column($this->rows($database->tables->daily()), 'pageviews'));
        self::assertCount(2, $database->log->matching('~^SELECT COUNT\(\*\) FROM bolt_ra_hit WHERE id <=~'), 'the chunk ran twice');
        self::assertFalse($database->connection->isTransactionActive());
    }

    public function testChunkThatKeepsChangingIsGivenUpAfterFiveRetries(): void
    {
        $ids = $this->writeWithFreeIds(10, [2, 3, 4, 5, 6, 7]);
        $late = $this->lateWriter();
        $database = $this->pipeline->database;
        $database->log->after('~^SELECT COUNT\(\*\) FROM bolt_ra_hit WHERE id <=~', function () use (&$ids, $late): void {
            if ($ids !== []) {
                $late(array_shift($ids));
            }
        });

        try {
            $this->pipeline->rollupService()
                ->rollup();
            self::fail('The rollup went on forever.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('raw rows kept changing', $exception->getMessage());
        }

        self::assertSame([], $this->rows($database->tables->daily()), 'nothing was counted');
        self::assertSame($database->secondConnection() instanceof Connection ? 10 : 4, $database->count($database->tables->hit()), 'and nothing deleted');
        self::assertFalse($database->connection->isTransactionActive());
        self::assertTrue($this->pipeline->rollupService()->rollup()->processed > 0, 'the lock was released');
    }

    /**
     * The collector's INSERT must not wait for a running chunk. MySQL's default
     * REPEATABLE READ would lock the gap after the newest row until the commit.
     */
    public function testCollectorIsNotBlockedWhileAChunkRuns(): void
    {
        $database = $this->pipeline->database;
        $collector = $database->secondConnection();
        if (! $collector instanceof Connection) {
            self::markTestSkipped('Needs a database server (RA_TEST_DATABASE_URL): SQLite allows one writer at a time, hence the spool.');
        }

        $collector->executeStatement($database->isMysql() ? 'SET SESSION innodb_lock_wait_timeout = 1' : "SET lock_timeout = '1s'");
        $this->write(...self::sample());
        $failure = null;
        $database->log->after('~^DELETE FROM bolt_ra_hit WHERE id <=~', function () use ($collector, &$failure): void {
            try {
                $collector->insert($this->pipeline->database->tables->hit(), (new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/meanwhile'))->toRow(), Hit::TYPES);
            } catch (Throwable $exception) {
                $failure = $exception;
            }
        });

        $result = $this->pipeline->rollupService()
            ->rollup();

        self::assertNull($failure, 'the collector waited for the rollup: ' . $failure?->getMessage());
        self::assertSame([10, 1], [$result->processed, $result->pending], 'the new row waits for the next run');
    }

    public function testMissingTablesAreReported(): void
    {
        $pipeline = new Pipeline($this->pipeline->project, new Database(false));

        $this->expectException(TableNotFoundException::class);

        $pipeline->rollupService()
            ->rollup();
    }

    /**
     * Two days, every kind of row.
     *
     * @return list<Hit>
     */
    private static function sample(): array
    {
        $entry = new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/', 'mobile', 'safari', 'ios', 'cs', 'social', 'facebook', 'léto', true);
        $internal = new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/program', 'mobile', 'safari', 'ios', 'cs', 'internal', ref: '/');

        return [
            $entry,
            // The same visitor group again, with a search term and the alias marker.
            new Hit('2026-11-13', 9, Hit::KIND_PAGEVIEW, '/', 'mobile', 'safari', 'ios', 'cs', 'social', 'facebook', 'léto', true, via: 'rockfestkurim.cz', term: 'vstupenky'),
            new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/', 'mobile', 'safari', 'ios', 'cs', 'social', 'facebook', 'léto', true, true),
            $internal,
            $internal,
            new Hit('2026-11-12', 23, Hit::KIND_PAGEVIEW, '/', 'desktop', 'firefox', 'linux', 'sk', 'direct', isEntry: true),
            new Hit('2026-11-13', 10, Hit::KIND_NOT_FOUND, '/stary-program', ref: '/kapely'),
            new Hit('2026-11-13', 10, Hit::KIND_NOT_FOUND, '/stary-program'),
            new Hit('2026-11-13', 10, Hit::KIND_BOT, '', bot: 'googlebot'),
            new Hit('2026-11-13', 10, Hit::KIND_ALIAS, '/', channel: 'direct', via: 'rockfestkurim.cz'),
        ];
    }

    /**
     * Writes page views with the ids 1 … $count and deletes those of $free, for
     * rows that commit late with a smaller id.
     *
     * @param list<int> $free
     *
     * @return list<int> $free
     */
    private function writeWithFreeIds(int $count, array $free): array
    {
        for ($index = 1; $index <= $count; ++$index) {
            $this->write(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/page-' . $index, 'desktop', 'firefox', 'linux', 'cs', 'direct', isEntry: true));
        }

        $this->pipeline->database->connection->executeStatement(
            sprintf('DELETE FROM %s WHERE id IN (%s)', $this->pipeline->database->tables->hit(), implode(', ', $free))
        );
        $this->pipeline->database->log->clear();

        return $free;
    }

    /**
     * Commits a page view with the given id from another connection, like a
     * collector of another request; on SQLite, where a second writer has to
     * wait, from the rollup's own connection.
     *
     * @return Closure(int): void
     */
    private function lateWriter(): Closure
    {
        $database = $this->pipeline->database;
        $connection = $database->secondConnection() ?? $database->connection;

        return static function (int $id) use ($connection, $database): void {
            $hit = new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/late-' . $id, 'desktop', 'firefox', 'linux', 'cs', 'direct', isEntry: true);
            $connection->insert($database->tables->hit(), [
                'id' => $id,
            ] + $hit->toRow(), Hit::TYPES);
        };
    }

    private function write(Hit ...$hits): void
    {
        foreach ($hits as $hit) {
            $this->pipeline->rawHitWriter()
                ->write($hit);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, string $orderBy = '1'): array
    {
        return $this->pipeline->database->rows($table, $orderBy);
    }

    /**
     * @return array<string, list<array<string, mixed>>> every aggregate, in a stable order
     */
    private function snapshot(): array
    {
        $tables = $this->pipeline->database->tables;
        $snapshot = [];
        foreach ([$tables->daily(), $tables->hourly(), $tables->pages(), $tables->clients(), $tables->locales(), $tables->sources(), $tables->transitions(), $tables->search(), $tables->aliases(), $tables->notFound(), $tables->bots()] as $table) {
            $rows = $this->rows($table);
            usort($rows, static fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));
            $snapshot[$table] = $rows;
        }

        return $snapshot;
    }

    private function lockDirectory(): string
    {
        $directory = $this->pipeline->project->dir . '/var/request-analytics';
        if (! is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        return $directory;
    }
}
