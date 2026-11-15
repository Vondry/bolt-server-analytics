<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use TomVondracek\RequestAnalytics\Controller\ApiController;
use TomVondracek\RequestAnalytics\Tests\Support\ApiControllers;
use TomVondracek\RequestAnalytics\Tests\Support\FakePageCatalog;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryLogger;
use TomVondracek\RequestAnalytics\Tests\Support\Payloads;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;
use TomVondracek\RequestAnalytics\Tests\Support\StoredValues;

/**
 * Every parameter of every endpoint, filled with every payload, by a user
 * who may see the statistics: the answer is the data or "invalid" (400),
 * never a failure (500), never SQL or a stack trace; valid JSON with markup
 * escaped; and nothing in the database changes.
 */
#[Group('database')]
final class ApiInputTest extends TestCase
{
    /**
     * A valid request of each endpoint, and the parameters it reads.
     */
    private const ENDPOINTS = [
        'trend' => [['range' => '30d'], ['range', 'metric', 'compare', 'interval', 'from', 'to']],
        'breakdown' => [['from' => '2026-11-01', 'to' => '2026-11-13'], ['from', 'to', 'metric', 'compare', 'compareFrom', 'compareTo']],
        'pages' => [['from' => '2026-11-01', 'to' => '2026-11-13'], ['from', 'to', 'metric', 'order', 'page', 'q', 'compareFrom', 'compareTo']],
        'summary' => [['from' => '2026-11-01', 'to' => '2026-11-13'], ['from', 'to', 'compare', 'compareFrom', 'compareTo']],
        'brokenLinks' => [['from' => '2026-11-01', 'to' => '2026-11-13'], ['from', 'to', 'page']],
        'searches' => [['from' => '2026-11-01', 'to' => '2026-11-13'], ['from', 'to', 'page']],
        'pageFlow' => [['from' => '2026-11-01', 'to' => '2026-11-13', 'path' => '/'], ['from', 'to', 'path']],
        'bots' => [['from' => '2026-11-01', 'to' => '2026-11-13'], ['from', 'to']],
        'hours' => [['from' => '2026-11-01', 'to' => '2026-11-13'], ['from', 'to']],
        'sources' => [['from' => '2026-11-01', 'to' => '2026-11-13'], ['from', 'to', 'channel', 'q', 'page']],
        'campaigns' => [['from' => '2026-11-01', 'to' => '2026-11-13'], ['from', 'to']],
        'aliases' => [['from' => '2026-11-01', 'to' => '2026-11-13'], ['from', 'to']],
        'erasePreview' => [['days' => '7'], ['days']],
    ];

    private Pipeline $pipeline;
    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->pipeline = new Pipeline();
        $this->logger = new MemoryLogger();

