<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Aggregation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use TomVondracek\RequestAnalytics\Aggregation\RollupService;
use TomVondracek\RequestAnalytics\Aggregation\VisitRollupListener;
use TomVondracek\RequestAnalytics\Collector\CollectListener;
use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Diagnostics\Throttle;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryLogger;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;

#[Group('database')]
final class VisitRollupListenerTest extends TestCase
{
    private Pipeline $pipeline;
    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->pipeline = new Pipeline();
        $this->logger = new MemoryLogger();
    }

    protected function tearDown(): void
    {
        $this->pipeline->remove();
    }

    public function testListensAfterTheCollector(): void
    {
        $listener = (new ReflectionClass(VisitRollupListener::class))->getAttributes(AsEventListener::class)[0]->newInstance();

        self::assertSame(KernelEvents::TERMINATE, $listener->event);
        self::assertLessThan(CollectListener::PRIORITY, $listener->priority);
    }

    /**
     * Without cron and without anybody opening the dashboard, a visit moves
     * the spool into the statistics, once its page has been sent.
     */
    public function testVisitRollsUpTheSpool(): void
    {
        $this->spool();

        $this->terminate($this->listener());

        self::assertSame(1, $this->pageviews());
        self::assertSame([], $this->logger->records);
    }

    /**
     * Once an hour (`visit_interval_minutes`), not on every visit.
     */
    public function testAtMostOncePerInterval(): void
    {
        $listener = $this->listener();
        $this->terminate($listener);

        $this->spool();
        $this->terminate($listener);

        self::assertSame(0, $this->pageviews(), 'waits for the next interval');
        self::assertSame(1, $this->pipeline->rollupService()->pending());
    }

    /**
     * Under Apache's mod_php or CGI the visitor would wait for the rollup.
     */
    public function testNotWhereTheVisitorWouldWait(): void
    {
        $this->spool();

        $this->terminate($this->listener(responseSent: false));

        self::assertSame(0, $this->pageviews());
    }

    public function testNotWithCronOnlyNorWhenTurnedOff(): void
    {
        $this->spool();

        $this->pipeline->project->configure([
            'aggregation' => [
                'trigger' => 'cron',
            ],
        ]);
        $this->terminate($this->listener());
        $this->pipeline->project->configure([
            'aggregation' => [
                'visit_interval_minutes' => 0,
            ],
        ]);
        $this->terminate($this->listener());

        self::assertSame(0, $this->pageviews());
    }

    /**
     * A call of the rollup URL has just run one.
     */
    public function testNotAfterTheRollupUrl(): void
    {
        $this->spool();
        $request = Request::create('https://rockfest-kurim.cz/_request-analytics/rollup');
        $request->attributes->set(CollectListener::NOT_COUNTED_ATTRIBUTE, true);

        $this->listener()(new TerminateEvent(self::createStub(HttpKernelInterface::class), $request, new Response()));

        self::assertSame(0, $this->pageviews());
    }

    /**
     * When the throttle cannot keep its time (no APCu, `var/` not
     * writable), no rollup starts, rather than one on every request.
     */
    public function testNoRollupWhenTheThrottleCannotKeepItsTime(): void
    {
        $this->spool();
        $directory = $this->pipeline->project->dir . '/var/request-analytics/throttle';
        mkdir($directory, 0o777, true);
        chmod($directory, 0o555);

        try {
            $this->terminate($this->listener());
        } finally {
            chmod($directory, 0o755);
        }

        self::assertSame(0, $this->pageviews());
    }

    public function testNotWhileTheSiteIsDeveloped(): void
    {
        $this->spool();

        $this->terminate($this->listener(environment: 'dev'));
        $this->terminate($this->listener(debug: true));

        self::assertSame(0, $this->pageviews());
    }

    /**
     * A failure never reaches the visitor's request: it is logged.
     */
    public function testFailureIsLogged(): void
    {
        $listener = new VisitRollupListener(
            $this->pipeline->project->configLoader(),
            new Throttle($this->pipeline->project->dir, apcu: false),
            $this->pipeline->project->failureReporter($this->logger),
            static fn (): RollupService => throw new RuntimeException('The database is gone.'),
            $this->pipeline->analyticsDatabase(...),
            responseSent: true,
        );

        $this->terminate($listener);

        self::assertCount(1, $this->logger->records);
        self::assertStringContainsString('The database is gone.', $this->logger->records[0]['message']);
    }

    private function listener(bool $responseSent = true, string $environment = 'prod', bool $debug = false): VisitRollupListener
    {
        return new VisitRollupListener(
            $this->pipeline->project->configLoader(),
            new Throttle($this->pipeline->project->dir, apcu: false),
            $this->pipeline->project->failureReporter($this->logger),
            $this->pipeline->rollupService(...),
            $this->pipeline->analyticsDatabase(...),
            $environment,
            $debug,
            $responseSent,
        );
    }

    private function terminate(VisitRollupListener $listener): void
    {
        $listener(new TerminateEvent(self::createStub(HttpKernelInterface::class), Request::create('https://rockfest-kurim.cz/'), new Response()));
    }

    private function spool(): void
    {
        $this->pipeline->spoolWriter()
            ->write(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/', 'desktop', 'chrome', 'windows', 'cs', 'direct', isEntry: true));
    }

    private function pageviews(): int
    {
        $this->pipeline->analyticsDatabase()
            ->install();
        $rows = $this->pipeline->database->rows($this->pipeline->database->tables->daily());

        return array_sum(array_map(static fn (array $row): int => is_numeric($row['pageviews'] ?? null) ? (int) $row['pageviews'] : 0, $rows));
    }
}
