<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Insight\Rule;

use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Insight\Insight;
use TomVondracek\RequestAnalytics\Insight\InsightContext;
use TomVondracek\RequestAnalytics\Insight\Rule\MissingLanguageRule;
use TomVondracek\RequestAnalytics\Insight\Rule\UnreachedTranslationRule;
use TomVondracek\RequestAnalytics\Insight\Rule\UnusedTranslationRule;
use TomVondracek\RequestAnalytics\Tests\Support\FakePageCatalog;
use TomVondracek\RequestAnalytics\Tests\Support\Insights;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryInsightData;

/**
 * Rules 4, 5 and 6: a language visits want, a translation that does not
 * reach them, and one nobody uses.
 */
final class LanguageRulesTest extends TestCase
{
    public function testAMissingLanguageWhoseVisitsBrowseLess(): void
    {
        // English: 300 of 2 300 visits (13 %), 1.3 pages per visit against 2.5.
        $data = self::languages([
            'cs' => [5000, 2000],
            'en' => [400, 300],
        ]);
        self::page($data, '/', 3000, 1200);
        self::page($data, '/program', 1500, 600);
        self::page($data, '/vstupenky', 900, 300);
        self::page($data, '/kontakt', 200, 200);

        $insights = (new MissingLanguageRule())->evaluate(Insights::context($data, self::czech()));

        self::assertSame(['missing-language:en'], Insights::ids($insights));
        self::assertSame([Insight::SECTION_LANGUAGES, Insight::KIND_GROW, 300.0, InsightContext::STRUCTURAL_DAYS], [$insights[0]->section, $insights[0]->kind, $insights[0]->impact, $insights[0]->days]);
        self::assertSame('300 visits (13%) come from browsers asking for English first. The site has no pages in it, and these visits open 1.3 pages per visit against 2.5 for the others. → Translate the pages most visits start on first: Homepage, Program, Vstupenky (91% of visits start there).', Insights::text($insights[0]));
    }

    /**
     * Many Czechs browse with English browsers: when those visits open as
     * many pages as the others, they read Czech.
     */
    public function testTheSafeguardKeepsQuietWhenTheseVisitsBrowseLikeTheOthers(): void
    {
        $rule = new MissingLanguageRule();

        // 2.33 pages per visit against 2.5: 93 %.
        self::assertSame([], $rule->evaluate(Insights::context(self::languages(['cs' => [5000, 2000], 'en' => [700, 300]]), self::czech())));
        // 70 % exactly still fires: 1.75 against 2.5.
        self::assertCount(1, $rule->evaluate(Insights::context(self::languages(['cs' => [5000, 2000], 'en' => [525, 300]]), self::czech())));
        // 71 %.
        self::assertSame([], $rule->evaluate(Insights::context(self::languages(['cs' => [5000, 2000], 'en' => [533, 300]]), self::czech())));
    }

    public function testAMissingLanguageNeedsItsShareAndItsVisits(): void
    {
        $rule = new MissingLanguageRule();

        self::assertSame([], $rule->evaluate(Insights::context(self::languages(['cs' => [5000, 2000], 'en' => [200, 200]]), self::czech())), '9 %');
        self::assertSame([], $rule->evaluate(Insights::context(self::languages(['cs' => [1000, 400], 'en' => [99, 99]]), self::czech())), '99 visits');
        self::assertSame([], $rule->evaluate(Insights::context(self::languages(['cs' => [1000, 400], 'en' => [100, 100]]), self::czech(), k: 101)), 'below the privacy threshold');
        self::assertSame([], $rule->evaluate(Insights::context(self::languages(['cs' => [5000, 2000], 'und' => [400, 300]]), self::czech())), 'an unknown language');
        self::assertSame([], $rule->evaluate(Insights::context(self::languages(['cs' => [5000, 2000], 'en' => [400, 300]]), new FakePageCatalog('cs', ['cs', 'en']))), 'the site has it');
    }

    public function testATranslationVisitsDoNotFindTheSwitcherOf(): void
    {
        // German: 300 of 1 800 visits (17 %), 2 % of the page views.
        $data = self::languages(['en' => [3000, 1500], 'de' => [600, 300]]);
        self::page($data, '/program', 4000, 1500);
        self::page($data, '/de/program', 100, 50);
        self::move($data, '/program', '/de/program', 10);

        $insights = (new UnreachedTranslationRule())->evaluate(Insights::context($data, self::english()));

        self::assertSame(['unreached-translation:de'], Insights::ids($insights));
        self::assertSame(Insight::KIND_FIX, $insights[0]->kind);
        self::assertSame('17% of visits prefer German, but only 2% of page views are in German. → Visits probably do not find the language switcher: put it in the header, as the name of the language and not only a flag.', Insights::text($insights[0]));
    }

    public function testATranslationVisitsSwitchToOnTheWrongPages(): void
    {
        $data = self::languages(['en' => [3000, 1500], 'de' => [600, 300]]);
        self::page($data, '/program', 4000, 1500);
        self::page($data, '/de/program', 100, 50);
        // 5 % of 300 German visits switch.
        self::move($data, '/program', '/de/program', 12);
        self::move($data, '/', '/de', 3);

        $insights = (new UnreachedTranslationRule())->evaluate(Insights::context($data, self::english(), k: 5));

        self::assertSame('17% of visits prefer German, but only 2% of page views are in German. → Visits find the language switcher, but arrive on the wrong language: add hreflang links, put the translations in the sitemap, and offer German on the pages they switch on most: Program (a suggestion, never a forced redirect).', Insights::text($insights[0]), 'the homepage has fewer switches than the threshold');
    }

