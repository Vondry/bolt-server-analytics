<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Insight\Rule;

use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Collector\Channel;
use TomVondracek\RequestAnalytics\Insight\Insight;
use TomVondracek\RequestAnalytics\Insight\InsightContext;
use TomVondracek\RequestAnalytics\Insight\Rule\VisitDropRule;
use TomVondracek\RequestAnalytics\Tests\Support\FakePageCatalog;
use TomVondracek\RequestAnalytics\Tests\Support\Insights;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryInsightData;

final class VisitDropRuleTest extends TestCase
{
    public function testTheLossIsSplitByChannelAndLandingPage(): void
    {
        // 560 → 392 visits, 2 pages per visit in both windows.
        $data = self::visits(20, 14);
        self::source($data, Insights::BEFORE_FROM, Channel::ORGANIC_SEARCH, 'google', '/vstupenky', 300);
        self::source($data, Insights::BEFORE_FROM, Channel::ORGANIC_SEARCH, 'google', '/program', 100);
        self::source($data, Insights::BEFORE_FROM, Channel::DIRECT, '', '/', 160);
        self::source($data, Insights::RECENT_FROM, Channel::ORGANIC_SEARCH, 'google', '/vstupenky', 150);
        self::source($data, Insights::RECENT_FROM, Channel::ORGANIC_SEARCH, 'google', '/program', 80);
        self::source($data, Insights::RECENT_FROM, Channel::DIRECT, '', '/', 162);

        $insights = (new VisitDropRule())->evaluate(Insights::context($data, self::catalog()));

        self::assertSame(['visit-drop'], Insights::ids($insights));
        self::assertSame([Insight::SECTION_SOURCES, Insight::KIND_FIX, 168.0, true], [$insights[0]->section, $insights[0]->kind, $insights[0]->impact, $insights[0]->compared]);
        self::assertSame('Visits fell −30% (560 → 392). Organic search accounts for 100% of the loss, mostly Vstupenky (−150), Program (−20). → Check the search ranking of these pages, and whether their title or address changed.', Insights::text($insights[0]));
    }

    public function testFewerPagesPerVisitAreMentioned(): void
    {
        $data = self::visits(20, 14, 2.0, 1.5);
        self::source($data, Insights::BEFORE_FROM, Channel::SOCIAL, 'facebook', '/', 560);
        self::source($data, Insights::RECENT_FROM, Channel::SOCIAL, 'facebook', '/', 392);

        $insights = (new VisitDropRule())->evaluate(Insights::context($data));

        self::assertSame('Visits fell −30% (560 → 392). Social accounts for 100% of the loss, mostly Homepage (−168). Visits also open fewer pages per visit. → Probably a recurring post or campaign stopped: check what was published there before.', Insights::text($insights[0]));
    }

    public function testAReferralNamesTheSiteThatProbablyRemovedItsLink(): void
    {
        $data = self::visits(20, 14);
        self::source($data, Insights::BEFORE_FROM, Channel::REFERRAL, 'partner.cz', '/', 200);
        self::source($data, Insights::BEFORE_FROM, Channel::DIRECT, '', '/', 360);
        self::source($data, Insights::RECENT_FROM, Channel::REFERRAL, 'partner.cz', '/', 30);
        self::source($data, Insights::RECENT_FROM, Channel::DIRECT, '', '/', 362);

        $insights = (new VisitDropRule())->evaluate(Insights::context($data));

        self::assertStringEndsWith('→ Probably partner.cz removed its link: check the linking page.', Insights::text($insights[0]));
    }

    /**
     * Pages and sources with fewer visits than the threshold are not named.
     */
    public function testThePrivacyThreshold(): void
    {
        $data = self::visits(20, 14);
        self::source($data, Insights::BEFORE_FROM, Channel::REFERRAL, 'tiny.cz', '/secret', 4);
        self::source($data, Insights::BEFORE_FROM, Channel::REFERRAL, 'small.cz', '/', 4);
        self::source($data, Insights::BEFORE_FROM, Channel::DIRECT, '', '/', 552);
        self::source($data, Insights::RECENT_FROM, Channel::DIRECT, '', '/', 392);

        $insights = (new VisitDropRule())->evaluate(Insights::context($data, k: 5));

        self::assertSame('Visits fell −30% (560 → 392). Direct accounts for 95% of the loss, mostly Homepage (−160). → Check bookmarks and printed links (posters, QR codes) that lead to these pages.', Insights::text($insights[0]));
    }

    public function testNoInsightJustBelowEachThreshold(): void
    {
        $rule = new VisitDropRule();

        self::assertCount(1, $rule->evaluate(Insights::context(self::visits(20, 16))), '−20 %');
        self::assertSame([], $rule->evaluate(Insights::context(self::visits(100, 81))), '−19 %');
        self::assertSame([], $rule->evaluate(Insights::context(self::visits(10, 5))), '280 visits before');
        self::assertSame([], $rule->evaluate(Insights::context(self::visits(20, 20))), 'unchanged');
        self::assertSame([], $rule->evaluate(Insights::context(self::visits(20, 30))), 'a rise needs no action');
    }

    /**
     * 300 → 240 is −20 %, but chance could do that: 60 / √540 < 3.
     */
    public function testTheChangeMustBeReal(): void
    {
        self::assertFalse(InsightContext::isRealChange(300, 240));
        self::assertTrue(InsightContext::isRealChange(560, 392));
        self::assertFalse(InsightContext::isRealChange(10_000, 8_100), 'real, but only −19 %');
        self::assertFalse(InsightContext::isRealChange(0, 0));
    }

    public function testBothWindowsMustBeCovered(): void
    {
        $data = self::visits(20, 10);
        $data->firstDay = '2026-09-19';

        self::assertSame([], (new VisitDropRule())->evaluate(Insights::context($data)));
    }

    private static function visits(int $before, int $after, float $perVisitBefore = 2.0, float $perVisitAfter = 2.0): MemoryInsightData
    {
        return (new MemoryInsightData())
            ->everyDay(Insights::before(), (int) ($before * $perVisitBefore), $before)
            ->everyDay(Insights::recent(), (int) ($after * $perVisitAfter), $after);
    }

    private static function source(MemoryInsightData $data, string $day, string $channel, string $source, string $path, int $visits): void
    {
        $data->sources[] = [
            'day' => $day,
            'channel' => $channel,
            'source' => $source,
            'path' => $path,
            'visits' => $visits,
        ];
    }

    private static function catalog(): FakePageCatalog
    {
        return new FakePageCatalog('en', ['en'], [
            '/program' => 'Program',
            '/vstupenky' => 'Vstupenky',
        ]);
    }
}
