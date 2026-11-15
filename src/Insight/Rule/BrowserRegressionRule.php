<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Insight\Rule;

use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Collector\UserAgentParser;
use TomVondracek\RequestAnalytics\Insight\Insight;
use TomVondracek\RequestAnalytics\Insight\InsightContext;
use TomVondracek\RequestAnalytics\Insight\InsightRule;
use TomVondracek\RequestAnalytics\Insight\Message;
use TomVondracek\RequestAnalytics\Insight\Param;
use TomVondracek\RequestAnalytics\Query\DayRange;

/**
 * 🔧 A browser (or a device class) stopped working well: with at least 5 %
 * and 100 visits in both windows, its pages per visit fell by 25 % or more,
 * while those of all other visits changed by less than 10 %.
 *
 * Each browser is compared with its own past, never with other browsers:
 * in-app browsers and audiences differ by nature.
 *
 * Since when: the day of the last 28 that best splits its daily pages per
 * visit into a higher part before and a lower part after.
 *
 * Device classes only when no browser fired: a broken browser drags its
 * device class along.
 */
final readonly class BrowserRegressionRule implements InsightRule
{
    private const MIN_SHARE = 0.05;
    private const MIN_VISITS = 100;
    private const MIN_FALL = 0.25;
    private const MAX_OTHERS_CHANGE = 0.1;

    /**
     * Values that are no browser or device.
     */
    private const NOT_A_SUBJECT = ['', UserAgentParser::OTHER, Hit::OPT_OUT];

    public function dependsOn(): array
    {
        return [InsightContext::DATA_COUNTS, InsightContext::DATA_REFERRERS];
    }

    public function evaluate(InsightContext $context): array
    {
        if (! $context->coversChange()) {
            return [];
        }

        $rows = $context->clients(DayRange::of($context->before->from, $context->recent->to));

        $browsers = $this->dimension($context, $rows, 'browser');

        return $browsers !== [] ? $browsers : $this->dimension($context, $rows, 'device');
    }

    /**
     * @param list<array{day: string, device: string, browser: string, views: int, entries: int}> $rows
     * @param 'browser'|'device' $dimension
     *
     * @return list<Insight>
     */
    private function dimension(InsightContext $context, array $rows, string $dimension): array
    {
        $windows = [
            'before' => [],
            'recent' => [],
        ];
        $totals = [
            'before' => [
                'views' => 0,
                'entries' => 0,
            ],
            'recent' => [
                'views' => 0,
                'entries' => 0,
            ],
        ];
        $daily = [];
        foreach ($rows as $row) {
            $window = $row['day'] >= $context->recent->from ? 'recent' : 'before';
            $value = $row[$dimension];
            $windows[$window][$value] ??= [
                'views' => 0,
                'entries' => 0,
            ];
            $windows[$window][$value]['views'] += $row['views'];
            $windows[$window][$value]['entries'] += $row['entries'];
            $totals[$window]['views'] += $row['views'];
            $totals[$window]['entries'] += $row['entries'];
            $daily[$value][$row['day']] ??= [
                'views' => 0,
                'entries' => 0,
            ];
            $daily[$value][$row['day']]['views'] += $row['views'];
            $daily[$value][$row['day']]['entries'] += $row['entries'];
        }

        $insights = [];
        foreach ($windows['recent'] as $value => $recent) {
            $value = (string) $value;
            $before = $windows['before'][$value] ?? null;
            if ($before === null || in_array($value, self::NOT_A_SUBJECT, true)) {
                continue;
            }

            if (! self::enough($context, $before, $totals['before']) || ! self::enough($context, $recent, $totals['recent'])) {
                continue;
            }

            $perVisitBefore = $before['views'] / $before['entries'];
            $perVisit = $recent['views'] / $recent['entries'];
            $fall = 1 - $perVisit / $perVisitBefore;
            if ($fall < self::MIN_FALL || ! $this->othersUnchanged($before, $recent, $totals)) {
                continue;
            }

            $params = [
                'subject' => $dimension === 'browser' ? Param::browser($value) : Param::device($value),
                'after' => Param::decimal($perVisit),
                'before' => Param::decimal($perVisitBefore),
                'day' => Param::day(self::since($context, $daily[$value] ?? [])),
            ];

            $insights[] = new Insight(
                id: $dimension . '-regression:' . $value,
                section: $dimension === 'browser' ? Insight::SECTION_BROWSERS : Insight::SECTION_DEVICES,
                kind: Insight::KIND_FIX,
                impact: $recent['entries'] * $fall,
                message: $dimension === 'browser'
                    ? Message::of('Visits in %subject% open %after% pages per visit, down from %before%, since about %day%. Other browsers are unchanged.', $params)
                    : Message::of('Visits on %subject% open %after% pages per visit, down from %before%, since about %day%. Other devices are unchanged.', $params),
                action: $dimension === 'browser'
                    ? Message::of('Test the site in %subject%, especially what was deployed around that day.', $params)
                    : Message::of('Test the site on %subject%, especially what was deployed around that day.', $params),
                compared: true,
            );
        }

        return $insights;
    }

    /**
     * At least 100 visits (k, if higher) and 5 % of all visits.
     *
     * @param array{views: int, entries: int} $counts
     * @param array{views: int, entries: int} $total
     */
    private static function enough(InsightContext $context, array $counts, array $total): bool
    {
        return $counts['entries'] >= $context->k(self::MIN_VISITS) && $counts['entries'] >= self::MIN_SHARE * $total['entries'];
    }

    /**
     * @param array{views: int, entries: int} $before
     * @param array{views: int, entries: int} $recent
     * @param array{before: array{views: int, entries: int}, recent: array{views: int, entries: int}} $totals
     */
    private function othersUnchanged(array $before, array $recent, array $totals): bool
    {
        $entriesBefore = $totals['before']['entries'] - $before['entries'];
        $entries = $totals['recent']['entries'] - $recent['entries'];
        if ($entriesBefore <= 0 || $entries <= 0) {
            return false;
        }

        $othersBefore = ($totals['before']['views'] - $before['views']) / $entriesBefore;
        $others = ($totals['recent']['views'] - $recent['views']) / $entries;

        return $othersBefore > 0 && abs($others / $othersBefore - 1) < self::MAX_OTHERS_CHANGE;
    }

    /**
     * The day of the last 28 where the daily pages per visit split best into
     * a higher mean before and a lower one from that day on (least squares);
     * the first of the 28 when none does.
     *
     * @param array<string, array{views: int, entries: int}> $days
     */
    private static function since(InsightContext $context, array $days): string
    {
        ksort($days);
        $series = [];
        foreach ($days as $day => $counts) {
            if ($counts['entries'] > 0) {
                $series[] = [(string) $day, $counts['views'] / $counts['entries']];
            }
        }

        $best = $context->recent->from;
        $bestError = INF;
        for ($index = 1, $count = count($series); $index < $count; ++$index) {
            if ($series[$index][0] < $context->recent->from) {
                continue;
            }

            $higher = array_column(array_slice($series, 0, $index), 1);
            $lower = array_column(array_slice($series, $index), 1);
            $higherMean = array_sum($higher) / count($higher);
            $lowerMean = array_sum($lower) / count($lower);
            if ($higherMean <= $lowerMean) {
                continue;
            }

            $error = 0.0;
            foreach ($higher as $value) {
                $error += ($value - $higherMean) ** 2;
            }
            foreach ($lower as $value) {
                $error += ($value - $lowerMean) ** 2;
            }

            if ($error < $bestError) {
                $bestError = $error;
                $best = $series[$index][0];
            }
        }

        return $best;
    }
}
