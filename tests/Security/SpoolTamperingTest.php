<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Tests\Support\Payloads;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;
use TomVondracek\RequestAnalytics\Tests\Support\StoredValues;

/**
 * The spool is a file on disk: whoever can write to it can put anything in a
 * line. Every text column of a line, filled with every payload: none of them
 * is stored, the honest lines around them are, and the tables stay intact.
 */
#[Group('database')]
final class SpoolTamperingTest extends TestCase
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
     * @return iterable<string, array{string}>
     */
    public static function columns(): iterable
    {
        foreach (Hit::COLUMNS as $column => $length) {
            if ($length !== null) {
                yield $column => [$column];
            }
        }
    }

    #[DataProvider('columns')]
    public function testForgedValuesAreNotStored(string $column): void
    {
        $tables = StoredValues::tableNames($this->pipeline->database);
        $honest = (new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/honest'))->toRow();
        $lines = json_encode($honest, JSON_THROW_ON_ERROR) . "\n";
        foreach (Payloads::all() as $payload) {
            $line = json_encode([
                ...$honest,
                $column => $payload,
            ], JSON_INVALID_UTF8_IGNORE);
            self::assertIsString($line);
            $lines .= $line . "\n";
        }
        $lines .= json_encode($honest, JSON_THROW_ON_ERROR) . "\n";

        mkdir($this->pipeline->project->spoolDir(), 0o770, true);
        file_put_contents($this->pipeline->project->spoolDir() . '/2026111310.ndjson', $lines);
        $this->pipeline->rollupService()
            ->rollup(PHP_INT_MAX);

        StoredValues::assertAllSafe($this->pipeline->database, 'spool ' . $column);
        self::assertSame($tables, StoredValues::tableNames($this->pipeline->database));
        // Some payloads are harmless text for a label (`' OR '1'='1`) and are kept as they are.
        $stored = StoredValues::counted($this->pipeline->database);
        self::assertGreaterThanOrEqual(2, $stored, 'the honest lines');
        self::assertLessThan(count(Payloads::all()) + 2, $stored, 'not every forged line');
    }
}
