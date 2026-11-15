<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Command;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Command\RollupCommand;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;

#[Group('database')]
final class RollupCommandTest extends TestCase
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

    public function testName(): void
    {
        self::assertSame('request-analytics:rollup', (new RollupCommand($this->pipeline->rollupService(), $this->pipeline->analyticsDatabase()))->getName());
    }

    public function testAggregatesEverythingWhateverTheDashboardBudget(): void
    {
        $this->pipeline->project->configure([
            'aggregation' => [
                'chunk_size' => 2,
                'time_budget_ms' => 0,
            ],
        ]);
        for ($index = 0; $index < 5; ++$index) {
            $this->pipeline->spoolWriter()
                ->write(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/'));
        }

        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertMatchesRegularExpression('/5 rows aggregated in \d+ ms, 0 pending\./', $tester->getDisplay());
        self::assertSame(5, $this->pipeline->database->rows($this->pipeline->database->tables->daily())[0]['pageviews'] ?? null);
    }

    public function testBusy(): void
    {
        mkdir($this->pipeline->project->dir . '/var/request-analytics', 0o777, true);
        $lock = (new LockFactory(new FlockStore($this->pipeline->project->dir . '/var/request-analytics')))->createLock('rollup');
        self::assertTrue($lock->acquire());

        try {
            $tester = $this->tester();

            self::assertSame(Command::SUCCESS, $tester->execute([]));
            self::assertStringContainsString('Another rollup is running', $tester->getDisplay());
        } finally {
            $lock->release();
        }
    }

    public function testInvalidBudget(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute([
            '--budget' => 'soon',
        ]));
        self::assertSame(Command::INVALID, $tester->execute([
            '--budget' => '0',
        ]));
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new RollupCommand($this->pipeline->rollupService(), $this->pipeline->analyticsDatabase()));
    }
}
