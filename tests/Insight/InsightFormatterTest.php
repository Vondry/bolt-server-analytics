<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Insight;

use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Insight\Insight;
use TomVondracek\RequestAnalytics\Insight\InsightContext;
use TomVondracek\RequestAnalytics\Insight\InsightFormatter;
use TomVondracek\RequestAnalytics\Insight\Message;
use TomVondracek\RequestAnalytics\Insight\Param;
use TomVondracek\RequestAnalytics\Tests\Support\Translations;

final class InsightFormatterTest extends TestCase
{
    public function testWhatThePageShows(): void
    {
        $insight = new Insight('visit-drop', Insight::SECTION_SOURCES, Insight::KIND_FIX, 1220, Message::of('Visits fell %change% (%before% → %after%). %channel% accounts for %share% of the loss, mostly %pages%.', [
            'change' => Param::change(-0.2392),
            'before' => Param::number(5100),
            'after' => Param::number(3880),
            'channel' => Param::channel('organic_search'),
            'share' => Param::percent(0.83),
            'pages' => Param::list([Param::page('/vstupenky', 'Vstupenky & více', -410), Param::page('/', null, -290)]),
        ]), Message::of('Check the search ranking of these pages, and whether their title or address changed.'), compared: true);

        self::assertSame([[
            'id' => 'visit-drop',
            'kind' => Insight::KIND_FIX,
            'section' => Insight::SECTION_SOURCES,
            'anchor' => 'ra-sources',
            'days' => InsightContext::RECENT_DAYS,
            'compared' => true,
            'more' => 0,
            'html' => 'Visits fell −24% (5,100 → 3,880). Organic search accounts for 83% of the loss, mostly <em title="/vstupenky">Vstupenky &amp; více</em> (−410), <em title="/">Homepage</em> (−290). <span class="ra-insight__action">→ Check the search ranking of these pages, and whether their title or address changed.</span>',
        ]], (new InsightFormatter(Translations::translator()))->format([$insight]));
    }

    /**
     * Numbers, shares and days as the backend language writes them; the
     * names of languages, channels and devices in it.
     */
    public function testValuesInTheBackendLanguage(): void
    {
        $formatter = new InsightFormatter(Translations::translator('cs'));
        $message = Message::of('%count% %share% %change% %decimal% %day% %language% %channel% %device% %browser%', [
            'count' => Param::number(1840),
            'share' => Param::percent(0.002),
            'change' => Param::change(0.5),
            'decimal' => Param::decimal(1.26),
            'day' => Param::day('2026-11-04'),
            'language' => Param::language('de'),
            'channel' => Param::channel('organic_search'),
            'device' => Param::device('mobile'),
            'browser' => Param::browser('samsung'),
        ]);

        self::assertSame("1\u{a0}840 0,2\u{a0}% +50\u{a0}% 1,3 4. 11. němčina Vyhledávače Mobil Samsung Internet", $formatter->sentence($message, 'cs'));
    }

    /**
     * Everything is escaped: paths, terms, hosts and titles come from
     * requests and editors.
     */
    public function testEveryValueIsEscaped(): void
    {
        $html = (new InsightFormatter(Translations::translator()))->sentence(Message::of('%path% %term% %host% %page%', [
            'path' => Param::path('/"><script>'),
            'term' => Param::term('<b>'),
            'host' => Param::text('a&b'),
            'page' => Param::page('/x"', '<i>'),
        ]), 'en');

        self::assertSame('<code>/&quot;&gt;&lt;script&gt;</code> <em>&lt;b&gt;</em> a&amp;b <em title="/x&quot;">&lt;i&gt;</em>', $html);
    }

    public function testAPageWithoutTitleIsItsPath(): void
    {
        self::assertSame('<code>/old</code>', (new InsightFormatter(Translations::translator()))->sentence(Message::of('%page%', [
            'page' => Param::page('/old', null),
        ]), 'en'));
    }
}
