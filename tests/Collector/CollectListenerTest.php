<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Collector;

use Bolt\Widget\Injector\RequestZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use TomVondracek\RequestAnalytics\Collector\CollectListener;
use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryLogger;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryWriter;
use TomVondracek\RequestAnalytics\Tests\Support\Project;
use TomVondracek\RequestAnalytics\Tests\Support\Requests;

final class CollectListenerTest extends TestCase
{
    private Project $project;
    private MemoryWriter $writer;
    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->project = new Project();
        $this->writer = new MemoryWriter();
        $this->logger = new MemoryLogger();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testListensAfterTheResponseWasSent(): void
    {
        $attributes = (new ReflectionClass(CollectListener::class))->getAttributes(AsEventListener::class);
        self::assertCount(1, $attributes);
        $listener = $attributes[0]->newInstance();

        self::assertSame(KernelEvents::TERMINATE, $listener->event);
        self::assertSame(-1024, $listener->priority);
    }

    public function testFrontendPageViewIsWritten(): void
    {
        $this->terminate($this->request('/program', RequestZone::FRONTEND), 200);

        self::assertCount(1, $this->writer->hits);
        self::assertSame(['pageview', '/program'], [$this->writer->hits[0]->kind, $this->writer->hits[0]->path]);
    }

    #[DataProvider('zones')]
    public function testOnlyTheFrontendIsCounted(string $zone, int $status, ?string $kind): void
    {
        $this->terminate($this->request('/page', $zone), $status);

        self::assertSame($kind === null ? [] : [$kind], array_map(static fn (Hit $hit): string => $hit->kind, $this->writer->hits));
    }

    /**
     * @return iterable<string, array{string, int, ?string}>
     */
    public static function zones(): iterable
    {
        yield 'frontend page' => [RequestZone::FRONTEND, 200, 'pageview'];
        yield 'frontend 404 (record not found)' => [RequestZone::FRONTEND, 404, 'not_found'];
        yield '404 without a route' => [RequestZone::NOWHERE, 404, 'not_found'];
        yield '404 in the error zone' => [RequestZone::ERROR, 404, 'not_found'];
        yield 'page of another controller' => [RequestZone::NOWHERE, 200, null];
        yield 'backend' => [RequestZone::BACKEND, 200, null];
        yield 'backend 404' => [RequestZone::BACKEND, 404, null];
        yield 'async' => [RequestZone::ASYNC, 200, null];
        yield 'async 404' => [RequestZone::ASYNC, 404, null];
        yield 'error page' => [RequestZone::ERROR, 500, null];
    }

