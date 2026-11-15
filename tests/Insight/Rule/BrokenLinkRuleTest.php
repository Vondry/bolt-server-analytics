<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Insight\Rule;

use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Insight\Insight;
use TomVondracek\RequestAnalytics\Insight\Rule\BrokenLinkRule;
use TomVondracek\RequestAnalytics\Tests\Support\FakePageCatalog;
use TomVondracek\RequestAnalytics\Tests\Support\Insights;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryInsightData;

final class BrokenLinkRuleTest extends TestCase
{
    /**
     * A link in the site's own markup counts from the first request.
     */
    public function testALinkOfTheSiteFiresWhateverItsCountWithThePageProbablyMeant(): void
    {
        $data = self::broken([['/stary-program', '/', 1]]);

        $insights = (new BrokenLinkRule())->evaluate(Insights::context($data, self::catalog()));

        self::assertSame(['broken-link:/stary-program'], Insights::ids($insights));
        self::assertSame([Insight::SECTION_BROKEN_LINKS, Insight::KIND_FIX, 1.0, 'ra-broken-links'], [$insights[0]->section, $insights[0]->kind, $insights[0]->impact, $insights[0]->target()]);
        self::assertSame('Homepage links to /stary-program, which does not exist (requests: 1). → Probably Program was meant: fix the link on that page.', Insights::text($insights[0]));
    }

    public function testRequestsFromOtherSitesFireFrom10(): void
    {
        $data = self::broken([['/vstupenky-2025', 'facebook.com', 52]]);
        $insights = (new BrokenLinkRule())->evaluate(Insights::context($data, self::catalog()));

        self::assertSame('/vstupenky-2025 does not exist, and gets 52 requests from facebook.com. → Redirect it to Vstupenky.', Insights::text($insights[0]));
        self::assertSame([], (new BrokenLinkRule())->evaluate(Insights::context(self::broken([['/old', 'facebook.com', 9]]))), 'just below 10');
        self::assertCount(1, (new BrokenLinkRule())->evaluate(Insights::context(self::broken([['/old', 'facebook.com', 10]]))));
    }

    public function testTypedAddressesDoNotFire(): void
    {
        self::assertSame([], (new BrokenLinkRule())->evaluate(Insights::context(self::broken([['/wp-admin', '', 500]]))));
    }

    /**
     * A site with fewer requests than the privacy threshold is not named, and
     * the threshold raises the minimum.
     */
    public function testThePrivacyThreshold(): void
    {
        $data = self::broken([['/old', 'a.example', 4], ['/old', 'b.example', 4], ['/old', 'c.example', 4]]);

        $insights = (new BrokenLinkRule())->evaluate(Insights::context($data, k: 5));
        self::assertSame('/old does not exist, and gets 12 requests from other sites. → Redirect it to the page that replaced it.', Insights::text($insights[0]));

        self::assertSame([], (new BrokenLinkRule())->evaluate(Insights::context($data, k: 13)));
    }

    public function testNoSuggestionWhenSeveralPagesMatchAsWell(): void
    {
        $catalog = new FakePageCatalog('en', ['en'], [
            '/vstupenky-online' => 'Online',
            '/vstupenky-na-misto' => 'Na místě',
        ]);

        $insights = (new BrokenLinkRule())->evaluate(Insights::context(self::broken([['/vstupenky', '/', 3]]), $catalog));

        self::assertSame('Homepage links to /vstupenky, which does not exist (requests: 3). → Fix or remove the link on that page.', Insights::text($insights[0]));
    }

    public function testTheSuggestionKeepsTheLanguageOfTheMissingPage(): void
    {
        $catalog = new FakePageCatalog('cs', ['cs', 'de'], [
            '/program' => 'Program',
        ]);

        $insights = (new BrokenLinkRule())->evaluate(Insights::context(self::broken([['/de/progam', '/de', 2]]), $catalog));

        self::assertSame('Homepage links to /de/progam, which does not exist (requests: 2). → Probably Program was meant: fix the link on that page.', Insights::text($insights[0]));
        self::assertSame('/de/program', $insights[0]->action?->params['target']->value);
    }

    public function testTheThreeLargestThenHowManyMore(): void
    {
        $data = self::broken([['/a', '/', 5], ['/b', '/', 50], ['/c', '/', 20], ['/d', '/', 30], ['/e', 'x.example', 10]]);

        $insights = (new BrokenLinkRule())->evaluate(Insights::context($data));

        self::assertSame(['broken-link:/b', 'broken-link:/d', 'broken-link:/c'], Insights::ids($insights));
        self::assertSame([0, 0, 2], array_map(static fn (Insight $insight): int => $insight->more, $insights));
    }

    public function testOnlyTheLast28Days(): void
    {
        $data = self::broken([['/old', '/', 1]], '2026-10-15');

        self::assertSame([], (new BrokenLinkRule())->evaluate(Insights::context($data)));
    }

    /**
     * @param list<array{string, string, int}> $rows path, ref, hits
     */
    private static function broken(array $rows, string $day = '2026-11-01'): MemoryInsightData
    {
        $data = new MemoryInsightData();
        foreach ($rows as [$path, $ref, $hits]) {
            $data->notFound[] = [
                'day' => $day,
                'path' => $path,
                'ref' => $ref,
                'hits' => $hits,
            ];
        }

        return $data;
    }

    private static function catalog(): FakePageCatalog
    {
        return new FakePageCatalog('en', ['en'], [
            '/program' => 'Program',
            '/vstupenky' => 'Vstupenky',
            '/kontakt' => 'Kontakt',
        ]);
    }
}
