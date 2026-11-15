<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Support;

use TomVondracek\RequestAnalytics\Insight\Insight;
use TomVondracek\RequestAnalytics\Insight\InsightContext;
use TomVondracek\RequestAnalytics\Insight\InsightFormatter;
use TomVondracek\RequestAnalytics\Insight\Message;
use TomVondracek\RequestAnalytics\Query\DayRange;

/**
 * The windows of the rule tests, and their insights as plain English text.
 *
 * The last complete day is 12 Nov 2026:
 *
 * - recent: 16 Oct – 12 Nov (28 days)
 * - before: 18 Sep – 15 Oct (28 days)
 * - structural: 15 Aug – 12 Nov (90 days)
 */
final class Insights
{
    public const LAST_DAY = '2026-11-12';
    public const RECENT_FROM = '2026-10-16';
    public const BEFORE_FROM = '2026-09-18';
    public const BEFORE_TO = '2026-10-15';
    public const STRUCTURAL_FROM = '2026-08-15';

    /**
     * @param list<string> $ownHosts
     */
    public static function context(MemoryInsightData $data, ?FakePageCatalog $catalog = null, int $k = 1, array $ownHosts = []): InsightContext
    {
        return new InsightContext($data, $catalog ?? new FakePageCatalog(), self::LAST_DAY, $k, $ownHosts);
    }

    public static function recent(): DayRange
    {
        return DayRange::of(self::RECENT_FROM, self::LAST_DAY);
    }

    public static function before(): DayRange
    {
        return DayRange::of(self::BEFORE_FROM, self::BEFORE_TO);
    }

    public static function structural(): DayRange
    {
        return DayRange::of(self::STRUCTURAL_FROM, self::LAST_DAY);
    }

    /**
     * The sentence and its next step, as the page shows them, without markup.
     */
    public static function text(Insight $insight, string $locale = 'en'): string
    {
        return self::plain($insight->message, $locale) . ($insight->action instanceof Message ? ' → ' . self::plain($insight->action, $locale) : '');
    }

    public static function plain(Message $message, string $locale = 'en'): string
    {
        return html_entity_decode(strip_tags((new InsightFormatter(Translations::translator($locale)))->sentence($message, $locale)), ENT_QUOTES | ENT_HTML5);
    }

    /**
     * @param list<Insight> $insights
     *
     * @return list<string>
     */
    public static function ids(array $insights): array
    {
        return array_map(static fn (Insight $insight): string => $insight->id, $insights);
    }
}
