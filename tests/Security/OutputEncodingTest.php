<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use TomVondracek\RequestAnalytics\Insight\Insight;
use TomVondracek\RequestAnalytics\Insight\InsightFormatter;
use TomVondracek\RequestAnalytics\Insight\Message;
use TomVondracek\RequestAnalytics\Insight\Param;
use TomVondracek\RequestAnalytics\Tests\Support\ApiControllers;
use TomVondracek\RequestAnalytics\Tests\Support\FakePageCatalog;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryLogger;
use TomVondracek\RequestAnalytics\Tests\Support\Payloads;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;
use TomVondracek\RequestAnalytics\Tests\Support\Translations;

/**
 * The second line of defence: values that never passed the collector's
 * whitelists (rows of an older version, a database changed by hand, a page
 * title an editor typed) still reach the browser only as data.
 *
 * - the endpoints send them as JSON, `<`, `>`, `&` and quotes escaped, and the
 *   scripts write them with textContent (no innerHTML in any template)
 * - the insights, written into the page as HTML, escape every value
 * - the scripts link only paths of the site
 */
final class OutputEncodingTest extends TestCase
{
    private const DAY = '2026-11-13';
    private const RANGE = [
        'from' => '2026-11-07',
        'to' => '2026-11-13',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function payloads(): iterable
    {
        foreach (Payloads::all() as $name => $payload) {
            yield $name => [$payload];
        }
    }

    #[DataProvider('payloads')]
    #[Group('database')]
    public function testPlantedValuesLeaveTheEndpointsOnlyAsEscapedData(string $payload): void
    {
        $pipeline = new Pipeline();

        try {
            // What a column of this database can hold: as long as the column,
            // and on a server valid UTF-8 without NUL (SQLite takes any bytes).
            $fit = static function (int $length) use ($pipeline, $payload): string {
                $value = $pipeline->database->isSqlite() ? $payload : str_replace("\0", '', mb_scrub($payload, 'UTF-8'));

                return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length);
            };

            // Above the threshold, so every report shows them.
            $pipeline->daily([
                self::DAY => [500, 200],
            ]);
            $pipeline->pages(self::DAY, $fit(255), 100, true);
            $pipeline->sources(self::DAY, $fit(255), 100, 'email', $fit(64), $fit(64));
            $pipeline->search(self::DAY, $fit(64), 100);
            $pipeline->notFound(self::DAY, '/missing', 100, $fit(255));
            $pipeline->transitions(self::DAY, $fit(255), '/', 100);
            $pipeline->aliases(self::DAY, $fit(255), $fit(255), 100, 'direct', $fit(64));
            $pipeline->bots(self::DAY, $fit(32), 100);
            $pipeline->clients(self::DAY, 100, $fit(16), $fit(16), $fit(16), true);
            $pipeline->locales(self::DAY, $fit(8), 100, true);

            $logger = new MemoryLogger();
            $controller = ApiControllers::make($pipeline, $logger, new FakePageCatalog('en', ['en'], [
                '/' => $payload,
            ]));
            $answers = [
                'pages' => $controller->pages(ApiControllers::request(self::RANGE)),
                'sources' => $controller->sources(ApiControllers::request(self::RANGE)),
                'campaigns' => $controller->campaigns(ApiControllers::request(self::RANGE)),
                'aliases' => $controller->aliases(ApiControllers::request(self::RANGE)),
                'searches' => $controller->searches(ApiControllers::request(self::RANGE)),
                'broken links' => $controller->brokenLinks(ApiControllers::request(self::RANGE)),
                'bots' => $controller->bots(ApiControllers::request(self::RANGE)),
                'breakdown' => $controller->breakdown(ApiControllers::request(self::RANGE)),
                'page flow' => $controller->pageFlow(ApiControllers::request(self::RANGE + [
                    'path' => '/',
                ])),
            ];

            $carried = false;
            foreach ($answers as $name => $response) {
                self::assertSame(200, $response->getStatusCode(), $name . ': ' . $response->getContent());
                $data = self::assertEscapedJson($response, $name);
                $carried = $carried || self::contains($data, $fit(64)) || self::contains($data, $fit(255));
            }

            self::assertSame([], $logger->records);
            if (mb_check_encoding($fit(255), 'UTF-8')) {
                self::assertTrue($carried, 'the value arrives as it is, as data');
            }
        } finally {
            $pipeline->remove();
        }
    }

