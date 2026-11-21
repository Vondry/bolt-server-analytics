<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Diagnostics;

use Bolt\Widget\Injector\RequestZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use TomVondracek\RequestAnalytics\Collector\ClassificationTrace;
use TomVondracek\RequestAnalytics\Collector\CollectListener;
use TomVondracek\RequestAnalytics\Diagnostics\DebugDump;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryLogger;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryWriter;
use TomVondracek\RequestAnalytics\Tests\Support\Project;
use TomVondracek\RequestAnalytics\Tests\Support\Requests;

final class DebugDumpTest extends TestCase
{
    private const SITE = 'https://rockfest-kurim.cz';

    private Project $project;
    private MemoryWriter $writer;
    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->project = new Project();
        $this->writer = new MemoryWriter();
        $this->logger = new MemoryLogger();
        $this->dumpOn();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testOffByDefault(): void
    {
        $this->project->configure([]);

        $this->terminate(Requests::browser(self::SITE . '/program'));

        self::assertCount(1, $this->writer->hits, 'collected as always');
        self::assertFileDoesNotExist($this->dump()->file());
    }

    public function testPageViewIsDumpedWithWhatWasDecided(): void
    {
        $this->terminate(Requests::browser(self::SITE . '/program?utm_source=seznam&utm_medium=cpc&utm_campaign=RTG+%7c+BNR'));

        $record = $this->record();
        self::assertSame('2026-11-13T10:52:00+01:00', $record['at'], 'in the site timezone');
        self::assertSame(['GET', 'https://rockfest-kurim.cz/program?utm_source=seznam&utm_medium=cpc&utm_campaign=RTG+%7c+BNR', 200, 'frontend'], [$record['method'], $record['url'], $record['status'], $record['zone']]);
        self::assertSame(['pageview', true, null], [$record['result'], $record['collected'], $record['reason']]);
        self::assertSame(Requests::CHROME, $this->field('headers', 'User-Agent'));
        self::assertSame('paid_search', $this->field('hit', 'channel'));
        self::assertSame('rtg bnr', $this->field('hit', 'campaign'));
        self::assertSame(0o660, fileperms($this->dump()->file()) & 0o777, 'owner and group only');
    }

    /**
     * Never a session, a secret or an IP address, whatever the request sends.
     */
    public function testNoSecretsAndNoIpAddresses(): void
    {
        $request = Requests::browser(self::SITE . '/unsubscribe/aB3dE5fG7hJ9kL1mN3pQ?token=s3cr3t-T0ken-value&password=hunter2&api_key=k3y&utm_source=newsletter&email=jane.doe%40example.com&page=2', [
            'Cookie' => 'bolt_session=SESSION-SECRET-123',
            'Authorization' => 'Bearer BEARER-SECRET-456',
            'X-CSRF-Token' => 'CSRF-SECRET-789',
            'X-Forwarded-For' => '203.0.113.7',
            'X-Real-IP' => '203.0.113.8',
            'CF-Connecting-IP' => '203.0.113.9',
            'Signature-Input' => 'sig1=("@authority");keyid="SIGNATURE-KEY-ID"',
            'Signature' => 'sig1=:SIGNATURE-VALUE:',
            'Referer' => 'https://mail.example.com/reset/5d41402abc4b2a76b9719d911017c592?code=REFERER-CODE&to=jane%40example.com',
        ]);
        $request->server->set('REMOTE_ADDR', '203.0.113.10');

        $this->terminate($request);

        $content = (string) file_get_contents($this->dump()->file());
        foreach (['SESSION-SECRET', 'BEARER-SECRET', 'CSRF-SECRET', '203.0.113', 'SIGNATURE-KEY-ID', 'SIGNATURE-VALUE', 's3cr3t', 'hunter2', 'k3y', 'jane', 'aB3dE5fG7hJ9kL1mN3pQ', '5d41402abc4b2a76b9719d911017c592', 'REFERER-CODE'] as $secret) {
            self::assertStringNotContainsString($secret, $content, $secret);
        }

        $record = $this->record();
        self::assertSame('https://rockfest-kurim.cz/unsubscribe/***?token=***&password=***&api_key=***&utm_source=newsletter&email=(redacted)&page=2', $record['url']);
        self::assertSame('https://mail.example.com/reset/***?code=***&to=(redacted)', $this->field('headers', 'Referer'));
        self::assertSame(['(sent)', '(sent)'], [$this->field('headers', 'Signature-Input'), $this->field('headers', 'Signature')]);
        self::assertArrayNotHasKey('Cookie', $this->section('headers'));
        self::assertArrayNotHasKey('X-Forwarded-For', $this->section('headers'));
    }

