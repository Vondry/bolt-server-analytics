<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Collector;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use TomVondracek\RequestAnalytics\Collector\AliasListener;
use TomVondracek\RequestAnalytics\Collector\CollectListener;
use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Collector\HitClassifier;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryLogger;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryWriter;
use TomVondracek\RequestAnalytics\Tests\Support\Project;
use TomVondracek\RequestAnalytics\Tests\Support\Requests;

final class AliasListenerTest extends TestCase
{
    private Project $project;
    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->project = new Project();
        $this->logger = new MemoryLogger();
        $this->configure();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testListensBeforeRouting(): void
    {
        $attributes = (new ReflectionClass(AliasListener::class))->getAttributes(AsEventListener::class);
        self::assertCount(1, $attributes);
        $listener = $attributes[0]->newInstance();

        self::assertSame(KernelEvents::REQUEST, $listener->event);
        self::assertGreaterThan(32, $listener->priority, 'the router listener has 32');
    }

    public function testAliasHostIsRedirectedAndCounted(): void
    {
        $request = Requests::browser('https://rockfestkurim.cz/program?den=1&x[]=a%20b');

        $response = $this->handle($request);

        self::assertNotNull($response);
        self::assertSame(Response::HTTP_MOVED_PERMANENTLY, $response->getStatusCode());
        self::assertSame('https://rockfest-kurim.cz/program?den=1&x[]=a%20b', $response->headers->get('Location'), 'path and query preserved');
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'), 'a plain 301 would be cached forever');

        self::assertTrue($request->attributes->get(CollectListener::ALIAS_HOP_ATTRIBUTE), 'marked for the collector');

        $hits = $this->collect($request, $response);
        self::assertCount(1, $hits);
        self::assertSame(['alias', 'rockfestkurim.cz', '/program', 'direct'], [$hits[0]->kind, $hits[0]->via, $hits[0]->path, $hits[0]->channel]);
    }

    public function testConfiguredStatus(): void
    {
        $this->configure([
            'redirect_status' => 307,
        ]);

        self::assertSame(307, $this->handle(Requests::browser('https://rockfestkurim.cz/'))?->getStatusCode());
    }

    public function testBotIsRedirectedButNotCounted(): void
    {
        $request = Requests::browser('https://rockfestkurim.cz/', [
            'User-Agent' => Requests::GOOGLEBOT,
        ]);

        $response = $this->handle($request);

        self::assertSame('https://rockfest-kurim.cz/', $response?->headers->get('Location'));
        self::assertSame([], $this->collect($request, $response), 'a hop, so not a bot request of the site either');
    }

    public function testPostIsRedirectedButNotCounted(): void
    {
        $request = Requests::browser('https://rockfestkurim.cz/kontakt', [], Request::METHOD_POST);

        $response = $this->handle($request);

        self::assertSame('https://rockfest-kurim.cz/kontakt', $response?->headers->get('Location'));
        self::assertSame([], $this->collect($request, $response));
    }

    public function testSiteItselfIsLeftAlone(): void
    {
        $request = Requests::browser('https://rockfest-kurim.cz/program');

        self::assertNull($this->handle($request));
        self::assertFalse($request->attributes->has(CollectListener::ALIAS_HOP_ATTRIBUTE));
    }

    public function testSubRequestIsLeftAlone(): void
    {
        self::assertNull($this->handle(Requests::browser('https://rockfestkurim.cz/'), HttpKernelInterface::SUB_REQUEST));
    }

    public function testDisabledExtensionDoesNotRedirect(): void
    {
        $this->project->configure([
            'enabled' => false,
            'aliases' => [
                'hosts' => ['rockfestkurim.cz'],
                'target' => 'https://rockfest-kurim.cz',
            ],
        ]);

        self::assertNull($this->handle(Requests::browser('https://rockfestkurim.cz/')));
    }

    /**
     * Tag mode expects the redirect to happen elsewhere. An alias host that
     * reaches the application anyway gets the marker, and the landing page view
     * is the one that counts.
     */
    public function testTagModeAddsTheMarkerInsteadOfCounting(): void
    {
        $this->configure([
            'mode' => 'tag',
        ]);

        $plain = Requests::browser('https://rockfestkurim.cz/program');
        $withQuery = Requests::browser('https://rockfestkurim.cz/program?den=1');

        self::assertSame('https://rockfest-kurim.cz/program?ra_via=rockfestkurim.cz', $this->handle($plain)?->headers->get('Location'));
        self::assertSame('https://rockfest-kurim.cz/program?den=1&ra_via=rockfestkurim.cz', $this->handle($withQuery)?->headers->get('Location'));
        self::assertFalse($plain->attributes->has(CollectListener::ALIAS_HOP_ATTRIBUTE));
    }

    /**
     * Runs before every response, so it does nothing that could delay one:
     * the hop is classified by the collector, after the redirect was sent.
     */
    public function testNothingIsClassifiedBeforeTheResponse(): void
    {
        $constructor = (new ReflectionClass(AliasListener::class))->getConstructor();
        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            self::assertInstanceOf(ReflectionNamedType::class, $type);
            self::assertNotContains($type->getName(), [HitClassifier::class, 'Closure'], 'no classifier before the response');
        }
    }

    public function testInvalidConfigLeavesTheSiteRunning(): void
    {
        $this->project->configure([
            'aliases' => [
                'hosts' => ['rockfestkurim.cz'],
            ],
        ]);

        self::assertNull($this->handle(Requests::browser('https://rockfestkurim.cz/')));
        self::assertSame('error', $this->logger->records[0]['level'] ?? null);
        self::assertStringStartsWith('request-analytics: alias redirect failed: ', $this->logger->records[0]['message'] ?? '');
    }

    /**
     * @param array<string, mixed> $aliases
     */
    private function configure(array $aliases = []): void
    {
        $this->project->configure([
            'aliases' => $aliases + [
                'hosts' => ['rockfestkurim.cz', 'www.rockfestkurim.cz'],
                'target' => 'https://rockfest-kurim.cz',
            ],
        ]);
    }

    private function handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): ?Response
    {
        $event = new RequestEvent(self::createStub(HttpKernelInterface::class), $request, $type);
        (new AliasListener($this->project->configLoader(), $this->project->failureReporter($this->logger)))($event);

        return $event->getResponse();
    }

    /**
     * What the collector stores once the redirect has been sent.
     *
     * @return list<Hit>
     */
    private function collect(Request $request, ?Response $response): array
    {
        self::assertNotNull($response);
        $writer = new MemoryWriter();
        $listener = new CollectListener($this->project->classifier(), $writer, $this->project->failureReporter($this->logger));
        $listener(new TerminateEvent(self::createStub(HttpKernelInterface::class), $request, $response));

        return $writer->hits;
    }
}