    /**
     * Every kind of value an insight holds, filled with every payload: the
     * HTML has no tag but its own markup, no attribute that breaks out, and
     * a value that looks like a placeholder is not replaced again.
     */
    public function testInsightsEscapeEveryValue(): void
    {
        $formatter = new InsightFormatter(Translations::translator());
        foreach (Payloads::all() as $name => $payload) {
            $params = [
                'path' => Param::path($payload),
                'code' => Param::code($payload),
                'text' => Param::text($payload),
                'term' => Param::term($payload),
                'page' => Param::page($payload, $payload, 3),
                'untitled' => Param::page($payload, null),
                'language' => Param::language($payload),
                'channel' => Param::channel($payload),
                'browser' => Param::browser($payload),
                'device' => Param::device($payload),
                'list' => Param::list([Param::path($payload), Param::term($payload)]),
            ];
            $template = implode(' ', array_map(static fn (string $key): string => '%' . $key . '%', array_keys($params)));
            $insight = new Insight('test', Insight::SECTION_SOURCES, Insight::KIND_FIX, 1, Message::of($template, $params), Message::of($template, $params));

            $html = $formatter->format([$insight], 'en')[0]['html'];

            // Its own markup removed, nothing that a browser reads as a tag or an attribute is left.
            $text = (string) preg_replace('~</?(?:code|em|span)(?: (?:title|class)="[^"<>]*")?>~', '', $html);
            foreach (['<', '>', '"'] as $raw) {
                self::assertStringNotContainsString($raw, $text, $name . ': ' . $html);
            }
            if (str_contains($payload, '%count%')) {
                self::assertStringContainsString('%count%', $html, 'a value is never read as a placeholder');
            }
        }
    }

    /**
     * The scripts link a stored path only when it is a path of the site
     * (`window.raSitePath`); the same expression, run here.
     */
    public function testScriptsLinkOnlyPathsOfTheSite(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/skeleton/_style.html.twig');
        self::assertSame(1, preg_match('~window\.raSitePath = .*?\.test\(path\)~s', $template, $function));
        self::assertSame(1, preg_match('~&& /(.+)/\.test\(path\)~', $function[0], $expression));
        $isSitePath = static fn (string $path): bool => preg_match('/' . $expression[1] . '/', $path) === 1;

        foreach (['/', '/a', '/a/b?c', '/%3Cscript%3E', '/caf%C3%A9'] as $path) {
            self::assertTrue($isSitePath($path), $path);
        }

        foreach (['//evil.example', '/\evil.example', '/\\\\evil.example', 'javascript:alert(1)', 'JAVASCRIPT:alert(1)', 'data:text/html,x', 'https://evil.example', ' /a', "\t/a", '', 'evil.example', 'vbscript:x'] as $path) {
            self::assertFalse($isSitePath($path), $path);
        }

        foreach (Payloads::all() as $name => $payload) {
            if ($isSitePath($payload)) {
                self::assertStringStartsWith('/', $payload, $name);
                self::assertDoesNotMatchRegularExpression('~^/[/\\\\]~', $payload, $name);
            }
        }
    }

    /**
     * The templates never put text into the page as HTML.
     */
    public function testTemplatesWriteNoHtmlFromData(): void
    {
        $templates = glob(dirname(__DIR__, 2) . '/templates/*/*.html.twig');
        self::assertIsArray($templates);
        self::assertNotEmpty($templates);

        foreach ($templates as $file) {
            $source = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('~\.(?:innerHTML|outerHTML)\s*=|insertAdjacentHTML|document\.write|\beval\(|new Function\(|setTimeout\(\s*[\'"]~', $source, basename($file));
            // `|raw` only for the insights and their status, which InsightFormatter escapes.
            preg_match_all('~\{\{[^}]*\|raw[^}]*\}\}~', $source, $raws);
            foreach ($raws[0] as $raw) {
                self::assertContains($raw, ['{{ insight.html|raw }}', '{{ insightsStatus.html|raw }}'], basename($file));
            }
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function assertEscapedJson(JsonResponse $response, string $context): array
    {
        $body = (string) $response->getContent();
        foreach (['<', '>', '&', "'"] as $raw) {
            self::assertStringNotContainsString($raw, $body, $context . ': raw ' . $raw);
        }
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function contains(array $data, string $value): bool
    {
        return array_any($data, fn ($item): bool => $item === $value || (is_array($item) && self::contains($item, $value)));
    }
}