    /**
     * Of a visitor who opted out only the path, the signal and the decision.
     */
    public function testOptOutKeepsOnlyThePathAndTheSignal(): void
    {
        $this->terminate(Requests::browser(self::SITE . '/program?utm_source=newsletter', [
            'Sec-GPC' => '1',
        ]));

        $record = $this->record();
        self::assertSame('https://rockfest-kurim.cz/program', $record['url']);
        self::assertSame([
            'Sec-GPC' => '1',
        ], $this->section('headers'));
        self::assertSame('(opt_out)', $this->field('hit', 'device'));
    }

    /**
     * @return iterable<string, array{Request, int, string}>
     */
    public static function skipped(): iterable
    {
        yield 'prefetch' => [Requests::browser(self::SITE . '/program', [
            'Sec-Purpose' => 'prefetch;prerender',
        ]), 200, ClassificationTrace::PREFETCH];
        yield 'excluded path' => [Requests::browser(self::SITE . '/files/poster.pdf.php'), 200, ClassificationTrace::EXCLUDED];
        yield 'not a page' => [Requests::browser(self::SITE . '/program', [
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
        ]), 200, ClassificationTrace::NOT_DOCUMENT];
        yield 'redirect' => [Requests::browser(self::SITE . '/old'), 301, ClassificationTrace::STATUS];
        yield 'POST' => [Requests::browser(self::SITE . '/contact', [], Request::METHOD_POST), 200, ClassificationTrace::NOT_GET];
    }

    #[DataProvider('skipped')]
    public function testSaysWhyARequestWasNotCounted(Request $request, int $status, string $reason): void
    {
        $this->project->configure([
            'exclude_paths' => ['^/files/'],
            'debug' => [
                'dump' => true,
                'until' => '2026-11-13 12:00',
            ],
        ]);

        $this->terminate($request, $status);

        self::assertSame(['skipped', false, $reason], [$this->record()['result'], $this->record()['collected'], $this->record()['reason']]);
        self::assertSame([], $this->writer->hits);
    }

    public function testBotIsDumpedAsABot(): void
    {
        $this->terminate(Requests::browser(self::SITE . '/program', [
            'User-Agent' => 'curl/8.7.1',
        ]));

        self::assertSame(['bot', 'curl'], [$this->record()['result'], $this->field('hit', 'bot')]);
    }

    /**
     * While the site is developed nothing is collected; the dump says what
     * would have been.
     */
    public function testInDevelopmentSaysWhatWouldHaveBeenCollected(): void
    {
        $this->terminate(Requests::browser(self::SITE . '/program'), environment: 'dev');

        self::assertSame([], $this->writer->hits);
        self::assertSame(['pageview', false, ClassificationTrace::DEVELOPMENT], [$this->record()['result'], $this->record()['collected'], $this->record()['reason']]);
    }

    public function testInDevelopmentWithoutTheDumpNothingHappens(): void
    {
        $this->project->configure([]);

        $this->terminate(Requests::browser(self::SITE . '/program'), environment: 'dev');

        self::assertSame([], $this->writer->hits);
        self::assertFileDoesNotExist($this->dump()->file());
    }

    /**
     * The admin's own requests, and the rollup URL with its token, are
     * never dumped.
     */
    public function testNotTheBackendNorTheRollupUrl(): void
    {
        $this->terminate(Requests::browser(self::SITE . '/bolt/edit/12'));
        $backend = Requests::browser(self::SITE . '/extension-page');
        RequestZone::setToRequest($backend, RequestZone::BACKEND);
        $this->terminate($backend);
        $rollup = Requests::browser(self::SITE . '/_request-analytics/rollup?token=abc');
        $rollup->attributes->set(CollectListener::NOT_COUNTED_ATTRIBUTE, true);
        $this->terminate($rollup);

        self::assertFileDoesNotExist($this->dump()->file());
    }

    public function testEndsAtUntil(): void
    {
        $this->project->clock->modify('+2 hours');

        $this->terminate(Requests::browser(self::SITE . '/program'));

        self::assertCount(1, $this->writer->hits);
        self::assertFileDoesNotExist($this->dump()->file());
    }

