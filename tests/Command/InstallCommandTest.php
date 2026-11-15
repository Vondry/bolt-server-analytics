<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TomVondracek\RequestAnalytics\Command\InstallCommand;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;
use TomVondracek\RequestAnalytics\Tests\Support\Project;

final class InstallCommandTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testCreatesTheTablesAndThenReportsThemUpToDate(): void
    {
        $tester = $this->tester();
        $file = $this->project->dir . '/var/data/request-analytics.db';

        self::assertSame(0, $tester->execute([], [
            'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
        ]));
        self::assertStringContainsString('CREATE TABLE ra_hit', $tester->getDisplay());
        // SymfonyStyle wraps long paths.
        self::assertStringContainsString('14 statement(s) run on the Request Analytics tables in', (string) preg_replace('~\s+~', ' ', $tester->getDisplay()));
        self::assertFileExists($file);

        self::assertStringNotContainsString('rewritten without the space of deleted rows', $tester->getDisplay(), 'no VACUUM unasked');

        self::assertSame(0, $tester->execute([
            '--vacuum' => true,
        ]));
        $display = (string) preg_replace('~\s+~', ' ', $tester->getDisplay());
        self::assertStringContainsString('are up to date', $display);
        self::assertStringContainsString('rewritten without the space of deleted rows', $display);
        self::assertStringContainsString('now for their owner and group only', $display);
        self::assertSame(0o660, fileperms($file) & 0o777);
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new InstallCommand(new AnalyticsDatabase($this->project->configLoader(), $this->project->dir)));
    }
}
