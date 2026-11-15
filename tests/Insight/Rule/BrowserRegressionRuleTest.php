<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Insight\Rule;

use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Insight\Insight;
use TomVondracek\RequestAnalytics\Insight\Rule\BrowserRegressionRule;
use TomVondracek\RequestAnalytics\Query\DayRange;
use TomVondracek\RequestAnalytics\Tests\Support\Insights;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryInsightData;

final class BrowserRegressionRuleTest extends TestCase
{
    /**
     * Safari falls from 2.0 to 1.1 pages per visit on 25 Oct; Chrome stays.
     */
    public function testABrowserThatStoppedWorkingWellAndSinceWhen(): void
    {
        $data = new MemoryInsightData();
        self::clients($data, 'desktop', 'chrome', 40, static fn (): float => 2.0);
        self::clients($data, 'mobile', 'safari', 10, static fn (string $day): float => $day < '2026-10-25' ? 2.0 : 1.1);

        $insights = (new BrowserRegressionRule())->evaluate(Insights::context($data));

        self::assertSame(['browser-regression:safari'], Insights::ids($insights), 'not mobile too: the browser drags its device class along');
        self::assertSame([Insight::SECTION_BROWSERS, Insight::KIND_FIX, true, 'ra-dimension-browser'], [$insights[0]->section, $insights[0]->kind, $insights[0]->compared, $insights[0]->target()]);
        self::assertSame('Visits in Safari open 1.4 pages per visit, down from 2.0, since about Oct 25. Other browsers are unchanged. → Test the site in Safari, especially what was deployed around that day.', Insights::text($insights[0]));
        self::assertEqualsWithDelta(280 * 0.305, $insights[0]->impact, 0.5);
    }

    public function testADeviceClassWhenNoBrowserFired(): void
    {
        $data = new MemoryInsightData();
        self::clients($data, 'desktop', 'other', 40, static fn (): float => 2.0);
        self::clients($data, 'mobile', 'other', 10, static fn (string $day): float => $day < '2026-10-16' ? 2.0 : 1.2);

        $insights = (new BrowserRegressionRule())->evaluate(Insights::context($data));

        self::assertSame(['device-regression:mobile'], Insights::ids($insights), '"other" is no browser');
        self::assertSame('Visits on Mobile open 1.2 pages per visit, down from 2.0, since about Oct 16. Other devices are unchanged. → Test the site on Mobile, especially what was deployed around that day.', Insights::text($insights[0]));
    }

    /**
     * When every browser falls, the site changed, not one browser.
     */
    public function testNothingWhenTheOthersChangedToo(): void
    {
        $data = new MemoryInsightData();
        self::clients($data, 'desktop', 'chrome', 40, static fn (string $day): float => $day < Insights::RECENT_FROM ? 2.0 : 1.75);
        self::clients($data, 'mobile', 'safari', 10, static fn (string $day): float => $day < Insights::RECENT_FROM ? 2.0 : 1.1);

        self::assertSame([], (new BrowserRegressionRule())->evaluate(Insights::context($data)));
    }

    public function testNoInsightJustBelowEachThreshold(): void
    {
        $rule = new BrowserRegressionRule();

        // A fall of 24 %.
        $small = new MemoryInsightData();
        self::clients($small, 'desktop', 'chrome', 40, static fn (): float => 2.0);
        self::clients($small, 'mobile', 'safari', 25, static fn (string $day): float => $day < Insights::RECENT_FROM ? 2.0 : 1.52);
        self::assertSame([], $rule->evaluate(Insights::context($small)));

        // 98 visits in a window.
        $rare = new MemoryInsightData();
        self::clients($rare, 'desktop', 'chrome', 40, static fn (): float => 2.0);
        self::clients($rare, 'mobile', 'safari', 3.5, static fn (string $day): float => $day < Insights::RECENT_FROM ? 2.0 : 1.0);
        self::assertSame([], $rule->evaluate(Insights::context($rare)));

        // 4.8 % of the visits.
        $niche = new MemoryInsightData();
        self::clients($niche, 'desktop', 'chrome', 200, static fn (): float => 2.0);
        self::clients($niche, 'mobile', 'safari', 10, static fn (string $day): float => $day < Insights::RECENT_FROM ? 2.0 : 1.0);
        self::assertSame([], $rule->evaluate(Insights::context($niche)));

        // Enough visits, but fewer than the privacy threshold.
        $private = new MemoryInsightData();
        self::clients($private, 'desktop', 'chrome', 40, static fn (): float => 2.0);
        self::clients($private, 'mobile', 'safari', 10, static fn (string $day): float => $day < Insights::RECENT_FROM ? 2.0 : 1.0);
        self::assertCount(1, $rule->evaluate(Insights::context($private)));
        self::assertSame([], $rule->evaluate(Insights::context($private, k: 281)));
    }

    /**
     * Rows of a browser on every day of both windows.
     *
     * @param callable(string): float $perVisit pages per visit on a day
     */
    private static function clients(MemoryInsightData $data, string $device, string $browser, float $visitsPerDay, callable $perVisit): void
    {
        foreach (DayRange::of(Insights::BEFORE_FROM, Insights::LAST_DAY)->days() as $index => $day) {
            // Spread fractions of a visit over the days.
            $entries = (int) floor(($index + 1) * $visitsPerDay) - (int) floor($index * $visitsPerDay);
            $data->clients[] = [
                'day' => $day,
                'device' => $device,
                'browser' => $browser,
                'views' => (int) round($entries * $perVisit($day)),
                'entries' => $entries,
            ];
        }
    }
}