    public function testStopsAtMaxMb(): void
    {
        $this->project->configure([
            'debug' => [
                'dump' => true,
                'until' => '2026-11-13 12:00',
                'max_mb' => 1,
            ],
        ]);
        $file = $this->dump()->file();
        mkdir(dirname($file), 0o777, true);
        file_put_contents($file, str_repeat('x', 1024 * 1024));

        $this->terminate(Requests::browser(self::SITE . '/program'));

        self::assertSame(1024 * 1024, filesize($file));
        self::assertCount(1, $this->writer->hits, 'collected as always');
    }

    /**
     * A dump that cannot be written is logged; the visit is collected all the same.
     */
    public function testFailureOfTheDumpNeverStopsTheCollection(): void
    {
        mkdir($this->dump()->file(), 0o777, true);

        $this->terminate(Requests::browser(self::SITE . '/program'));

        self::assertCount(1, $this->writer->hits);
        self::assertCount(1, $this->logger->records);
        self::assertStringContainsString('debug dump', $this->logger->records[0]['message']);
    }

    public function testFileIsDeletedSomeDaysAfterTheDumpEnded(): void
    {
        $file = $this->dump()->file();
        mkdir(dirname($file), 0o777, true);
        file_put_contents($file, "{}\n");

        touch($file, time() - 86_400);
        self::assertFalse($this->dump()->cleanUp(), 'a day old');

        touch($file, time() - 8 * 86_400);
        self::assertFalse($this->dump()->cleanUp(), 'still running');

        $this->project->configure([]);
        self::assertTrue($this->dump()->cleanUp());
        self::assertFileDoesNotExist($file);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function urls(): iterable
    {
        yield 'plain' => ['https://example.com/program?page=2', 'https://example.com/program?page=2'];
        yield 'numeric ids stay' => ['https://example.com/product/12345', 'https://example.com/product/12345'];
        yield 'slug stays' => ['https://example.com/rock-fest-2026-program', 'https://example.com/rock-fest-2026-program'];
        yield 'uuid' => ['https://example.com/confirm/550e8400-e29b-41d4-a716-446655440000', 'https://example.com/confirm/***'];
        yield 'jwt' => ['https://example.com/login/eyJhbGciOi.eyJzdWIiOiIx.SflKxwRJSM', 'https://example.com/login/***'];
        yield 'secret words' => ['https://example.com/?access_token=a&reset-code=b&sid=c&keyword=rock', 'https://example.com/?access_token=***&reset-code=***&sid=***&keyword=rock'];
        yield 'e-mail in the path' => ['https://example.com/u/jane%40example.com', 'https://example.com/u/(redacted)'];
        yield 'no fragment' => ['https://example.com/a#secret', 'https://example.com/a'];
        yield 'relative' => ['/a?token=x', '/a?token=***'];
    }

    #[DataProvider('urls')]
    public function testUrlWithoutSecrets(string $url, string $expected): void
    {
        self::assertSame($expected, DebugDump::url($url));
    }

    private function dumpOn(): void
    {
        $this->project->configure([
            'debug' => [
                'dump' => true,
                // In Prague; the project's clock says 10:52 there.
                'until' => '2026-11-13 12:00',
            ],
        ]);
    }

    private function dump(): DebugDump
    {
        return new DebugDump($this->project->configLoader(), $this->project->siteTime(), $this->project->dir);
    }

    private function terminate(Request $request, int $status = 200, string $environment = 'prod'): void
    {
        if (! $request->attributes->has('zone')) {
            RequestZone::setToRequest($request, RequestZone::FRONTEND);
        }

        $listener = new CollectListener($this->project->classifier(), $this->writer, $this->project->failureReporter($this->logger), '/bolt', $environment, false, $this->dump());
        $listener(new TerminateEvent(self::createStub(HttpKernelInterface::class), $request, new Response('', $status)));
    }

    /**
     * @return array<array-key, mixed> the last line of the dump
     */
    private function record(): array
    {
        $lines = file($this->dump()->file(), FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        $record = json_decode((string) end($lines), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($record);

        return $record;
    }

    /**
     * @return array<array-key, mixed> `headers` or `hit` of the last line
     */
    private function section(string $name): array
    {
        $section = $this->record()[$name] ?? null;
        self::assertIsArray($section, $name);

        return $section;
    }

    private function field(string $section, string $key): mixed
    {
        return $this->section($section)[$key] ?? null;
    }
}
