<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Diagnostics;

use LogicException;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use TomVondracek\RequestAnalytics\Diagnostics\FailureReporter;
use TomVondracek\RequestAnalytics\Diagnostics\Throttle;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryLogger;
use TomVondracek\RequestAnalytics\Tests\Support\Project;

final class FailureReporterTest extends TestCase
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

    public function testLogsAnErrorWithTheException(): void
    {
        $logger = new MemoryLogger();

        $this->project->failureReporter($logger)
            ->report('collection', new RuntimeException('The spool directory is not writable.'));

        self::assertSame([[
            'level' => 'error',
            'message' => 'request-analytics: collection failed: The spool directory is not writable.',
        ]], $logger->records);
    }

    /**
     * A failure on every request is logged once per window; another failure is
     * logged on its own.
     */
    public function testEachDistinctFailureIsLoggedOncePerWindow(): void
    {
        $logger = new MemoryLogger();

        // Like separate requests: new services, shared files.
        foreach (range(1, 3) as $request) {
            $this->project->withFreshServices()
                ->failureReporter($logger)
                ->report('collection', new RuntimeException('The spool directory is not writable.'));
        }
        $this->project->failureReporter($logger)
            ->report('collection', new RuntimeException('The database is locked.'));
        $this->project->failureReporter($logger)
            ->report('alias redirect', new RuntimeException('The database is locked.'));

        self::assertSame([
            'request-analytics: collection failed: The spool directory is not writable.',
            'request-analytics: collection failed: The database is locked.',
            'request-analytics: alias redirect failed: The database is locked.',
        ], array_column($logger->records, 'message'));
    }

    /**
     * The same failure in another hour (the spool file of the hour in its
     * message) is the same failure: once per window, and one marker.
     */
    public function testNumbersInTheMessageMakeNoOtherFailure(): void
    {
        $logger = new MemoryLogger();

        foreach (['2026111309', '2026111310', '2026111311'] as $hour) {
            $this->project->withFreshServices()
                ->failureReporter($logger)
                ->report('collection', new RuntimeException(sprintf('The spool file "/var/spool/%s.ndjson" could not be opened.', $hour)));
        }

        self::assertCount(1, $logger->records);
    }

    /**
     * Markers of keys that have not come back for a day are removed when a
     * new key is first seen, so they do not pile up.
     */
    public function testOldMarkersAreRemoved(): void
    {
        $throttle = new Throttle($this->project->dir, apcu: false);
        self::assertTrue($throttle->allow('old', 300));
        $markers = glob($this->project->dir . '/var/request-analytics/throttle/*');
        self::assertIsArray($markers);
        foreach ($markers as $marker) {
            touch($marker, time() - 86_401);
        }

        self::assertTrue($throttle->allow('new', 300));

        self::assertCount(1, (array) glob($this->project->dir . '/var/request-analytics/throttle/*'));
    }

    #[DoesNotPerformAssertions]
    public function testABrokenLoggerIsIgnored(): void
    {
        $logger = new class() extends AbstractLogger {
            /**
             * @param array<array-key, mixed> $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new LogicException('The log file is not writable.');
            }
        };

        (new FailureReporter($logger, new Throttle($this->project->dir)))->report('collection', new RuntimeException('Failed.'));
    }

    public function testThrottleIsPerKeyAndPerSite(): void
    {
        $throttle = new Throttle($this->project->dir);
        $other = new Project();

        try {
            self::assertTrue($throttle->allow('install', 300));
            self::assertFalse($throttle->allow('install', 300), 'within the window');
            self::assertFalse((new Throttle($this->project->dir))->allow('install', 300), 'also for a later request');
            self::assertTrue($throttle->allow('other', 300), 'another key');
            self::assertTrue((new Throttle($other->dir))->allow('install', 300), 'another site');
        } finally {
            $other->remove();
        }
    }

    public function testThrottlePassesAgainAfterTheWindow(): void
    {
        $throttle = new Throttle($this->project->dir, apcu: false);

        self::assertTrue($throttle->allow('install', 300));
        $markers = glob($this->project->dir . '/var/request-analytics/throttle/*');
        self::assertIsArray($markers);
        self::assertCount(1, $markers);
        foreach ($markers as $marker) {
            touch($marker, time() - 301);
        }

        self::assertTrue($throttle->allow('install', 300));
    }

    /**
     * Without a place to keep the window, a failure is logged every time rather
     * than never.
     */
    public function testThrottleWithoutStoragePasses(): void
    {
        $throttle = new Throttle('/dev/null/no-project', apcu: false);

        self::assertTrue($throttle->allow('install', 300));
        self::assertTrue($throttle->allow('install', 300));
    }
}
