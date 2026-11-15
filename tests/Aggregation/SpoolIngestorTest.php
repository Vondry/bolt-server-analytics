<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Aggregation;

use Doctrine\DBAL\Exception\TableNotFoundException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Tests\Support\Database;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;
use TomVondracek\RequestAnalytics\Tests\Support\WriterProcess;

#[Group('database')]
final class SpoolIngestorTest extends TestCase
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

    public function testMovesEveryLineAndDeletesTheFiles(): void
    {
        $this->spool(new Hit('2026-11-13', 9, Hit::KIND_PAGEVIEW, '/a', isEntry: true), new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/b', ref: '/a'), new Hit('2026-11-13', 10, Hit::KIND_BOT, '', bot: 'curl'));
        $ingestor = $this->pipeline->spoolIngestor();

        self::assertSame(3, $ingestor->pending());
        self::assertSame(3, $ingestor->ingest(PHP_INT_MAX));
        self::assertSame(0, $ingestor->pending());

        $rows = $this->pipeline->database->rows($this->pipeline->database->tables->hit(), 'id');
        self::assertSame(['/a', '/b', ''], array_column($rows, 'path'), 'oldest file first');
        self::assertSame([1, 0, 0], array_column($rows, 'is_entry'));
        self::assertSame([null, '/a', null], array_column($rows, 'ref'));
        self::assertSame([], $this->files());
    }

    public function testManyRowsGoInBatches(): void
    {
        $hits = [];
        for ($index = 0; $index < 2345; ++$index) {
            $hits[] = new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/page-' . $index);
        }
        $this->spool(...$hits);

        self::assertSame(2345, $this->pipeline->spoolIngestor()->ingest(PHP_INT_MAX));
        self::assertSame(2345, $this->pipeline->database->count($this->pipeline->database->tables->hit()));
    }

    public function testLinesThatAreNoHitsAreSkipped(): void
    {
        $this->spool(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/ok'));
        file_put_contents($this->pipeline->project->spoolDir() . '/2026111310.ndjson', "not json\n{\"day\":\"2026-11-13\"}\n[1,2]\n\n{\"day\":\"2026-11-13\",\"hour\":10,\"kind\":\"pageview\",\"path\":\"/trunca", FILE_APPEND);

        self::assertSame(1, $this->pipeline->spoolIngestor()->ingest(PHP_INT_MAX));
        self::assertSame([], $this->files());
    }

    public function testOtherFilesAreLeftAlone(): void
    {
        $this->spool(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/ok'));
        file_put_contents($this->pipeline->project->spoolDir() . '/README.txt', 'not a spool file');

        $this->pipeline->spoolIngestor()
            ->ingest(PHP_INT_MAX);

        self::assertSame(['README.txt'], $this->files());
    }

    public function testStopsAfterTheDeadlineAndContinuesNextTime(): void
    {
        $this->spool(new Hit('2026-11-13', 9, Hit::KIND_PAGEVIEW, '/a'), new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/b'));
        $ingestor = $this->pipeline->spoolIngestor();

        self::assertSame(1, $ingestor->ingest(0), 'one file per run at least');
        self::assertSame(['2026111310.ndjson'], $this->files());
        self::assertSame(1, $ingestor->pending());
        self::assertSame(1, $ingestor->ingest(0));
    }

    /**
     * One busy hour is one big file: the time budget applies within it, and the
     * next run (another process) continues where this one stopped.
     */
    public function testLargeFileIsSpreadOverRunsWithinTheBudget(): void
    {
        $this->spool(...self::pageviews(2500));

        self::assertSame(1000, $this->pipeline->spoolIngestor()->ingest(0), 'one batch, then the budget is over');
        self::assertCount(1, $this->files(), 'the file stays, claimed');
        self::assertStringEndsWith('.ingest', $this->files()[0]);
        self::assertSame(1500, $this->pipeline->spoolIngestor()->pending());

        self::assertSame(1000, $this->pipeline->spoolIngestor()->ingest(0));
        self::assertSame(500, $this->pipeline->spoolIngestor()->ingest(0));
        self::assertSame([], $this->files());

        $paths = array_column($this->pipeline->database->rows($this->pipeline->database->tables->hit(), 'id'), 'path');
        self::assertSame(array_map(static fn (int $index): string => '/page-' . $index, range(0, 2499)), $paths, 'every line exactly once, in order');
    }

    /**
     * The rows and the position in the file are committed together, so a
     * process that dies before it deletes the file doesn't count it again.
     */
    /**
     * A busy hour: the rollup stops halfway through the claimed file, visitors
     * of the same hour start a new file (which sorts first), and the next run
     * runs out of time right after that new file. The saved position must still
     * belong to the half-read file.
     */
    public function testNewFileOfTheSameHourDoesNotTakeOverThePosition(): void
    {
        $this->spool(...self::pageviews(2500));
        self::assertSame(1000, $this->pipeline->spoolIngestor()->ingest(0));

        $this->spool(...array_map(static fn (int $index): Hit => new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/later-' . $index), range(0, 9)));
        $this->pipeline->spoolIngestor()
            ->ingest(0);
        $this->pipeline->spoolIngestor()
            ->ingest(PHP_INT_MAX);

        self::assertSame([], $this->files());
        $paths = array_column($this->pipeline->database->rows($this->pipeline->database->tables->hit(), 'id'), 'path');
        self::assertSame([
            ...array_map(static fn (int $index): string => '/page-' . $index, range(0, 2499)),
            ...array_map(static fn (int $index): string => '/later-' . $index, range(0, 9)),
        ], $paths, 'every line exactly once, the half-read file finished first');
    }

    /**
     * Several web servers move their own spools into one database: the
     * position of another server's file does not replace that of this one's
     * half-read file.
     */
    public function testAnotherServerDoesNotTakeOverThePosition(): void
    {
        $this->spool(...self::pageviews(1500));
        self::assertSame(1000, $this->pipeline->spoolIngestor()->ingest(0));

        // Meanwhile, the rollup of another server, with a file of its own.
        $this->pipeline->state()
            ->saveSpoolOffset('2026111310.ndjson.ffff0000.ingest', 123);

        self::assertSame(500, $this->pipeline->spoolIngestor()->ingest(PHP_INT_MAX));
        self::assertSame([], $this->files());
        self::assertSame(1500, $this->pipeline->database->count($this->pipeline->database->tables->hit()), 'every line exactly once');
        self::assertSame([
            '2026111310.ndjson.ffff0000.ingest' => 123,
        ], $this->pipeline->state()->spoolOffsets(), 'only the position of the file that is done went');
    }

    public function testFileThatWasStoredButNotDeletedIsNotCountedTwice(): void
    {
        $this->spool(...self::pageviews(1500));
        $this->pipeline->spoolIngestor()
            ->ingest(0);
        $claimed = $this->pipeline->project->spoolDir() . '/' . $this->files()[0];
        $content = (string) file_get_contents($claimed);

        self::assertSame(500, $this->pipeline->spoolIngestor()->ingest(PHP_INT_MAX));
        // The process died right after the commit, before unlink(): the file
        // and its position, at its end, are still there.
        file_put_contents($claimed, $content);
        $this->pipeline->state()
            ->saveSpoolOffset(basename($claimed), mb_strlen($content, '8bit'));

        self::assertSame(0, $this->pipeline->spoolIngestor()->ingest(PHP_INT_MAX));
        self::assertSame([], $this->files());
        self::assertSame(1500, $this->pipeline->database->count($this->pipeline->database->tables->hit()));
    }

    public function testPendingOfALargeSpoolIsEstimatedFromASample(): void
    {
        // ≈ 3 MB: well beyond what pending() reads.
        // Multibyte characters: the sample is measured in bytes, like the files.
        $line = json_encode((new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/kapely/žluťoučký-kůň'))->toRow(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        mkdir($this->pipeline->project->spoolDir(), 0o777, true);
        file_put_contents($this->pipeline->project->spoolDir() . '/2026111309.ndjson', str_repeat($line, 6000));
        file_put_contents($this->pipeline->project->spoolDir() . '/2026111310.ndjson', str_repeat($line, 4000));

        self::assertGreaterThan(2 << 20, 10_000 * mb_strlen($line, '8bit'));
        self::assertEqualsWithDelta(10_000, $this->pipeline->spoolIngestor()->pending(), 10);
    }

    /**
     * Collectors keep writing while the rollup claims and reads their files.
     */
    public function testConcurrentCollectorsLoseNoLine(): void
    {
        $writers = [];
        for ($index = 0; $index < 4; ++$index) {
            $writers[] = new WriterProcess($this->pipeline->project, 1500);
        }
        foreach ($writers as $writer) {
            $writer->waitUntilReady();
        }

        $ingestor = $this->pipeline->spoolIngestor();
        $ingested = 0;
        while (array_filter($writers, static fn (WriterProcess $writer): bool => $writer->isRunning()) !== []) {
            $ingested += $ingestor->ingest(PHP_INT_MAX);
        }
        foreach ($writers as $writer) {
            $writer->finish();
        }
        $ingested += $ingestor->ingest(PHP_INT_MAX);

        self::assertSame(6000, $ingested);
        self::assertSame(6000, $this->pipeline->database->count($this->pipeline->database->tables->hit()));
        self::assertSame([], $this->files());
    }

    /**
     * A batch whose rows can't be stored doesn't move the saved position: the
     * next run stores the same lines again, once.
     */
    public function testFailedBatchKeepsThePositionInTheFile(): void
    {
        $this->spool(...self::pageviews(1500));
        $database = $this->pipeline->database;
        $this->pipeline->spoolIngestor()
            ->ingest(0);
        $position = $this->pipeline->state()->spoolOffsets();

        $database->connection->executeStatement(sprintf('ALTER TABLE %s RENAME TO bolt_ra_hit_away', $database->tables->hit()));
        try {
            $this->pipeline->spoolIngestor()
                ->ingest(PHP_INT_MAX);
            self::fail('The rows were stored without a table.');
        } catch (TableNotFoundException) {
        } finally {
            $database->connection->executeStatement(sprintf('ALTER TABLE bolt_ra_hit_away RENAME TO %s', $database->tables->hit()));
        }

        self::assertFalse($database->connection->isTransactionActive());
        self::assertSame($position, $this->pipeline->state()->spoolOffsets());
        self::assertSame(500, $this->pipeline->spoolIngestor()->ingest(PHP_INT_MAX));
        self::assertSame(1500, $database->count($database->tables->hit()));
    }

    /**
     * The values are written into the INSERT as quoted literals: whatever a
     * path, source or term may hold (quotes, SQL, `%` and `_`) comes out
     * exactly as it went in.
     */
    public function testValuesArriveUnchanged(): void
    {
        $tricky = new Hit(
            '2026-11-13',
            23,
            Hit::KIND_PAGEVIEW,
            '/o%27neil/%22quoted%22;--/100%_(x)',
            'mobile',
            'safari',
            'ios',
            'cs',
            'campaign',
            "x') drop table bolt_ra_hit --",
            'léto % _',
            true,
            false,
            ref: '/ref?',
            via: 'alias.test',
            term: "it's n not a newline",
        );
        $this->spool($tricky, new Hit('2026-11-13', 23, Hit::KIND_BOT, '', bot: 'googlebot'));

        self::assertSame(2, $this->pipeline->spoolIngestor()->ingest(PHP_INT_MAX));

        $rows = $this->pipeline->database->rows($this->pipeline->database->tables->hit(), 'id');
        unset($rows[0]['id'], $rows[1]['id']);
        self::assertSame([
            ...$tricky->toRow(),
            'is_entry' => 1,
            'is_reload' => 0,
        ], $rows[0]);
        self::assertSame([null, null, 'googlebot'], [$rows[1]['ref'], $rows[1]['term'], $rows[1]['bot']]);
    }

    /**
     * A spool file is a file on disk: a line with what the collector never
     * writes (markup, a backslash, a bidi override) is not stored.
     */
    public function testLinesWithUnsafeValuesAreSkipped(): void
    {
        $this->spool(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/ok'));
        $line = static fn (array $values): string => json_encode([...(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/x'))->toRow(), ...$values], JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($this->pipeline->project->spoolDir() . '/2026111310.ndjson', $line([
            'path' => '/<script>',
        ]) . $line([
            'path' => '/\\evil.com',
        ]) . $line([
            'campaign' => "sale\u{202E}",
        ]) . $line([
            'term' => '<b>bold</b>',
        ]) . $line([
            'device' => 'Desktop<',
        ]), FILE_APPEND);

        self::assertSame(1, $this->pipeline->spoolIngestor()->ingest(PHP_INT_MAX));
        self::assertSame(['/ok'], $this->pipeline->database->connection->fetchFirstColumn(sprintf('SELECT path FROM %s', $this->pipeline->database->tables->hit())));
    }

    public function testCollectorContinuesInAFreshFileWhileOneIsIngested(): void
    {
        $this->spool(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/before'));

        // What ingest() does first: the file is claimed by renaming it.
        $spool = $this->pipeline->project->spoolDir();
        rename($spool . '/2026111310.ndjson', $spool . '/2026111310.ndjson.abcd1234.ingest');
        $this->spool(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/during'));

        self::assertSame(['2026111310.ndjson', '2026111310.ndjson.abcd1234.ingest'], $this->files());
        self::assertSame(2, $this->pipeline->spoolIngestor()->ingest(PHP_INT_MAX), 'a claimed file that was left behind is picked up again');
        self::assertSame([], $this->files());
    }

    public function testFileIsKeptWhenItsRowsCouldNotBeStored(): void
    {
        $this->spool(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/a'), new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/b'));
        $withoutTables = new Pipeline($this->pipeline->project, new Database(false));

        try {
            $withoutTables->spoolIngestor()
                ->ingest(PHP_INT_MAX);
            self::fail('The rows were stored without a table.');
        } catch (TableNotFoundException) {
        }

        self::assertFalse($withoutTables->database->connection->isTransactionActive());
        self::assertCount(1, $this->files());
        self::assertSame(2, $this->pipeline->spoolIngestor()->pending(), 'still pending');

        // Once the tables are there, nothing is lost.
        self::assertSame(2, $this->pipeline->spoolIngestor()->ingest(PHP_INT_MAX));
    }

    public function testNoSpoolDirectoryIsFine(): void
    {
        self::assertSame(0, $this->pipeline->spoolIngestor()->ingest(PHP_INT_MAX));
        self::assertSame(0, $this->pipeline->spoolIngestor()->pending());
    }

    /**
     * @return list<Hit>
     */
    private static function pageviews(int $count): array
    {
        return array_map(static fn (int $index): Hit => new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/page-' . $index), range(0, $count - 1));
    }

    private function spool(Hit ...$hits): void
    {
        foreach ($hits as $hit) {
            $this->pipeline->spoolWriter()
                ->write($hit);
        }
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $names = scandir($this->pipeline->project->spoolDir());
        self::assertIsArray($names);

        return array_values(array_diff($names, ['.', '..']));
    }
}
