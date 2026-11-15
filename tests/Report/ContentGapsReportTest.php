<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Report;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Report\ContentGapsReport;
use TomVondracek\RequestAnalytics\Tests\Support\FakePageCatalog;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;

/**
 * "Today" is 2026-11-13; the range 2026-11-09 … 2026-11-15 is compared with
 * 2026-11-04 … 2026-11-08.
 */
#[Group('database')]
final class ContentGapsReportTest extends TestCase
{
    private const FROM = '2026-11-09';
    private const TO = '2026-11-15';

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
     * A link of the site itself can be fixed right away: those come first,
     * whatever their count, with the pages the links are on.
     */
    public function testBrokenLinksOfTheSiteFirstWithWhereTheLinksAre(): void
    {
        $this->pipeline->notFound('2026-11-10', '/old-program', 2, '/page/about');
        $this->pipeline->notFound('2026-11-10', '/old-program', 1, '/');
        $this->pipeline->notFound('2026-11-11', '/tickets-2025', 30, 'example.org');
        $this->pipeline->notFound('2026-11-11', '/tickets-2025', 2, 'tiny.example');
        $this->pipeline->notFound('2026-11-11', '/tickets-2025', 8);
        $this->pipeline->notFound('2026-11-05', '/tickets-2025', 20, 'example.org');

        $report = $this->report()
            ->brokenLinks(self::FROM, self::TO, 5);

        self::assertSame([43, 20, 2, 0], [$report['requests'], $report['previousRequests'], $report['total'], $report['hidden']]);
        self::assertSame([
            'path' => '/old-program',
            'hits' => 3,
            'previous' => 0,
            'internal' => [
                [
                    'page' => '/page/about',
                    'title' => 'About us',
                    'hits' => 2,
                ],
                [
                    'page' => '/',
                    'title' => null,
                    'hits' => 1,
                ],
            ],
            'external' => [],
            'typed' => 0,
        ], $report['rows'][0]);
        self::assertSame([
            'path' => '/tickets-2025',
            'hits' => 40,
            'previous' => 20,
            'internal' => [],
            'external' => [
                [
                    'host' => 'example.org',
                    'hits' => 30,
                ],
                [
                    'host' => ContentGapsReport::OTHER,
                    'hits' => 2,
                ],
            ],
            'typed' => 8,
        ], $report['rows'][1]);
    }

    /**
     * A missing page known only from outside or typed in, with few requests,
     * could be anything a visitor typed: only counted.
     */
    public function testSmallBrokenLinksFromOutsideAreOnlyCounted(): void
    {
        $this->pipeline->notFound('2026-11-10', '/jan.novak', 1);
        $this->pipeline->notFound('2026-11-10', '/typo', 2, 'example.org');
        $this->pipeline->notFound('2026-11-10', '/linked-here', 1, '/page/about');

        $report = $this->report()
            ->brokenLinks(self::FROM, self::TO, 5);

        self::assertSame(['/linked-here'], array_column($report['rows'], 'path'));
        self::assertSame([2, 4], [$report['hidden'], $report['requests']]);
    }

    public function testSearchTermsMostSearchedFirst(): void
    {
        $this->pipeline->search('2026-11-10', 'vstupenky', 12);
        $this->pipeline->search('2026-11-11', 'program', 7);
        $this->pipeline->search('2026-11-11', 'call (redacted)', 6);
        $this->pipeline->search('2026-11-11', 'jan novak', 1);
        $this->pipeline->search('2026-11-05', 'vstupenky', 4);

        $report = $this->report()
            ->searches(self::FROM, self::TO, 5);

        self::assertSame([26, 3, 1], [$report['searches'], $report['total'], $report['hidden']]);
        self::assertSame([
            [
                'term' => 'vstupenky',
                'searches' => 12,
                'previous' => 4,
                'redacted' => false,
            ],
            [
                'term' => 'program',
                'searches' => 7,
                'previous' => 0,
                'redacted' => false,
            ],
            [
                'term' => 'call (redacted)',
                'searches' => 6,
                'previous' => 0,
                'redacted' => true,
            ],
        ], $report['rows']);
    }

    public function testWithoutComparison(): void
    {
        $this->pipeline->search('2026-11-10', 'program', 9);
        $this->pipeline->notFound('2026-11-10', '/old', 9);

        $searches = $this->report()
            ->searches(self::FROM, self::TO, 1, compare: false);
        $broken = $this->report()
            ->brokenLinks(self::FROM, self::TO, 1, compare: false);

        self::assertSame([null, null], [$searches['previousFrom'], $searches['rows'][0]['previous']]);
        self::assertSame([null, null, null], [$broken['previousFrom'], $broken['previousRequests'], $broken['rows'][0]['previous']]);
    }

    public function testPagesOfTheList(): void
    {
        for ($index = 1; $index <= 25; ++$index) {
            $this->pipeline->search('2026-11-10', sprintf('term %02d', $index), $index);
        }

        $second = $this->report()
            ->searches(self::FROM, self::TO, 1, 2);

        self::assertSame([2, 2, 25], [$second['page'], $second['pages'], $second['total']]);
        self::assertSame(['term 05', 'term 04', 'term 03', 'term 02', 'term 01'], array_column($second['rows'], 'term'));
    }

    private function report(): ContentGapsReport
    {
        return $this->pipeline->contentGapsReport(new FakePageCatalog('en', ['en'], [
            '/page/about' => 'About us',
        ]));
    }
}