        $this->pipeline->daily([
            '2026-11-12' => [10, 4],
            '2026-11-13' => [5, 2],
        ]);
        $this->pipeline->pages('2026-11-13', '/', 5, true);
        $this->pipeline->sources('2026-11-13', '/', 2, 'organic_search', 'google');
        $this->pipeline->notFound('2026-11-13', '/missing', 6, '/');
        $this->pipeline->search('2026-11-13', 'tickets', 6);
        $this->pipeline->transitions('2026-11-13', '/', '/program', 3);
        $this->pipeline->bots('2026-11-13', 'googlebot', 4);
        $this->pipeline->hourly('2026-11-13', 10, 5);
    }

    protected function tearDown(): void
    {
        $this->pipeline->remove();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function parameters(): iterable
    {
        foreach (self::ENDPOINTS as $endpoint => [, $parameters]) {
            foreach ($parameters as $parameter) {
                yield $endpoint . ' ' . $parameter => [$endpoint, $parameter];
            }
        }
    }

    #[DataProvider('parameters')]
    public function testEveryPayloadInEveryParameterIsDataOrInvalid(string $endpoint, string $parameter): void
    {
        [$valid] = self::ENDPOINTS[$endpoint];
        $before = $this->snapshot();
        $controller = ApiControllers::make($this->pipeline, $this->logger, new FakePageCatalog('en', ['en', 'nl'], [
            '/' => '<script>alert("title")</script>',
        ]));

        foreach (Payloads::all() as $name => $payload) {
            foreach ([$payload, [$payload], ['nested' => [$payload]]] as $value) {
                $response = $this->call($controller, $endpoint, ApiControllers::request([$parameter => $value] + $valid));
                $this->assertSafeAnswer($response, sprintf('%s %s = %s', $endpoint, $parameter, $name), $payload);
            }
        }

        self::assertSame([], $this->logger->records, 'no failure');
        self::assertSame($before, $this->snapshot(), 'nothing changed');
    }

    /**
     * The token and the origin are checked first: a forged one is refused,
     * whatever it holds.
     */
    public function testForgedTokensAndOriginsAreRefused(): void
    {
        $controller = ApiControllers::make($this->pipeline, $this->logger, new FakePageCatalog('en', ['en', 'nl'], [
            '/' => '<script>alert("title")</script>',
        ]));

        foreach (Payloads::all() as $name => $payload) {
            $request = Request::create('/summary', 'GET', self::ENDPOINTS['summary'][0]);
            $request->headers->set(ApiController::CSRF_HEADER, $payload);
            $response = $controller->summary($request);
            self::assertSame(403, $response->getStatusCode(), $name);
            $this->assertSafeAnswer($response, 'token ' . $name, $payload);

            $request = ApiControllers::request(self::ENDPOINTS['summary'][0]);
            $request->headers->set('Origin', $payload);
            $request->headers->set('Sec-Fetch-Site', $payload);
            self::assertSame(403, $controller->summary($request)->getStatusCode(), 'origin ' . $name);
        }
    }

    /**
     * Deleting takes a number of days or `all`; anything else deletes nothing.
     */
    public function testEraseTakesNothingButDays(): void
    {
        $before = $this->snapshot();
        $controller = ApiControllers::make($this->pipeline, $this->logger, new FakePageCatalog('en', ['en', 'nl'], [
            '/' => '<script>alert("title")</script>',
        ]));

        foreach (Payloads::all() as $name => $payload) {
            foreach ([$payload, [$payload]] as $value) {
                $request = Request::create('/erase', 'POST', [
                    'days' => $value,
                ]);
                $request->headers->set(ApiController::CSRF_HEADER, ApiControllers::ERASE_TOKEN);
                $request->headers->set('Sec-Fetch-Site', 'same-origin');
                $request->setSession(new Session(new MockArraySessionStorage()));

                $response = $controller->erase($request);

                self::assertSame(400, $response->getStatusCode(), $name);
                $this->assertSafeAnswer($response, 'erase ' . $name, $payload);
            }

            $request = Request::create('/erase', 'POST', [
                'days' => 'all',
                '_csrf_token' => $payload,
            ]);
            self::assertSame(403, $controller->erase($request)->getStatusCode(), 'erase token ' . $name);
        }

        self::assertSame($before, $this->snapshot(), 'nothing deleted');
    }

    /**
     * 200 with data or 400 "invalid" (403 for a token): never 500, valid JSON,
     * markup and quotes escaped by the JSON encoder, nothing of the server
     * (SQL, classes, files, traces) in the message.
     */
    private function assertSafeAnswer(JsonResponse $response, string $context, string $payload): void
    {
        $status = $response->getStatusCode();
        self::assertContains($status, [200, 400, 403], $context);
        self::assertSame('application/json', $response->headers->get('Content-Type'), $context);
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $context);

        $body = (string) $response->getContent();
        self::assertIsArray(json_decode($body, true, 64, JSON_THROW_ON_ERROR), $context);
        foreach (['<', '>', "'", '&', "\n", "\r"] as $raw) {
            self::assertStringNotContainsString($raw, $body, $context . ': raw ' . json_encode($raw) . ' in the JSON');
        }

        if ($status !== 200) {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            self::assertContains($data['error'] ?? null, ['invalid', 'csrf', 'origin'], $context);
            $message = $data['message'] ?? null;
            self::assertIsString($message);
            // The message may name the value it refuses (escaped like all JSON); nothing else of the server.
            $message = str_replace($payload, '', $message);
            self::assertDoesNotMatchRegularExpression('~SQLSTATE|SQLite|syntax error|Doctrine\\\\|/vendor/|/src/|\.php\b|Stack trace|#\d+ /~i', $message, $context);
        }
    }

    private function call(ApiController $controller, string $endpoint, Request $request): JsonResponse
    {
        return match ($endpoint) {
            'trend' => $controller->trend($request),
            'breakdown' => $controller->breakdown($request),
            'pages' => $controller->pages($request),
            'summary' => $controller->summary($request),
            'brokenLinks' => $controller->brokenLinks($request),
            'searches' => $controller->searches($request),
            'pageFlow' => $controller->pageFlow($request),
            'bots' => $controller->bots($request),
            'hours' => $controller->hours($request),
            'sources' => $controller->sources($request),
            'campaigns' => $controller->campaigns($request),
            'aliases' => $controller->aliases($request),
            'erasePreview' => $controller->erasePreview($request),
            default => self::fail('Unknown endpoint ' . $endpoint),
        };
    }

    /**
     * Every row of every table, and the tables there are.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $database = $this->pipeline->database;
        $rows = ['tables' => StoredValues::tableNames($database)];
        foreach (StoredValues::tables($database) as $table) {
            $rows[$table] = $database->rows($table);
        }

        return $rows;
    }
}
