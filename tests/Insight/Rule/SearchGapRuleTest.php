<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Insight\Rule;

use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Insight\Insight;
use TomVondracek\RequestAnalytics\Insight\InsightContext;
use TomVondracek\RequestAnalytics\Insight\Rule\SearchGapRule;
use TomVondracek\RequestAnalytics\Tests\Support\FakePageCatalog;
use TomVondracek\RequestAnalytics\Tests\Support\Insights;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryInsightData;

final class SearchGapRuleTest extends TestCase
{
    public function testATermWithAPageIsHardToFind(): void
    {
        $insights = (new SearchGapRule())->evaluate(Insights::context(self::searches(['vstupenky' => 120]), self::catalog()));

        self::assertSame(['search-gap:vstupenky'], Insights::ids($insights));
        self::assertSame([Insight::SECTION_SEARCH, Insight::KIND_FIX, InsightContext::STRUCTURAL_DAYS], [$insights[0]->section, $insights[0]->kind, $insights[0]->days]);
        self::assertSame('120 searches for vstupenky, and Vstupenky 2026 exists: it is probably hard to find. → Link it from the menu or the homepage.', Insights::text($insights[0]));
    }

    public function testATermWithoutAPageAsksForOne(): void
    {
        $insights = (new SearchGapRule())->evaluate(Insights::context(self::searches(['parkoviště praha' => 45]), self::catalog()));

        self::assertSame(Insight::KIND_GROW, $insights[0]->kind);
        self::assertSame('45 searches for parkoviště praha, and no page is about it. → Write one, or add the word to a page that answers it.', Insights::text($insights[0]));
    }

    /**
     * Every word, lowercase and without diacritics, in the title or the
     * address; the shortest address among several.
     */
    public function testWordsMatchWithoutCaseAndDiacritics(): void
    {
        $insights = (new SearchGapRule())->evaluate(Insights::context(self::searches(['Parkovani U AREÁLU' => 30]), self::catalog()));

        self::assertSame(Insight::KIND_FIX, $insights[0]->kind);
        self::assertSame('/info/parkovani', $insights[0]->message->params['page']->value);
    }

    public function testNoInsightJustBelowTheMinimumOrThePrivacyThreshold(): void
    {
        self::assertSame([], (new SearchGapRule())->evaluate(Insights::context(self::searches(['vstupenky' => 19]))));
        self::assertCount(1, (new SearchGapRule())->evaluate(Insights::context(self::searches(['vstupenky' => 20]))));
        self::assertSame([], (new SearchGapRule())->evaluate(Insights::context(self::searches(['vstupenky' => 49]), k: 50)));
    }

    public function testRedactedTermsAreNeverShown(): void
    {
        self::assertSame([], (new SearchGapRule())->evaluate(Insights::context(self::searches(['(redacted)' => 300, 'call (redacted)' => 80]))));
    }

    public function testTheThreeMostSearchedThenHowManyMore(): void
    {
        $insights = (new SearchGapRule())->evaluate(Insights::context(self::searches(['a1' => 20, 'b2' => 90, 'c3' => 40, 'd4' => 60, 'e5' => 25])));

        self::assertSame(['search-gap:b2', 'search-gap:d4', 'search-gap:c3'], Insights::ids($insights));
        self::assertSame([0, 0, 2], array_map(static fn (Insight $insight): int => $insight->more, $insights));
    }

    public function testNothingUntilTheStatisticsCover90Days(): void
    {
        $data = self::searches(['vstupenky' => 120]);
        $data->firstDay = '2026-08-16';

        self::assertSame([], (new SearchGapRule())->evaluate(Insights::context($data)));
    }

    /**
     * @param array<string, int> $terms
     */
    private static function searches(array $terms): MemoryInsightData
    {
        $data = new MemoryInsightData();
        foreach ($terms as $term => $hits) {
            $data->searches[] = [
                'day' => '2026-09-01',
                'term' => (string) $term,
                'hits' => $hits,
            ];
        }

        return $data;
    }

    private static function catalog(): FakePageCatalog
    {
        return new FakePageCatalog('en', ['en'], [
            '/vstupenky' => 'Vstupenky 2026',
            '/info/parkovani' => 'Parkování u areálu',
            '/info/parkovani-pro-autobusy-u-arealu' => 'Parkování pro autobusy',
        ]);
    }
}
