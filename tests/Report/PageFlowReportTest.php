<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Report;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Report\PageFlowReport;
use TomVondracek\RequestAnalytics\Tests\Support\FakePageCatalog;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;

/**
 * "Today" is 2026-11-13.
 */
#[Group('database')]
final class PageFlowReportTest extends TestCase
{
    private Pipeline $pipeline;

    protected function setUp(): void
    {
        $this->pipeline = new Pipeline();
    }

    protected function tearDown(): void
    {
        $this->pipeline->remove();
    }

    /**
     * A page in all its languages: the moves to and from `/page/about` and
     * `/nl/page/about` are one page, and so are the pages on the other end.
     */
    public function testWhereVisitorsCameFromAndWentNext(): void
    {
        $this->pipeline->pages('2026-11-10', '/page/about', 30);
        $this->pipeline->pages('2026-11-10', '/page/about', 8, entry: true);
        $this->pipeline->pages('2026-11-10', '/nl/page/about', 10);
        $this->pipeline->transitions('2026-11-10', '/', '/page/about', 12);
        $this->pipeline->transitions('2026-11-10', '/nl', '/nl/page/about', 6);
        $this->pipeline->transitions('2026-11-10', '/blog', '/page/about', 5);
        $this->pipeline->transitions('2026-11-10', '/page/about', '/contact', 9);
        $this->pipeline->transitions('2026-11-10', '/nl/page/about', '/nl/contact', 2);
        // A language switch; a reload is not counted.
        $this->pipeline->transitions('2026-11-10', '/page/about', '/nl/page/about', 3);
        $this->pipeline->transitions('2026-11-10', '/page/about', '/page/about', 4);
        // Out of the range, and moves between other pages.
        $this->pipeline->transitions('2026-11-01', '/', '/page/about', 50);
        $this->pipeline->transitions('2026-11-10', '/', '/contact', 50);

        $flow = $this->report()
            ->flow('/page/about', '2026-11-09', '2026-11-15');

        self::assertSame(['/page/about', 'About us', '2026-11-09', '2026-11-13'], [$flow['path'], $flow['title'], $flow['from'], $flow['to']]);
        self::assertSame([48, 8, 3], [$flow['views'], $flow['entries'], $flow['languageSwitches']]);
        self::assertSame([
            [
                'key' => '/',
                'title' => 'Home',
                'hits' => 18,
            ],
            [
                'key' => '/blog',
                'title' => null,
                'hits' => 5,
            ],
        ], $flow['cameFrom']);
        self::assertSame([['/contact', 11]], array_map(static fn (array $step): array => [$step['key'], $step['hits']], $flow['wentTo']));
        self::assertSame([0, 0, 11], [$flow['cameFromOther'], $flow['wentToOther'], $flow['wentToTotal']]);
    }

    /**
     * A rare way through the site could trace one visitor: counted, not named.
     */
    public function testRareMovesAreOnlyCounted(): void
    {
        $this->pipeline->transitions('2026-11-10', '/', '/page/about', 9);
        $this->pipeline->transitions('2026-11-10', '/secret-path', '/page/about', 2);
        for ($index = 1; $index <= PageFlowReport::MAX_STEPS + 2; ++$index) {
            $this->pipeline->transitions('2026-11-10', '/page/about', '/next/' . $index, 10 + $index);
        }

        $flow = $this->report()
            ->flow('/page/about', '2026-11-09', '2026-11-15', 5);

        self::assertSame(['/'], array_column($flow['cameFrom'], 'key'));
        self::assertSame(2, $flow['cameFromOther']);
        self::assertCount(PageFlowReport::MAX_STEPS, $flow['wentTo']);
        self::assertSame('/next/10', $flow['wentTo'][0]['key']);
        self::assertSame(11 + 12, $flow['wentToOther']);
    }

    public function testHomepageInAnotherLanguage(): void
    {
        $this->pipeline->transitions('2026-11-10', '/nl', '/page/about', 4);

        $flow = $this->report()
            ->flow('/', '2026-11-09', '2026-11-15');

        self::assertSame([['/page/about', 4]], array_map(static fn (array $step): array => [$step['key'], $step['hits']], $flow['wentTo']));
    }

    public function testInvalidPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"path" must be a path of the site such as /page/about, "page" given.');

        $this->report()
            ->flow('page', '2026-11-09', '2026-11-15');
    }

    private function report(): PageFlowReport
    {
        return $this->pipeline->pageFlowReport(new FakePageCatalog('en', ['en', 'nl'], [
            '/' => 'Home',
            '/page/about' => 'About us',
        ]));
    }
}
