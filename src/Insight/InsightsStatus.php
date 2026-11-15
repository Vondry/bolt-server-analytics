<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Insight;

use DateTimeImmutable;
use DateTimeZone;
use TomVondracek\RequestAnalytics\Query\DayRange;

/**
 * What the insights card says when no insight fires, because "nothing" means
 * two different things:
 *
 * - waiting: fewer than 28 complete days of statistics; no rule runs yet.
 * - quiet: the days are there, but too few visits to tell a problem from
 *   chance (the rules count in hundreds of visits).
 * - partial: the 28-day checks found nothing; those comparing longer periods
 *   wait for 90 days of statistics.
 * - clear: every check ran on enough data and found nothing.
 */
final readonly class InsightsStatus
{
    public const WAITING = 'waiting';
    public const QUIET = 'quiet';
    public const PARTIAL = 'partial';
    public const CLEAR = 'clear';

    /**
     * Fewer visits in the days checked (up to 90) are too few for the rules:
     * a fall in visits needs 300 in 28 days, a language 100 visits that are
     * at least a tenth of all of them.
     */
    public const MIN_VISITS = 300;

    private function __construct(
        public string $state,
        public Message $message,
    ) {
    }

    /**
     * Before the first rollup: not a day of statistics yet.
     */
    public static function noStatistics(): self
    {
        return new self(self::WAITING, Message::of('No statistics yet. Insights need %days% complete days of them and then appear here on their own.', [
            'days' => Param::number(InsightContext::RECENT_DAYS),
        ]));
    }

    public static function of(InsightContext $context): self
    {
        $first = $context->firstDay();
        if ($first === null) {
            return self::noStatistics();
        }

        if (! $context->coversRecent()) {
            return new self(self::WAITING, Message::of('Insights need %days% complete days of statistics (so far: %count%). The first ones can appear on %day%.', [
                'days' => Param::number(InsightContext::RECENT_DAYS),
                'count' => Param::number($first <= $context->lastDay ? DayRange::of($first, $context->lastDay)->length() : 0),
                'day' => Param::day(self::after($first, InsightContext::RECENT_DAYS)),
            ]));
        }

        $checked = DayRange::of(max($first, $context->structural->from), $context->lastDay);
        $visits = $context->totals($checked)['visits'];
        if ($visits < $context->k(self::MIN_VISITS)) {
            return new self(self::QUIET, Message::of('Too few visits to tell a real problem from chance (last %days% days: %count%). Insights appear as the site gets more visits.', [
                'days' => Param::number($checked->length()),
                'count' => Param::number($visits),
            ]));
        }

        if (! $context->coversStructural()) {
            return new self(self::PARTIAL, Message::of('Nothing needs attention so far. Checks that compare longer periods start once there are %days% days of statistics, on %day%.', [
                'days' => Param::number(InsightContext::STRUCTURAL_DAYS),
                'day' => Param::day(self::after($first, InsightContext::STRUCTURAL_DAYS)),
            ]));
        }

        return new self(self::CLEAR, Message::of('Nothing needs attention. Broken links, site search, languages, visits and browsers of the last %days% days look fine.', [
            'days' => Param::number(InsightContext::STRUCTURAL_DAYS),
        ]));
    }

    /**
     * The day the statistics from $first cover $days complete days: those
     * days, and the rollup after midnight.
     */
    private static function after(string $first, int $days): string
    {
        return (new DateTimeImmutable($first, new DateTimeZone('UTC')))->modify(sprintf('+%d days', $days))->format('Y-m-d');
    }
}