    /**
     * A 404 without a route has no zone; below the backend URL it is the
     * backend's, also when the project moved the backend.
     */
    #[DataProvider('backendPaths')]
    public function testBackend404IsNotCounted(string $backendUrl, string $path, bool $counted): void
    {
        $this->terminate($this->request($path, RequestZone::NOWHERE), 404, $backendUrl);

        self::assertSame($counted ? ['not_found'] : [], array_map(static fn (Hit $hit): string => $hit->kind, $this->writer->hits));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function backendPaths(): iterable
    {
        yield 'below /bolt' => ['/bolt', '/bolt/nothing-here', false];
        yield 'the backend itself' => ['/bolt', '/bolt', false];
        yield 'a page that starts like the backend' => ['/bolt', '/boltcms-meetup', true];
        yield 'moved backend' => ['/admin/', '/admin/nothing-here', false];
        yield '/bolt with a moved backend' => ['/admin', '/bolt/old-link', true];
    }

    /**
     * AliasListener answered the request with the redirect and only marked
     * it; the hop is classified here, after the redirect was sent.
     */
    public function testAliasHopIsClassifiedAfterTheRedirect(): void
    {
        $this->configureAlias();

        $this->terminate($this->aliasHop('/program?utm_source=poster'), 301);

        self::assertCount(1, $this->writer->hits);
        $hit = $this->writer->hits[0];
        self::assertSame([Hit::KIND_ALIAS, 'rockfestkurim.cz', '/program'], [$hit->kind, $hit->via, $hit->path]);
    }

    /**
     * A hop is classified as a hop only, whatever the zone: a bot that follows
     * the redirect is counted on the site, not on the alias.
     */
    public function testAliasHopOfABotIsNotWritten(): void
    {
        $this->configureAlias();
        $hop = $this->aliasHop('/', [
            'User-Agent' => Requests::GOOGLEBOT,
        ]);
        RequestZone::setToRequest($hop, RequestZone::FRONTEND);

        $this->terminate($hop, 301);

        self::assertSame([], $this->writer->hits);
    }

    /**
     * Requests made while the site is developed, tested or debugged are not
     * visits: `APP_ENV=dev` or `APP_DEBUG=1` collects nothing, not even the
     * hop of an alias host.
     */
    #[DataProvider('environments')]
    public function testNothingIsCollectedDuringDevelopment(string $environment, bool $debug, bool $collected): void
    {
        $this->configureAlias();
        $this->terminate($this->request('/program', RequestZone::FRONTEND), 200, environment: $environment, debug: $debug);
        $this->terminate($this->aliasHop('/'), 301, environment: $environment, debug: $debug);

        self::assertCount($collected ? 2 : 0, $this->writer->hits);
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function environments(): iterable
    {
        yield 'prod' => ['prod', false, true];
        yield 'prod with APP_DEBUG=1' => ['prod', true, false];
        yield 'dev' => ['dev', true, false];
        yield 'dev with APP_DEBUG=0' => ['dev', false, false];
        yield 'another environment without debug' => ['staging', false, true];
    }

    public function testFailingWriterNeverBreaksTheRequest(): void
    {
        $this->writer->fails = true;

        $this->terminate($this->request('/program', RequestZone::FRONTEND), 200);

        self::assertSame([[
            'level' => 'error',
            'message' => 'request-analytics: collection failed: The database is gone.',
        ]], $this->logger->records);
    }

    public function testInvalidConfigNeverBreaksTheRequest(): void
    {
        $this->project->configure([
            'enabled' => 'maybe',
        ]);

        $this->terminate($this->request('/program', RequestZone::FRONTEND), 200);

        self::assertSame([], $this->writer->hits);
        self::assertCount(1, $this->logger->records);
        self::assertSame('error', $this->logger->records[0]['level'], 'Bolt\'s production log drops anything below `error`');
        self::assertStringStartsWith('request-analytics: collection failed: ', $this->logger->records[0]['message']);
    }

    /**
     * Every request fails the same way; the log gets one line, not one per
     * request.
     */
    public function testRepeatedFailureIsLoggedOnce(): void
    {
        $this->project->configure([
            'enabled' => 'maybe',
        ]);

        for ($index = 0; $index < 5; ++$index) {
            $this->terminate($this->request('/program', RequestZone::FRONTEND), 200);
        }

        self::assertCount(1, $this->logger->records);
    }

    private function configureAlias(): void
    {
        $this->project->configure([
            'aliases' => [
                'hosts' => ['rockfestkurim.cz'],
                'target' => 'https://rockfest-kurim.cz',
            ],
        ]);
    }

    /**
     * A request to the alias host, as AliasListener leaves it: answered with
     * the redirect before routing (no zone), and marked.
     *
     * @param array<string, string> $headers
     */
    private function aliasHop(string $path, array $headers = []): Request
    {
        $request = Requests::browser('https://rockfestkurim.cz' . $path, $headers);
        $request->attributes->set(CollectListener::ALIAS_HOP_ATTRIBUTE, true);

        return $request;
    }

    private function request(string $path, string $zone): Request
    {
        $request = Requests::browser('https://rockfest-kurim.cz' . $path);
        if ($zone !== RequestZone::NOWHERE) {
            RequestZone::setToRequest($request, $zone);
        }

        return $request;
    }

    private function terminate(Request $request, int $status, string $backendUrl = '/bolt', string $environment = 'prod', bool $debug = false): void
    {
        $listener = new CollectListener($this->project->classifier(), $this->writer, $this->project->failureReporter($this->logger), $backendUrl, $environment, $debug);
        $listener(new TerminateEvent(self::createStub(HttpKernelInterface::class), $request, new Response('', $status)));
    }
}