    public function testATranslationThatReachesItsVisitsIsFine(): void
    {
        $rule = new UnreachedTranslationRule();

        // 17 % of visits, 9 % of page views: more than half.
        $data = self::languages(['en' => [3000, 1500], 'de' => [600, 300]]);
        self::page($data, '/program', 4000, 1500);
        self::page($data, '/de/program', 400, 50);
        self::assertSame([], $rule->evaluate(Insights::context($data, self::english())));

        // 4 % of visits.
        $few = self::languages(['en' => [3000, 2400], 'de' => [150, 100]]);
        self::page($few, '/program', 4000, 1500);
        self::assertSame([], $rule->evaluate(Insights::context($few, self::english())));
    }

    public function testMoreVisitsWantAnotherLanguageThanTheDefaultOne(): void
    {
        $data = self::languages(['cs' => [2000, 700], 'en' => [600, 300]]);
        self::page($data, '/', 3050, 900);
        self::page($data, '/cs', 1950, 100);

        $insights = (new UnreachedTranslationRule())->evaluate(Insights::context($data, new FakePageCatalog('en', ['en', 'cs'])));

        self::assertSame('70% of visits prefer Czech, more than English, but the site opens in English, and 61% of page views stay in it. → Make Czech the default language, or offer it on the homepage.', Insights::text($insights[0]));
    }

    public function testAnUnusedTranslation(): void
    {
        $data = self::languages(['en' => [2000, 998], 'pl' => [3, 2]]);
        self::page($data, '/program', 2000, 1000);
        self::page($data, '/pl/program', 12, 2);

        $insights = (new UnusedTranslationRule())->evaluate(Insights::context($data, new FakePageCatalog('en', ['en', 'pl'])));

        self::assertSame(['unused-translation'], Insights::ids($insights));
        self::assertSame([Insight::KIND_DECIDE, 0.0], [$insights[0]->kind, $insights[0]->impact], 'after every fix and opportunity');
        self::assertSame('Polish: 12 page views in 90 days (0.6%), and 0.2% of visits prefer it. → Keeping it up to date may not pay off: translate only the key pages, stop translating new content, or remove the language.', Insights::text($insights[0]));
        self::assertSame("Polština: 12 zobrazení stránek za 90 dní (0,6\u{a0}%) a tento jazyk preferuje 0,2\u{a0}% návštěv.", Insights::plain($insights[0]->message, 'cs'), 'a name that starts the sentence starts with a capital letter');
    }

    public function testSeveralUnusedTranslationsAreOneInsight(): void
    {
        $data = self::languages(['en' => [2000, 1000]]);
        self::page($data, '/program', 2000, 1000);
        self::page($data, '/pl/program', 12, 2);
        self::page($data, '/de/program', 20, 2);

        $insights = (new UnusedTranslationRule())->evaluate(Insights::context($data, new FakePageCatalog('en', ['en', 'pl', 'de'])));

        self::assertCount(1, $insights);
        self::assertStringStartsWith('Polish, German together: 32 page views in 90 days (2%), and 0% of visits prefer one of them.', Insights::text($insights[0]));
    }

    public function testAnUnusedTranslationNeedsEnoughPageViewsAndLowShares(): void
    {
        $rule = new UnusedTranslationRule();

        $small = self::languages(['en' => [900, 500]]);
        self::page($small, '/program', 990, 500);
        self::page($small, '/pl/program', 5, 1);
        self::assertSame([], $rule->evaluate(Insights::context($small, new FakePageCatalog('en', ['en', 'pl']))), 'below 1 000 page views');

        $used = self::languages(['en' => [2000, 1000]]);
        self::page($used, '/program', 1980, 1000);
        self::page($used, '/pl/program', 20, 2);
        self::assertSame([], $rule->evaluate(Insights::context($used, new FakePageCatalog('en', ['en', 'pl']))), '1 % of the page views');

        $wanted = self::languages(['en' => [2000, 990], 'pl' => [20, 10]]);
        self::page($wanted, '/program', 2000, 1000);
        self::assertSame([], $rule->evaluate(Insights::context($wanted, new FakePageCatalog('en', ['en', 'pl']))), '1 % of the visits prefer it');
    }

    /**
     * @param array<string, array{int, int}> $languages language => [page views, visits]
     */
    private static function languages(array $languages): MemoryInsightData
    {
        $data = new MemoryInsightData();
        foreach ($languages as $lang => [$views, $entries]) {
            $data->languages[] = [
                'day' => '2026-09-01',
                'lang' => $lang,
                'views' => $views,
                'entries' => $entries,
            ];
        }

        return $data;
    }

    private static function page(MemoryInsightData $data, string $path, int $views, int $entries): void
    {
        $data->pages[] = [
            'day' => '2026-09-01',
            'path' => $path,
            'views' => $views,
            'entries' => $entries,
        ];
    }

    private static function move(MemoryInsightData $data, string $from, string $to, int $hits): void
    {
        $data->moves[] = [
            'day' => '2026-09-01',
            'from' => $from,
            'to' => $to,
            'hits' => $hits,
        ];
    }

    private static function czech(): FakePageCatalog
    {
        return new FakePageCatalog('cs', ['cs'], [
            '/program' => 'Program',
            '/vstupenky' => 'Vstupenky',
        ]);
    }

    private static function english(): FakePageCatalog
    {
        return new FakePageCatalog('en', ['en', 'de'], [
            '/program' => 'Program',
        ]);
    }
}
