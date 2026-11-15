<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use TomVondracek\RequestAnalytics\Collector\AliasListener;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryLogger;
use TomVondracek\RequestAnalytics\Tests\Support\Payloads;
use TomVondracek\RequestAnalytics\Tests\Support\Project;
use TomVondracek\RequestAnalytics\Tests\Support\Requests;

/**
 * The alias redirect puts the requested address into a `Location` header:
 * whatever the address holds, the redirect goes to the configured target and
 * nowhere else (no open redirect), and the header stays one header (no
 * response splitting).
 */
final class AliasRedirectTest extends TestCase
{
    private const TARGET = 'https://example.com';
    private const ALIAS = 'alias.example';

    private Project $project;
    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->project = new Project();
        $this->logger = new MemoryLogger();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modes(): iterable
    {
        yield 'count' => ['count'];
        yield 'tag' => ['tag'];
    }

    #[DataProvider('modes')]
    public function testEveryRedirectStaysOnTheTarget(string $mode): void
    {
        $this->project->configure([
            'aliases' => [
                'hosts' => [self::ALIAS],
                'target' => self::TARGET,
                'mode' => $mode,
            ],
        ]);

        foreach (Payloads::all() as $name => $payload) {
            $encoded = rawurlencode($payload);
            $requests = [
                'path' => Requests::browser('https://' . self::ALIAS . '/' . $encoded),
                'path, slashes kept' => Requests::browser('https://' . self::ALIAS . '/' . str_replace('%2F', '/', $encoded)),
                'query' => Requests::browser('https://' . self::ALIAS . '/?q=' . $encoded),
            ];
            // The request target as the server passed it, also one that does not start with `/`.
            foreach (['raw target' => $payload, 'raw target after a slash' => '/' . $payload] as $kind => $target) {
                $request = Requests::browser('https://' . self::ALIAS . '/');
                $request->server->set('REQUEST_URI', $target);
                $requests[$kind] = $request;
            }

            foreach ($requests as $kind => $request) {
                $this->assertRedirectsToTheTarget($request, sprintf('%s, %s (%s)', $name, $kind, $mode));
            }
        }

        foreach (['@evil.example', '.evil.example/', ':evil@evil.example', '?x=1@evil.example', '#@evil.example', '\\evil.example', '//evil.example', '/\\evil.example', "\r\nLocation: https://evil.example"] as $target) {
            $request = Requests::browser('https://' . self::ALIAS . '/');
            $request->server->set('REQUEST_URI', $target);
            $this->assertRedirectsToTheTarget($request, json_encode($target) . ' (' . $mode . ')');
        }

        self::assertSame([], $this->logger->records);
    }

    private function assertRedirectsToTheTarget(Request $request, string $context): void
    {
        $event = new RequestEvent(self::createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        (new AliasListener($this->project->configLoader(), $this->project->failureReporter($this->logger)))($event);
        $response = $event->getResponse();

        self::assertInstanceOf(RedirectResponse::class, $response, $context);
        $location = $response->getTargetUrl();
        self::assertStringStartsWith(self::TARGET . '/', $location, $context . ': ' . $location);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x20\x7F]/', $location, $context . ': no space, line break or control character');

        $parts = parse_url($location);
        self::assertIsArray($parts, $context);
        self::assertSame('https', $parts['scheme'] ?? null, $context);
        self::assertSame('example.com', $parts['host'] ?? null, $context . ': ' . $location);
        self::assertArrayNotHasKey('user', $parts, $context);
        self::assertArrayNotHasKey('port', $parts, $context);
        // What a browser does with a backslash: still the same host.
        $browser = parse_url(str_replace('\\', '/', $location));
        self::assertSame('example.com', is_array($browser) ? ($browser['host'] ?? null) : null, $context . ': ' . $location);

        // The body of a redirect shows the address as HTML.
        $body = (string) $response->getContent();
        self::assertStringNotContainsString('<script', mb_strtolower($body), $context);
        self::assertDoesNotMatchRegularExpression('~<(?!/?(?:html|head|meta|title|body|a)\b|!DOCTYPE)~i', $body, $context);
    }
}
