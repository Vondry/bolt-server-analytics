<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Report;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Report\SourcesReport;
use TomVondracek\RequestAnalytics\Tests\Support\FakePageCatalog;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;

#[Group('database')]
final class SourcesReportTest extends TestCase
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

    public function testChannelsAndSourcesWithTheirLandingPages(): void
    {
        $this->pipeline->sources('2026-11-10', '/', 30, 'organic_search', 'google');
        $this->pipeline->sources('2026-11-10', '/page/program', 12, 'organic_search', 'google');
        $this->pipeline->sources('2026-11-10', '/nl/page/program', 3, 'organic_search', 'google');
        $this->pipeline->sources('2026-11-11', '/', 20, 'direct');
        $this->pipeline->sources('2026-11-11', '/page/tickets', 8, 'social', 'facebook');
        // Out of the range.
        $this->pipeline->sources('2026-11-01', '/', 999, 'referral', 'example.org');

        $report = $this->report()
            ->sources(self::FROM, self::TO, 1);

        self::assertSame(73, $report['visits']);
        self::assertSame([
            [
                'channel' => 'organic_search',
                'visits' => 45,
                'previous' => 0,
            ],
            [
                'channel' => 'direct',
                'visits' => 20,
                'previous' => 0,
            ],
            [
                'channel' => 'social',
                'visits' => 8,
                'previous' => 0,
            ],
        ], $report['channels']);
        self::assertSame([
            'channel' => 'organic_search',
            'source' => 'google',
            'visits' => 45,
            'previous' => 0,
            'landing' => [
                [
                    'path' => '/',
                    'title' => 'Rockfest',
                    'visits' => 30,
                ],
                [
                    'path' => '/page/program',
                    'title' => 'Program',
                    'visits' => 15,
                ],
            ],
        ], $report['rows'][0], 'the languages of a landing page are one page');
        self::assertSame(['google', '', 'facebook'], array_column($report['rows'], 'source'));
        self::assertSame([0, 0], [$report['campaigns'], $report['aliases']]);
    }

    /**
     * A small referring site could be a private page, and so could a landing
     * page of a source: below the threshold they are "other"; the totals of
     * the channels stay exact.
     */
    public function testSmallSourcesAndLandingPagesAreOther(): void
    {
        $this->pipeline->sources('2026-11-10', '/', 9, 'referral', 'example.org');
        $this->pipeline->sources('2026-11-10', '/page/secret', 2, 'referral', 'example.org');
        $this->pipeline->sources('2026-11-10', '/', 2, 'referral', 'tiny-blog.example');
        $this->pipeline->sources('2026-11-10', '/', 1, 'referral', 'another.example');
        $this->pipeline->sources('2026-11-10', '/', 3, 'direct');

        $report = $this->report()
            ->sources(self::FROM, self::TO, 5);

        self::assertSame([[
            'channel' => 'referral',
            'visits' => 14,
            'previous' => 0,
        ], [
            'channel' => 'direct',
            'visits' => 3,
            'previous' => 0,
        ]], $report['channels']);
        self::assertSame([['referral', 'example.org', 11], ['direct', '', 3], ['referral', SourcesReport::OTHER, 3]], array_map(static fn (array $row): array => [$row['channel'], $row['source'], $row['visits']], $report['rows']));
        self::assertSame(['/'], array_column($report['rows'][0]['landing'], 'path'), '/page/secret had 2 visits only');
        self::assertSame([], $report['rows'][1]['landing'], 'not even the landing page of 3 direct visits');
    }

    /**
     * Sources and channels carry their visits of as many days before; an
     * "other" source the sum of those of the sources it holds.
     */
    public function testSourcesCarryTheirVisitsOfThePeriodBefore(): void
    {
        $this->pipeline->sources('2026-11-10', '/', 20, 'organic_search', 'google');
        $this->pipeline->sources('2026-11-05', '/', 10, 'organic_search', 'google');
        $this->pipeline->sources('2026-11-10', '/', 2, 'referral', 'tiny.example');
        $this->pipeline->sources('2026-11-10', '/', 1, 'referral', 'small.example');
        $this->pipeline->sources('2026-11-06', '/', 4, 'referral', 'tiny.example');
        $this->pipeline->sources('2026-11-06', '/', 6, 'referral', 'small.example');

        $report = $this->report()
            ->sources(self::FROM, self::TO, 5);

        self::assertSame([['organic_search', 20, 10], ['referral', 3, 10]], array_map(static fn (array $channel): array => [$channel['channel'], $channel['visits'], $channel['previous']], $report['channels']));
        self::assertSame([['google', 20, 10], [SourcesReport::OTHER, 3, 10]], array_map(static fn (array $row): array => [$row['source'], $row['visits'], $row['previous']], $report['rows']));
    }

    public function testComparesWithTheGivenDaysOrNone(): void
    {
        $this->pipeline->sources('2026-11-10', '/', 20, 'organic_search', 'google');
        $this->pipeline->sources('2025-11-10', '/', 8, 'organic_search', 'google');

        $lastYear = $this->report()
            ->sources(self::FROM, self::TO, 1, compareFrom: '2025-11-10', compareTo: '2025-11-14');
        $none = $this->report()
            ->sources(self::FROM, self::TO, 1, compare: false);

        self::assertSame([8, 8], [$lastYear['channels'][0]['previous'], $lastYear['rows'][0]['previous']]);
        self::assertSame([null, null, null], [$none['previousFrom'], $none['channels'][0]['previous'], $none['rows'][0]['previous']]);
    }

    public function testFilterByChannelSearchAndPages(): void
    {
        for ($index = 1; $index <= 25; ++$index) {
            $this->pipeline->sources('2026-11-10', '/', $index, 'referral', sprintf('site%02d.example', $index));
        }
        $this->pipeline->sources('2026-11-10', '/', 50, 'organic_search', 'google');

        $referrals = $this->report()
            ->sources(self::FROM, self::TO, 1, 'referral');
        $second = $this->report()
            ->sources(self::FROM, self::TO, 1, 'referral', '', 2);
        $search = $this->report()
            ->sources(self::FROM, self::TO, 1, null, 'site1');

        self::assertSame([25, 2, 'site25.example'], [$referrals['total'], $referrals['pages'], $referrals['rows'][0]['source']]);
        self::assertSame(50, $referrals['max'], 'bars stay comparable across channels');
        self::assertCount(5, $second['rows']);
        self::assertSame(['site19.example', 'site18.example', 'site17.example', 'site16.example', 'site15.example', 'site14.example', 'site13.example', 'site12.example', 'site11.example', 'site10.example'], array_column($search['rows'], 'source'));
        self::assertCount(2, $search['channels'], 'the channels are of all visits');
    }

    public function testCampaignsWithTheirVisitsPerDay(): void
    {
        $this->pipeline->sources('2026-11-10', '/', 10, 'email', 'newsletter', 'autumn-sale');
        $this->pipeline->sources('2026-11-11', '/page/sale', 6, 'email', 'newsletter', 'autumn-sale');
        $this->pipeline->sources('2026-11-11', '/page/sale', 4, 'social', 'facebook', 'autumn-sale');
        $this->pipeline->sources('2026-11-13', '/', 2, 'campaign', 'poster', 'concert');
        $this->pipeline->sources('2026-11-12', '/', 40, 'organic_search', 'google');

        $report = $this->report()
            ->campaigns(self::FROM, self::TO, 3);

        self::assertCount(7, $report['days']);
        self::assertSame(['autumn-sale', 'concert'], array_column($report['rows'], 'campaign'));
        self::assertSame([
            'campaign' => 'autumn-sale',
            'visits' => 20,
            'first' => '2026-11-10',
            'last' => '2026-11-11',
            'series' => [0, 10, 10, 0, 0, 0, 0],
            'origins' => [
                [
                    'channel' => 'email',
                    'source' => 'newsletter',
                    'visits' => 16,
                ],
                [
                    'channel' => 'social',
                    'source' => 'facebook',
                    'visits' => 4,
                ],
            ],
            // Equal visits: by path.
            'landing' => [
                [
                    'path' => '/',
                    'title' => 'Rockfest',
                    'visits' => 10,
                ],
                [
                    'path' => '/page/sale',
                    'title' => null,
                    'visits' => 10,
                ],
            ],
        ], $report['rows'][0]);
        self::assertSame(2, $report['rows'][1]['visits'], 'a campaign total is exact');
        self::assertSame([[
            'channel' => SourcesReport::OTHER,
            'source' => '',
            'visits' => 2,
        ]], $report['rows'][1]['origins']);
        self::assertSame(2, $this->report()->sources(self::FROM, self::TO, 1)['campaigns']);
    }

    public function testAliasesWithHowVisitorsGotThere(): void
    {
        $this->pipeline->aliases('2026-11-10', 'rockfestkurim.cz', '/', 20);
        $this->pipeline->aliases('2026-11-10', 'rockfestkurim.cz', '/page/program', 5, 'social', 'facebook');
        $this->pipeline->aliases('2026-11-11', 'poster-a2', '/', 7);

        $report = $this->report()
            ->aliases(self::FROM, self::TO, 1, ['rockfestkurim.cz', 'poster-a2', 'old-domain.cz']);

        self::assertSame([['rockfestkurim.cz', 25], ['poster-a2', 7], ['old-domain.cz', 0]], array_map(static fn (array $row): array => [$row['via'], $row['visits']], $report['rows']));
        self::assertSame([
            [
                'channel' => 'direct',
                'source' => '',
                'visits' => 20,
            ],
            [
                'channel' => 'social',
                'source' => 'facebook',
                'visits' => 5,
            ],
        ], $report['rows'][0]['origins']);
        self::assertSame([[], []], [$report['rows'][2]['origins'], $report['rows'][2]['landing']], 'configured, not used yet');
        self::assertSame(2, $this->report()->sources(self::FROM, self::TO, 1)['aliases']);
    }

    public function testInvalidRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"from" must be at most 1199 days before "to".');

        $this->report()
            ->campaigns('2026-11-15', '2026-11-09', 1);
    }

    private function report(): SourcesReport
    {
        return $this->pipeline->sourcesReport(new FakePageCatalog('en', ['en', 'nl'], [
            '/' => 'Rockfest',
            '/page/program' => 'Program',
        ]));
    }
}
