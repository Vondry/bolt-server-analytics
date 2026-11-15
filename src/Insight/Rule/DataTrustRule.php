<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Insight\Rule;

use DateTimeImmutable;
use DateTimeZone;
use TomVondracek\RequestAnalytics\Collector\Channel;
use TomVondracek\RequestAnalytics\Insight\Insight;
use TomVondracek\RequestAnalytics\Insight\InsightContext;
use TomVondracek\RequestAnalytics\Insight\InsightRule;
use TomVondracek\RequestAnalytics\Insight\Message;
use TomVondracek\RequestAnalytics\Insight\Param;
use TomVondracek\RequestAnalytics\Query\DayRange;

/**
 * ⚙️ The numbers cannot be trusted. Shown first; while one of these fires,
 * the rules that rely on the affected data are not evaluated.
 *
 * - Tracking gap: a day of the last 28 without page views, while the 14 days
 *   before it had a median of 20 or more. Days whose statistics were deleted
 *   on purpose are neither a gap nor part of the median.
 * - Own site counted as referral: a referring site is a host of this site.
 * - Hidden referrers: many visits without a referrer, and hardly more than
 *   one page per visit: clicks between pages count as new visits.
 */
final readonly class DataTrustRule implements InsightRule
{
    private const GAP_BASELINE_DAYS = 14;
    private const GAP_MEDIAN = 20;
    private const HIDDEN_MIN_VISITS = 300;
    private const HIDDEN_UNKNOWN_SHARE = 0.3;
    private const HIDDEN_PER_VISIT = 1.1;

    public function dependsOn(): array
    {
        return [];
    }

    public function evaluate(InsightContext $context): array
    {
        if (! $context->coversRecent()) {
            return [];
        }

        return array_values(array_filter([
            $this->trackingGap($context),
            $this->ownSiteReferral($context),
            $context->coversStructural() ? $this->hiddenReferrers($context) : null,
        ]));
    }

    private function trackingGap(InsightContext $context): ?Insight
    {
        $from = (new DateTimeImmutable($context->recent->from, new DateTimeZone('UTC')))->modify(sprintf('-%d days', self::GAP_BASELINE_DAYS))->format('Y-m-d');
        $range = DayRange::of($from, $context->recent->to);
        $daily = $context->daily($range);
        $days = $range->days();

        $gaps = [];
        foreach ($days as $index => $day) {
            if ($day < $context->recent->from || ($daily[$day]['pageviews'] ?? 0) > 0 || $context->isErased($day)) {
                continue;
            }

            $before = array_filter(array_slice($days, $index - self::GAP_BASELINE_DAYS, self::GAP_BASELINE_DAYS), static fn (string $before): bool => ! $context->isErased($before));
            $baseline = array_values(array_map(static fn (string $before): int => $daily[$before]['pageviews'] ?? 0, $before));
            if (self::median($baseline) >= self::GAP_MEDIAN) {
                $gaps[] = $day;
            }
        }

        if ($gaps === []) {
            return null;
        }

        // The latest: the one most likely still going on.
        $day = $gaps[array_key_last($gaps)];
        $params = [
            'day' => Param::day($day),
        ];
        if (($daily[$day]['bots'] ?? 0) > 0) {
            $message = Message::of('Bolt recorded no page views on %day%, but bots came: a cache or CDN probably answers without Bolt, or collection is off.', $params);
            $action = Message::of('Check that page requests reach Bolt, and that the extension is enabled.');
        } else {
            $message = Message::of('No requests at all on %day%: the site was probably down, or the rollup did not run.', $params);
            $action = Message::of('Check the server log of that day, and that the rollup runs.');
        }

        return new Insight(
            id: 'tracking-gap',
            section: Insight::SECTION_HEADLINE,
            kind: Insight::KIND_SETUP,
            impact: count($gaps),
            message: $message,
            action: $action,
            anchor: 'ra-trend',
            more: count($gaps) - 1,
            suppresses: [InsightContext::DATA_COUNTS],
        );
    }

    private function ownSiteReferral(InsightContext $context): ?Insight
    {
        if ($context->ownHosts === []) {
            return null;
        }

        $hosts = [];
        foreach ($context->sources($context->recent) as $row) {
            if ($row['channel'] === Channel::REFERRAL && in_array($row['source'], $context->ownHosts, true)) {
                $hosts[$row['source']] = ($hosts[$row['source']] ?? 0) + $row['visits'];
            }
        }

        $hosts = array_filter($hosts, static fn (int $visits): bool => $visits >= $context->k());
        if ($hosts === []) {
            return null;
        }

        arsort($hosts);
        $host = (string) array_key_first($hosts);

        return new Insight(
            id: 'own-referral',
            section: Insight::SECTION_HEADLINE,
            kind: Insight::KIND_SETUP,
            impact: array_sum($hosts),
            message: Message::of('%host% is counted as a referral (%count% visits): moves between pages of this site probably count as new visits.', [
                'host' => Param::text($host),
                'count' => Param::number($hosts[$host]),
            ]),
            action: Message::of('Add %hosts% to %setting% in the config of Request Analytics.', [
                'hosts' => Param::list([Param::code($host), Param::code('www.' . $host)]),
                'setting' => Param::code('own_hosts'),
            ]),
            anchor: 'ra-sources',
            more: count($hosts) - 1,
            suppresses: [InsightContext::DATA_REFERRERS],
        );
    }

    private function hiddenReferrers(InsightContext $context): ?Insight
    {
        $totals = $context->totals($context->structural);
        if ($totals['visits'] < self::HIDDEN_MIN_VISITS) {
            return null;
        }

        $unknown = 0;
        foreach ($context->sources($context->structural) as $row) {
            if ($row['channel'] === Channel::UNKNOWN) {
                $unknown += $row['visits'];
            }
        }

        $share = $unknown / $totals['visits'];
        $perVisit = $totals['pageviews'] / $totals['visits'];
        if ($share < self::HIDDEN_UNKNOWN_SHARE || $perVisit >= self::HIDDEN_PER_VISIT) {
            return null;
        }

        return new Insight(
            id: 'hidden-referrers',
            section: Insight::SECTION_HEADLINE,
            kind: Insight::KIND_SETUP,
            impact: $unknown,
            message: Message::of('%share% of visits arrive without a referrer, and a visit opens only %per_visit% pages: clicks between pages probably arrive without one too, so each counts as a new visit.', [
                'share' => Param::percent($share),
                'per_visit' => Param::decimal($perVisit),
            ]),
            action: Message::of('Send the header %header%.', [
                'header' => Param::code('Referrer-Policy: strict-origin-when-cross-origin'),
            ]),
            days: InsightContext::STRUCTURAL_DAYS,
            anchor: 'ra-sources',
            suppresses: [InsightContext::DATA_REFERRERS],
        );
    }

    /**
     * @param list<int> $values
     */
    private static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
