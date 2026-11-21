<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Writer;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Tests\Support\Database;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryLogger;
use TomVondracek\RequestAnalytics\Tests\Support\Project;
use TomVondracek\RequestAnalytics\Tests\Support\WriterProcess;
use TomVondracek\RequestAnalytics\Writer\SpoolHitWriter;

#[Group('database')]
final class SpoolHitWriterTest extends TestCase
{
    private Project $project;
    private Database $database;

    protected function setUp(): void
    {
        $this->project = new Project();
        $this->database = new Database();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /**
     * The rollup holds the lock of a claimed file while it reads it, up to its
     * whole budget. A collector must not wait that long.
     */
    public function testSpoolWriterGivesUpOnALockThatIsHeld(): void
    {
        $spool = $this->project->spoolDir();
        mkdir($spool, 0o777, true);
        $file = $spool . '/2026111310.ndjson';
        $handle = fopen($file, 'a');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, LOCK_EX));
        $start = hrtime(true);

        try {
            (new SpoolHitWriter($this->project->configLoader()))->write(self::hit());
            self::fail('The line was written although the file was locked.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('stayed locked', $exception->getMessage());
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        self::assertLessThan(3_000, (hrtime(true) - $start) / 1_000_000, 'about a second, not the rollup\'s budget');
        self::assertSame('', file_get_contents($file));
    }

    /**
     * The line is written in bytes; paths, sources and terms may be any UTF-8.
     */
    public function testSpoolWriterKeepsMultibyteText(): void
    {
        $hit = new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/kapely/žluťoučký-kůň', campaign: 'léto žluťoučký', term: 'vstupenky ü');

        (new SpoolHitWriter($this->project->configLoader()))->write($hit);

        $lines = file($this->project->spoolDir() . '/2026111310.ndjson', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        $row = json_decode($lines[0], true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($row);
        self::assertEquals($hit, Hit::fromRow($row));
        self::assertSame(0o660, fileperms($this->project->spoolDir() . '/2026111310.ndjson') & 0o777, 'owner and group only');
        self::assertSame(0o770, fileperms($this->project->spoolDir()) & 0o777);
    }

    public function testSpoolWriterAppendsOneLinePerHitToTheFileOfItsHour(): void
    {
        $writer = new SpoolHitWriter($this->project->configLoader());

        $writer->write(self::hit());
        $writer->write(self::hit());
        $writer->write(new Hit('2026-11-13', 9, Hit::KIND_PAGEVIEW, '/'));

        $ten = $this->project->spoolDir() . '/2026111310.ndjson';
        self::assertFileExists($ten);
        self::assertFileExists($this->project->spoolDir() . '/2026111309.ndjson');

        $lines = file($ten, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(2, $lines);
        $row = json_decode($lines[0], true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($row);
        self::assertEquals(self::hit(), Hit::fromRow($row));
        self::assertStringContainsString('"path":"/program"', $lines[0], 'slashes are not escaped');
        self::assertSame([], $this->database->log->statements, 'the collector never touches the database');
    }

    public function testSpoolWriterFailsWhenTheDirectoryCanNotBeCreated(): void
    {
        file_put_contents($this->project->dir . '/blocked', '');
        $this->project->configure([
            'collector' => [
                'spool_dir' => $this->project->dir . '/blocked/spool',
            ],
        ]);

        $this->expectException(RuntimeException::class);

        (new SpoolHitWriter($this->project->configLoader()))->write(self::hit());
    }

    /**
     * When the rollup cannot run, the spool does not fill the disk: from
     * `max_spool_mb` on, a new file is not started, and the hit is dropped
     * (the collector logs it). Once the rollup has worked it off, collecting
     * goes on.
     */
    public function testAFullSpoolDropsHitsUntilItIsWorkedOff(): void
    {
        $this->project->configure([
            'collector' => [
                'max_spool_mb' => 1,
            ],
        ]);
        $spool = $this->project->spoolDir();
        mkdir($spool, 0o777, true);
        // A claimed file of an earlier hour that the rollup could not store.
        file_put_contents($spool . '/2026111309.ndjson.abcd1234.ingest', str_repeat('x', 1024 * 1024));
        $writer = new SpoolHitWriter($this->project->configLoader());

        try {
            $writer->write(self::hit());
            self::fail('A full spool took another hit.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('collector.max_spool_mb', $exception->getMessage());
        }

        self::assertFileDoesNotExist($spool . '/2026111310.ndjson');
        self::assertFileExists($spool . '/' . SpoolHitWriter::FULL_MARKER, 'remembered, not summed up on every request');

        // The rollup has worked it off; the marker is older than a minute.
        unlink($spool . '/2026111309.ndjson.abcd1234.ingest');
        touch($spool . '/' . SpoolHitWriter::FULL_MARKER, time() - 61);
        $writer->write(self::hit());

        self::assertFileExists($spool . '/2026111310.ndjson');
        self::assertFileDoesNotExist($spool . '/' . SpoolHitWriter::FULL_MARKER);
    }

    public function testNoLimitWithZero(): void
    {
        $this->project->configure([
            'collector' => [
                'max_spool_mb' => 0,
            ],
        ]);
        $spool = $this->project->spoolDir();
        mkdir($spool, 0o777, true);
        file_put_contents($spool . '/2026111309.ndjson', str_repeat('x', 1024 * 1024));

        (new SpoolHitWriter($this->project->configLoader()))->write(self::hit());

        self::assertFileExists($spool . '/2026111310.ndjson');
    }

    /**
     * With `raw_max_days`, visits do not wait in the spool for ever when no
     * rollup runs: a line describes one request. Files of hours more than
     * that many days before are deleted when a new file is started, and that
     * is logged. Claimed files are the rollup's.
     */
    public function testSpoolFilesThatWaitedTooLongAreDeleted(): void
    {
        $this->project->configure([
            'privacy' => [
                'raw_max_days' => 30,
            ],
        ]);
        $spool = $this->project->spoolDir();
        mkdir($spool, 0o777, true);
        foreach (['2026101323.ndjson', '2026101400.ndjson', '2026101200.ndjson.0a1b2c3d.ingest', 'notes.txt'] as $name) {
            file_put_contents($spool . '/' . $name, "x\n");
        }

        $logger = new MemoryLogger();
        (new SpoolHitWriter($this->project->configLoader(), $this->project->failureReporter($logger)))->write(self::hit());

        self::assertFileDoesNotExist($spool . '/2026101323.ndjson', 'more than 30 days before 2026-11-13');
        self::assertFileExists($spool . '/2026101400.ndjson', 'the 30th day before is kept');
        self::assertFileExists($spool . '/2026101200.ndjson.0a1b2c3d.ingest');
        self::assertFileExists($spool . '/notes.txt');
        self::assertFileExists($spool . '/2026111310.ndjson');
        self::assertCount(1, $logger->records);
        self::assertStringContainsString('raw_max_days', $logger->records[0]['message']);
    }

    public function testSpoolFilesAreKeptUntilTheRollupByDefault(): void
    {
        $spool = $this->project->spoolDir();
        mkdir($spool, 0o777, true);
        file_put_contents($spool . '/2025010100.ndjson', "x\n");

        (new SpoolHitWriter($this->project->configLoader()))->write(self::hit());

        self::assertFileExists($spool . '/2025010100.ndjson');
    }

    /**
     * The rollup claims a spool file by renaming it, then locks it. A collector
     * that opened the file before the rename and gets the lock afterwards must
     * write to a fresh file: the rollup may already have read the claimed one.
     */
    public function testSpoolWriterDoesNotAppendToAClaimedFile(): void
    {
        $spool = $this->project->spoolDir();
        mkdir($spool, 0o777, true);
        $file = $spool . '/2026111310.ndjson';
        file_put_contents($file, '');
        $handle = fopen($file, 'r');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, LOCK_EX));

        $writer = new WriterProcess($this->project, 1);
        $writer->waitUntilReady();
        // Long enough for the writer to open the file and wait for the lock.
        usleep(300_000);
        rename($file, $file . '.claimed');
        flock($handle, LOCK_UN);
        fclose($handle);
        $writer->finish();

        self::assertSame('', file_get_contents($file . '.claimed'), 'nothing was added to the claimed file');
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(1, $lines, 'the line went to a fresh file');
    }

    private static function hit(): Hit
    {
        return new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/program', 'mobile', 'safari', 'ios', 'cs', 'social', 'facebook', '', true);
    }
}
