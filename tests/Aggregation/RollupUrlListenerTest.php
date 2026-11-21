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
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use TomVondracek\RequestAnalytics\Aggregation\RollupService;
use TomVondracek\RequestAnalytics\Aggregation\RollupUrlListener;
use TomVondracek\RequestAnalytics\Collector\AliasListener;
use TomVondracek\RequestAnalytics\Collector\CollectListener;
use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryLogger;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryWriter;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;

#[Group('database')]
final class RollupUrlListenerTest extends TestCase
{
    private const SITE = 'https://rockfest-kurim.cz';
    private const URL = '/_request-analytics/rollup';

    private Pipeline $pipeline;
    private MemoryLogger $logger;
    private string $token;

    protected function setUp(): void
    {
        $this->pipeline = new Pipeline();
        $this->logger = new MemoryLogger();
        $this->token = bin2hex(random_bytes(24));
        $this->pipeline->project->configure([
            'aggregation' => [
                'rollup_url' => self::URL,
                'rollup_token' => $this->token,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->pipeline->remove();
    }

    public function testListensBeforeRoutingAndTheFirewall(): void
    {
        $listener = (new ReflectionClass(RollupUrlListener::class))->getAttributes(AsEventListener::class)[0]->newInstance();

        self::assertSame(KernelEvents::REQUEST, $listener->event);
        self::assertGreaterThan(32, $listener->priority);
        self::assertLessThan(AliasListener::PRIORITY, $listener->priority, 'an alias host is redirected first');
    }

    public function testCronCallsTheUrlWithTheToken(): void
    {
        $this->spool();

        $response = $this->handle(Request::create(self::SITE . self::URL . '?token=' . $this->token));

        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'processed' => 1,
            'pending' => 0,
        ], array_intersect_key(self::json($response), [
            'processed' => 0,
            'pending' => 0,
        ]));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    }

    public function testTokenInAnAuthorizationHeader(): void
    {
        $request = Request::create(self::SITE . self::URL . '/', Request::METHOD_POST);
        $request->headers->set('Authorization', 'Bearer ' . $this->token);

        self::assertSame(200, $this->handle($request)?->getStatusCode());
    }

    /**
     * Without the right token the request goes on as if the URL were not
     * there (the site's own 404 page): nobody can tell it exists.
     */
    public function testWrongOrMissingTokenIsLeftToTheSite(): void
    {
        $this->spool();

        foreach (['', '?token=', '?token=' . strrev($this->token), '?token[]=' . $this->token, '?token=' . mb_substr($this->token, 0, -1)] as $query) {
            $request = Request::create(self::SITE . self::URL . $query);
            self::assertNull($this->handle($request), $query);
            self::assertNull($request->attributes->get(CollectListener::NOT_COUNTED_ATTRIBUTE));
        }

        foreach ([Request::METHOD_PUT, Request::METHOD_HEAD, Request::METHOD_DELETE] as $method) {
            self::assertNull($this->handle(Request::create(self::SITE . self::URL, $method)), 'no other answer for another method either');
        }

        self::assertSame(1, $this->pipeline->rollupService()->pending(), 'nothing was rolled up');
    }

    /**
     * A path that is also a page of the site: visitors still get the page.
     */
    public function testPageOfTheSamePathKeepsWorking(): void
    {
        $this->pipeline->project->configure([
            'aggregation' => [
                'rollup_url' => '/program',
                'rollup_token' => $this->token,
            ],
        ]);

        self::assertNull($this->handle(Request::create(self::SITE . '/program')));
        self::assertSame(200, $this->handle(Request::create(self::SITE . '/program?token=' . $this->token))?->getStatusCode());
    }

    public function testOffWhileTheExtensionIsOff(): void
    {
        $this->pipeline->project->configure([
            'enabled' => false,
            'aggregation' => [
                'rollup_url' => self::URL,
                'rollup_token' => $this->token,
            ],
        ]);

        self::assertNull($this->handle(Request::create(self::SITE . self::URL . '?token=' . $this->token)));
    }

    public function testOffByDefault(): void
    {
        $this->pipeline->project->configure([]);

        self::assertNull($this->handle(Request::create(self::SITE . '/_request-analytics/rollup?token=' . $this->token)));
    }

    public function testOtherPathsAreLeftToTheSite(): void
    {
        self::assertNull($this->handle(Request::create(self::SITE . '/program?token=' . $this->token)));
        self::assertNull($this->handle(Request::create(self::SITE . self::URL . '/more?token=' . $this->token)));
        self::assertNull($this->handle(Request::create(self::SITE . self::URL . '?token=' . $this->token), HttpKernelInterface::SUB_REQUEST));
    }

    /**
     * A token from an environment variable that is not set lets nobody in.
     */
    public function testTokenVariableNotSet(): void
    {
        $this->pipeline->project->configure([
            'aggregation' => [
                'rollup_url' => self::URL,
                'rollup_token' => '%env(RA_TEST_UNSET_ROLLUP_TOKEN)%',
            ],
        ]);

        self::assertNull($this->handle(Request::create(self::SITE . self::URL . '?token=')));
    }

    public function testOnlyGetAndPost(): void
    {
        self::assertSame(405, $this->handle(Request::create(self::SITE . self::URL . '?token=' . $this->token, Request::METHOD_PUT))?->getStatusCode());
    }

    /**
     * The call is no visit and no broken link, whatever `exclude_paths` says.
     */
    public function testCallIsNotCounted(): void
    {
        $this->pipeline->project->configure([
            'exclude_paths' => [],
            'aggregation' => [
                'rollup_url' => '/rollup',
                'rollup_token' => $this->token,
            ],
        ]);
        $request = Request::create(self::SITE . '/rollup?token=' . $this->token);
        $response = $this->handle($request);
        self::assertNotNull($response);

        $writer = new MemoryWriter();
        (new CollectListener($this->pipeline->project->classifier(), $writer, $this->pipeline->project->failureReporter($this->logger)))(new TerminateEvent(self::createStub(HttpKernelInterface::class), $request, $response));

        self::assertSame([], $writer->hits);
    }

    public function testFailureIsLoggedAndNotShown(): void
    {
        $listener = new RollupUrlListener(
            $this->pipeline->project->configLoader(),
            $this->pipeline->project->failureReporter($this->logger),
            static fn (): RollupService => throw new RuntimeException('The database password is wrong.'),
            $this->pipeline->analyticsDatabase(...),
        );
        $event = new RequestEvent(self::createStub(HttpKernelInterface::class), Request::create(self::SITE . self::URL . '?token=' . $this->token), HttpKernelInterface::MAIN_REQUEST);

        $listener($event);

        self::assertSame(500, $event->getResponse()?->getStatusCode());
        self::assertStringNotContainsString('password', (string) $event->getResponse()->getContent());
        self::assertCount(1, $this->logger->records);
    }

    private function handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): ?Response
    {
        $event = new RequestEvent(self::createStub(HttpKernelInterface::class), $request, $type);
        (new RollupUrlListener(
            $this->pipeline->project->configLoader(),
            $this->pipeline->project->failureReporter($this->logger),
            $this->pipeline->rollupService(...),
            $this->pipeline->analyticsDatabase(...),
        ))($event);

        return $event->getResponse();
    }

    private function spool(): void
    {
        $this->pipeline->spoolWriter()
            ->write(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/', 'desktop', 'chrome', 'windows', 'cs', 'direct', isEntry: true));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function json(Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
