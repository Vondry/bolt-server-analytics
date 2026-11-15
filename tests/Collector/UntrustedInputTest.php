<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Collector;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Collector\Normalizer;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;
use TomVondracek\RequestAnalytics\Tests\Support\Requests;

/**
 * What an attacker can put into a request (the URL, `Referer`, `utm_*`, a
 * search term) through the whole pipeline: classifier, spool, rollup. Every
 * value that arrives in any table is safe to show: no markup, no quotes, no
 * invisible or bidi characters, no link to another site.
 */
#[Group('database')]
final class UntrustedInputTest extends TestCase
{
    private const SITE = 'https://example.com';

    private Pipeline $pipeline;

    protected function setUp(): void
    {
        $this->pipeline = new Pipeline();
        $this->pipeline->project->configure([
            'search_params' => ['searchTerm'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->pipeline->remove();
    }

    public function testNothingUnsafeArrivesInAnyTable(): void
    {
        $this->collect([
            [Requests::browser(self::SITE . '/%5Cevil.com'), 404],
            [Requests::browser(self::SITE . '/%5C%5Cevil.com/x'), 404],
            [Requests::browser(self::SITE . '/a/%3Cscript%3Ealert(1)%3C/script%3E'), 200],
            [Requests::browser(self::SITE . '/a%22onmouseover=%22alert(1)'), 200],
            [Requests::browser(self::SITE . '/invoice%E2%80%AEfdp.exe'), 200],
            // A link planted through a forged Referer on a missing page.
            [Requests::browser(self::SITE . '/missing', [
                'Referer' => self::SITE . '/%5Cplanted.example',
                'Sec-Fetch-Site' => 'same-origin',
            ]), 404],
            [Requests::browser(self::SITE . '/next', [
                'Referer' => self::SITE . '/%3Cimg%20src=x%20onerror=alert(1)%3E',
                'Sec-Fetch-Site' => 'same-origin',
            ]), 200],
            [Requests::browser(self::SITE . '/?utm_source=%3Cimg%20src=x%3E&utm_medium=email&utm_campaign=%E2%80%AEsale%22%7B%7B7*7%7D%7D'), 200],
            [Requests::browser(self::SITE . '/', [
                'Referer' => 'https://evil.example%3Cb%3E/page',
                'Sec-Fetch-Site' => 'cross-site',
            ]), 200],
            [Requests::browser(self::SITE . '/search?searchTerm=%3Cb%3Ebold%3C/b%3E%20%22x%22%20%E2%80%8Bhidden'), 200],
        ]);

        $connection = $this->pipeline->database->connection;
        $tables = $this->pipeline->database->tables;
        $checked = 0;
        foreach ([$tables->pages(), $tables->notFound(), $tables->transitions(), $tables->sources(), $tables->search(), $tables->clients(), $tables->locales()] as $table) {
            foreach ($connection->fetchAllAssociative(sprintf('SELECT * FROM %s', $table)) as $row) {
                foreach ($row as $column => $value) {
                    if (! is_string($value) || $column === 'day') {
                        continue;
                    }

                    $safe = in_array($column, ['path', 'from_path', 'to_path'], true)
                        ? Normalizer::isPath($value)
                        : (str_starts_with($value, '/') ? Normalizer::isPath($value) : Normalizer::isLabel($value));
                    self::assertTrue($safe, sprintf('%s.%s holds %s', $table, $column, json_encode($value)));
                    self::assertDoesNotMatchRegularExpression('~[<>"`\\\\{}]|^//|\x{202E}|\x{200B}~u', $value, sprintf('%s.%s', $table, $column));
                    ++$checked;
                }
            }
        }

        self::assertGreaterThan(20, $checked);
        self::assertSame(['/evil.com', '/evil.com/x', '/missing'], $this->column($tables->notFound(), 'path'), 'a backslash is a slash, as browsers read it');
        self::assertContains('/planted.example', $this->column($tables->notFound(), 'ref'), 'the planted link points into the site');
        self::assertContains('/a/%3Cscript%3Ealert(1)%3C/script%3E', $this->column($tables->pages(), 'path'), 'markup stays encoded');
        self::assertContains('/invoice%E2%80%AEfdp.exe', $this->column($tables->pages(), 'path'), 'the bidi override shows as what it is');
        self::assertContains('/%3Cimg%20src=x%20onerror=alert(1)%3E', $this->column($tables->transitions(), 'from_path'));
        self::assertSame(['img src x'], $this->column($tables->sources(), 'source', "channel = 'email'"));
        self::assertSame(['sale 7*7'], $this->column($tables->sources(), 'campaign', "channel = 'email'"));
        self::assertSame(['b bold /b x hidden'], $this->column($tables->search(), 'term'));
        self::assertSame([], $this->column($tables->sources(), 'source', "source LIKE '%evil%'"), 'a Referer whose host is no host name names no source');
    }

    /**
     * @param list<array{Request, int}> $requests
     */
    private function collect(array $requests): void
    {
        $classifier = $this->pipeline->project->classifier();
        $writer = $this->pipeline->spoolWriter();
        foreach ($requests as [$request, $status]) {
            $hit = $classifier->classify($request, $status);
            self::assertInstanceOf(Hit::class, $hit, $request->getUri());
            $writer->write($hit);
        }

        $this->pipeline->rollupService()
            ->rollup(PHP_INT_MAX);
    }

    /**
     * @return list<string>
     */
    private function column(string $table, string $column, string $where = '1 = 1'): array
    {
        $values = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $this->pipeline->database->connection->fetchFirstColumn(sprintf('SELECT DISTINCT %s FROM %s WHERE %s ORDER BY 1', $column, $table, $where)));

        return array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
    }
}
