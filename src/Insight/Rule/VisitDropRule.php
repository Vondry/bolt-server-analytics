<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Insight\Rule;

use TomVondracek\RequestAnalytics\Collector\Channel;
use TomVondracek\RequestAnalytics\Insight\Insight;
use TomVondracek\RequestAnalytics\Insight\InsightContext;
use TomVondracek\RequestAnalytics\Insight\InsightRule;
use TomVondracek\RequestAnalytics\Insight\Message;
use TomVondracek\RequestAnalytics\Insight\Param;

/**
 * 🔧 Where the lost visits went: visits of the last 28 days fell by 20 % or
 * more against the 28 before (a real change, from at least 300 visits). The
 * loss is split by channel, and within the channel that lost most, by
 * landing page. Not for growth: a rise needs no action.
 */
final readonly class VisitDropRule implements InsightRule
{
    private const MIN_VISITS = 300;
    private const MIN_FALL = 0.2;
    private const LANDING_PAGES = 3;

    /**
     * Pages per visit fell "clearly" more than visits: by this much.
     */
    private const PER_VISIT_FALL = 0.1;

    private const SEARCH = [Channel::ORGANIC_SEARCH, Channel::AI_ASSISTANT];
    private const CAMPAIGNS = [Channel::SOCIAL, Channel::CAMPAIGN, Channel::EMAIL, Channel::PAID_SEARCH, Channel::DISPLAY, Channel::AFFILIATE];

    public function dependsOn(): array
    {
        return [InsightContext::DATA_COUNTS, InsightContext::DATA_REFERRERS];
    }

    public function evaluate(InsightContext $context): array
    {
        if (! $context->coversChange()) {
            return [];
        }

        $before = $context->totals($context->before);
        $after = $context->totals($context->recent);
        if ($before['visits'] < self::MIN_VISITS || $after['visits'] > (1 - self::MIN_FALL) * $before['visits'] || ! InsightContext::isRealChange($before['visits'], $after['visits'])) {
            return [];
        }

        [$channel, $share, $pages, $source] = $this->attribution($context);

        $params = [
            'change' => Param::change(($after['visits'] - $before['visits']) / $before['visits']),
            'before' => Param::number($before['visits']),
            'after' => Param::number($after['visits']),
            'channel' => Param::channel($channel),
            'share' => Param::percent($share),
        ];
        $fewerPages = $after['visits'] > 0 && $before['pageviews'] > 0
            && ($after['pageviews'] / $after['visits']) <= (1 - self::PER_VISIT_FALL) * ($before['pageviews'] / $before['visits']);

        if ($pages === []) {
            $message = $fewerPages
                ? Message::of('Visits fell %change% (%before% → %after%). %channel% accounts for %share% of the loss. Visits also open fewer pages per visit.', $params)
                : Message::of('Visits fell %change% (%before% → %after%). %channel% accounts for %share% of the loss.', $params);
        } else {
            $params['pages'] = Param::list($pages);
            $message = $fewerPages
                ? Message::of('Visits fell %change% (%before% → %after%). %channel% accounts for %share% of the loss, mostly %pages%. Visits also open fewer pages per visit.', $params)
                : Message::of('Visits fell %change% (%before% → %after%). %channel% accounts for %share% of the loss, mostly %pages%.', $params);
        }

        return [new Insight(
            id: 'visit-drop',
            section: Insight::SECTION_SOURCES,
            kind: Insight::KIND_FIX,
            impact: $before['visits'] - $after['visits'],
            message: $message,
            action: $this->action($channel, $source),
            compared: true,
        )];
    }

    /**
     * The channel that lost most, its share of the loss of all channels that
     * lost, its landing pages that lost most, and its source that lost most.
     *
     * @return array{string, float, list<Param>, ?string}
     */
    private function attribution(InsightContext $context): array
    {
        $loss = [];
        $pages = [];
        $sources = [];
        foreach ([[$context->before, 1], [$context->recent, -1]] as [$range, $sign]) {
            foreach ($context->sources($range) as $row) {
                $channel = $row['channel'];
                [$key] = $context->split($row['path']);
                $loss[$channel] = ($loss[$channel] ?? 0) + $sign * $row['visits'];
                $pages[$channel][$key]['loss'] = ($pages[$channel][$key]['loss'] ?? 0) + $sign * $row['visits'];
                $pages[$channel][$key]['before'] = ($pages[$channel][$key]['before'] ?? 0) + ($sign > 0 ? $row['visits'] : 0);
                $sources[$channel][$row['source']]['loss'] = ($sources[$channel][$row['source']]['loss'] ?? 0) + $sign * $row['visits'];
                $sources[$channel][$row['source']]['before'] = ($sources[$channel][$row['source']]['before'] ?? 0) + ($sign > 0 ? $row['visits'] : 0);
            }
        }

        $lost = array_filter($loss, static fn (int $visits): bool => $visits > 0);
        if ($lost === []) {
            return [Channel::UNKNOWN, 1.0, [], null];
        }

        uksort($lost, static fn (string $a, string $b): int => [$lost[$b], $a] <=> [$lost[$a], $b]);
        $channel = (string) array_key_first($lost);

        // Named only when it had enough visits to be named.
        $landing = array_filter($pages[$channel] ?? [], static fn (array $page): bool => $page['loss'] > 0 && $page['before'] >= $context->k());
        uksort($landing, static fn (string $a, string $b): int => [$landing[$b]['loss'], $a] <=> [$landing[$a]['loss'], $b]);
        $listed = [];
        foreach (array_slice($landing, 0, self::LANDING_PAGES, true) as $path => $page) {
            $listed[] = Param::page((string) $path, $context->titles()[$path] ?? null, -$page['loss']);
        }

        $candidates = array_filter($sources[$channel] ?? [], static fn (array $source): bool => $source['loss'] > 0 && $source['before'] >= $context->k());
        uksort($candidates, static fn (string $a, string $b): int => [$candidates[$b]['loss'], $a] <=> [$candidates[$a]['loss'], $b]);
        $source = array_key_first($candidates);

        return [$channel, $lost[$channel] / array_sum($lost), $listed, $source === null || $source === '' ? null : (string) $source];
    }

    private function action(string $channel, ?string $source): Message
    {
        if (in_array($channel, self::SEARCH, true)) {
            return Message::of('Check the search ranking of these pages, and whether their title or address changed.');
        }

        if (in_array($channel, self::CAMPAIGNS, true)) {
            return Message::of('Probably a recurring post or campaign stopped: check what was published there before.');
        }

        if ($channel === Channel::REFERRAL) {
            return $source === null
                ? Message::of('Probably a linking site removed its link.')
                : Message::of('Probably %source% removed its link: check the linking page.', [
                    'source' => Param::text($source),
                ]);
        }

        return Message::of('Check bookmarks and printed links (posters, QR codes) that lead to these pages.');
    }
}
