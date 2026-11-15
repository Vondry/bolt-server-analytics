<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Security;

use Bolt\Widget\Injector\RequestZone;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use TomVondracek\RequestAnalytics\Collector\CollectListener;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryLogger;
use TomVondracek\RequestAnalytics\Tests\Support\Payloads;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;
use TomVondracek\RequestAnalytics\Tests\Support\Requests;
use TomVondracek\RequestAnalytics\Tests\Support\StoredValues;

/**
 * Every part of a request the collector reads, filled with every payload of
 * Payloads (XSS, Twig, PHP, SQL, CRLF, traversal, Unicode tricks, …), through
 * the real listener, both writers and the rollup.
 *
 * For each: the collector neither fails nor refuses a hit it built itself
 * (Hit checks every value), every value in every table is safe, no table is
 * created, dropped or emptied by injected SQL, and a request that is a visit
 * whatever the payload is still counted.
 */
#[Group('database')]
final class CollectedInputTest extends TestCase
{
    private const SITE = 'https://example.com';

    private Pipeline $pipeline;
    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->pipeline = new Pipeline();
        $this->logger = new MemoryLogger();
        $this->pipeline->project->configure([
            'search_params' => ['searchTerm', 'q'],
            'aliases' => [
                // Tag mode: the marker comes from the URL.
                'hosts' => ['poster-a2', 'alias.example'],
                'target' => self::SITE,
                'mode' => 'tag',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->pipeline->remove();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function vectors(): iterable
    {
        foreach (array_keys(self::requests()) as $vector) {
            yield $vector => [$vector];
        }
    }

    /**
     * Through the spool, the collector's only writer: the lines are read back
     * from disk by the rollup.
     */
    #[DataProvider('vectors')]
    public function testEveryPayloadIsStoredSafely(string $vector): void
    {
        [$build, $counted] = self::requests()[$vector];
        $database = $this->pipeline->database;
        $tables = StoredValues::tableNames($database);
        $listener = new CollectListener(
            $this->pipeline->project->classifier(),
            $this->pipeline->spoolWriter(),
            $this->pipeline->project->failureReporter($this->logger),
        );

        foreach (Payloads::all() as $payload) {
            [$request, $status] = $build($payload);
            // As Bolt marks a page of the site.
            RequestZone::setToRequest($request, RequestZone::FRONTEND);
            $listener(new TerminateEvent(self::createStub(HttpKernelInterface::class), $request, new Response('', $status)));
        }

        self::assertSame([], $this->logger->records, 'the collector neither failed nor refused a hit it built');
        StoredValues::assertAllSafe($database, $vector . ', raw rows');

        $this->pipeline->rollupService()
            ->rollup(PHP_INT_MAX);

        $checked = StoredValues::assertAllSafe($database, $vector . ', aggregated');
        self::assertSame($tables, StoredValues::tableNames($database), 'no table created, dropped or renamed');
        if ($counted) {
            self::assertGreaterThan(0, $checked);
            self::assertSame(count(Payloads::all()), StoredValues::counted($database), 'every request still counted');
        }
    }

    /**
     * Where a payload can be in a request, and whether every such request is
     * still a counted hit.
     *
     * @return array<string, array{Closure(string): array{Request, int}, bool}>
     */
    private static function requests(): array
    {
        $query = static fn (array $parameters): string => self::SITE . '/?' . http_build_query($parameters);

        return [
            'path' => [static fn (string $payload): array => self::page(self::SITE . '/p/' . rawurlencode($payload)), true],
            'path, slashes kept' => [static fn (string $payload): array => self::page(self::SITE . '/' . str_replace('%2F', '/', rawurlencode($payload))), true],
            'path of a missing page' => [static fn (string $payload): array => self::page(self::SITE . '/' . rawurlencode($payload), [], 404), true],
            'search term' => [static fn (string $payload): array => self::page($query(['searchTerm' => $payload])), true],
            'second search parameter' => [static fn (string $payload): array => self::page($query(['q' => $payload])), true],
            'utm_source' => [static fn (string $payload): array => self::page($query(['utm_source' => $payload, 'utm_medium' => 'email'])), true],
            'utm_medium' => [static fn (string $payload): array => self::page($query(['utm_source' => 'newsletter', 'utm_medium' => $payload])), true],
            'utm_campaign' => [static fn (string $payload): array => self::page($query(['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => $payload])), true],
            'click id' => [static fn (string $payload): array => self::page($query(['gclid' => $payload, 'fbclid' => $payload])), true],
            'alias marker' => [static fn (string $payload): array => self::page($query(['ra_via' => $payload])), true],
            'internal referrer' => [static fn (string $payload): array => self::page(self::SITE . '/next', [
                'Referer' => self::SITE . '/' . rawurlencode($payload),
                'Sec-Fetch-Site' => 'same-origin',
            ]), true],
            'internal referrer of a missing page' => [static fn (string $payload): array => self::page(self::SITE . '/missing', [
                'Referer' => self::SITE . '/' . str_replace('%2F', '/', rawurlencode($payload)),
                'Sec-Fetch-Site' => 'same-origin',
            ], 404), true],
            'external referrer host' => [static fn (string $payload): array => self::page(self::SITE . '/', [
                'Referer' => 'https://' . $payload . '/x',
                'Sec-Fetch-Site' => 'cross-site',
            ]), true],
            'external referrer host of a missing page' => [static fn (string $payload): array => self::page(self::SITE . '/missing', [
                'Referer' => 'https://' . rawurlencode($payload) . '.example/x',
                'Sec-Fetch-Site' => 'cross-site',
            ], 404), true],
            'whole referrer' => [static fn (string $payload): array => self::page(self::SITE . '/', [
                'Referer' => $payload,
                'Sec-Fetch-Site' => 'cross-site',
            ]), true],
            'app referrer' => [static fn (string $payload): array => self::page(self::SITE . '/', [
                'Referer' => 'android-app://' . $payload,
                'Sec-Fetch-Site' => 'cross-site',
            ]), true],
            'user agent' => [static fn (string $payload): array => self::page(self::SITE . '/', [
                'User-Agent' => $payload,
            ]), true],
            'user agent of a missing page' => [static fn (string $payload): array => self::page(self::SITE . '/missing', [
                'User-Agent' => 'Mozilla/5.0 (compatible; ' . $payload . ') bot',
            ], 404), true],
            'accept-language' => [static fn (string $payload): array => self::page(self::SITE . '/', [
                'Accept-Language' => $payload,
            ]), true],
            'client hints' => [static fn (string $payload): array => self::page(self::SITE . '/', [
                'Sec-CH-UA' => $payload,
                'Sec-CH-UA-Platform' => $payload,
                'Sec-CH-UA-Mobile' => $payload,
            ]), true],
            'privacy signals' => [static fn (string $payload): array => self::page(self::SITE . '/', [
                'DNT' => $payload,
                'Sec-GPC' => $payload,
            ]), true],
            'cache-control' => [static fn (string $payload): array => self::page(self::SITE . '/', [
                'Cache-Control' => $payload,
                'Pragma' => $payload,
            ]), true],
            // These decide whether a request is a visit at all: not every payload leaves one.
            'fetch metadata' => [static fn (string $payload): array => self::page(self::SITE . '/', [
                'Sec-Fetch-Site' => $payload,
                'Sec-Fetch-Mode' => $payload,
                'Sec-Fetch-Dest' => $payload,
            ]), false],
            'prefetch signals' => [static fn (string $payload): array => self::page(self::SITE . '/', [
                'Purpose' => $payload,
                'Sec-Purpose' => $payload,
                'X-Purpose' => $payload,
                'X-Moz' => $payload,
            ]), false],
            'accept' => [static fn (string $payload): array => self::page(self::SITE . '/', [
                'Accept' => $payload,
            ]), false],
            'everything at once' => [static fn (string $payload): array => self::page(self::SITE . '/' . rawurlencode($payload) . '?' . http_build_query([
                'searchTerm' => $payload,
                'utm_source' => $payload,
                'utm_medium' => $payload,
                'utm_campaign' => $payload,
                'ra_via' => $payload,
            ]), [
                'Referer' => self::SITE . '/' . rawurlencode($payload),
                'Sec-Fetch-Site' => 'same-origin',
                'User-Agent' => Requests::CHROME . ' ' . $payload,
                'Accept-Language' => $payload,
            ], 404), true],
        ];
    }

    /**
     * @param array<string, string|null> $headers
     *
     * @return array{Request, int}
     */
    private static function page(string $url, array $headers = [], int $status = 200): array
    {
        return [Requests::browser($url, $headers), $status];
    }
}
