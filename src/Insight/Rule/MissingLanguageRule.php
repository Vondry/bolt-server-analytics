<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Insight\Rule;

use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Insight\Insight;
use TomVondracek\RequestAnalytics\Insight\InsightContext;
use TomVondracek\RequestAnalytics\Insight\InsightRule;
use TomVondracek\RequestAnalytics\Insight\Message;
use TomVondracek\RequestAnalytics\Insight\Param;

/**
 * 🚀 A language visits want, but the site does not have (90 days): at least
 * 10 % and 100 visits come from browsers asking for it first.
 *
 * The safeguard: the language of a browser is not what its user reads (many
 * Czechs browse in English), so it fires only when these visits open at most
 * 70 % of the pages per visit of the others. Visits that browse like everyone
 * else read the site's language.
 */
final readonly class MissingLanguageRule implements InsightRule
{
    private const MIN_SHARE = 0.1;
    private const MIN_VISITS = 100;
    private const MAX_PER_VISIT_RATIO = 0.7;
    private const ENTRY_PAGES = 3;

    public function dependsOn(): array
    {
        return [InsightContext::DATA_REFERRERS];
    }

    public function evaluate(InsightContext $context): array
    {
        if (! $context->coversStructural()) {
            return [];
        }

        $languages = $context->languages($context->structural);
        $visits = array_sum(array_column($languages, 'entries'));
        $views = array_sum(array_column($languages, 'views'));
        $offered = $context->siteLanguages();

        $insights = [];
        foreach ($languages as $language => $counts) {
            $language = (string) $language;
            if ($language === Hit::UNKNOWN_LANGUAGE || in_array($language, $offered, true)
                || $counts['entries'] < $context->k(self::MIN_VISITS) || $counts['entries'] < self::MIN_SHARE * $visits
            ) {
                continue;
            }

            $otherVisits = $visits - $counts['entries'];
            if ($otherVisits <= 0) {
                // Nobody to compare with.
                continue;
            }

            $perVisit = $counts['views'] / $counts['entries'];
            $othersPerVisit = ($views - $counts['views']) / $otherVisits;
            if ($perVisit > self::MAX_PER_VISIT_RATIO * $othersPerVisit) {
                continue;
            }

            $params = [
                'count' => Param::number($counts['entries']),
                'share' => Param::percent($counts['entries'] / $visits),
                'language' => Param::language($language),
                'per_visit' => Param::decimal($perVisit),
                'others' => Param::decimal($othersPerVisit),
            ];
            [$pages, $pagesShare] = $this->entryPages($context);

            $insights[] = new Insight(
                id: 'missing-language:' . $language,
                section: Insight::SECTION_LANGUAGES,
                kind: Insight::KIND_GROW,
                impact: $counts['entries'],
                message: Message::of('%count% visits (%share%) come from browsers asking for %language% first. The site has no pages in it, and these visits open %per_visit% pages per visit against %others% for the others.', $params),
                action: $pages === []
                    ? Message::of('Translate the pages most visits start on first.')
                    : Message::of('Translate the pages most visits start on first: %pages% (%pages_share% of visits start there).', [
                        'pages' => Param::list($pages),
                        'pages_share' => Param::percent($pagesShare),
                    ]),
                days: InsightContext::STRUCTURAL_DAYS,
            );
        }

        return $insights;
    }

    /**
     * The pages most visits start on, and their share of all visits.
     *
     * @return array{list<Param>, float}
     */
    private function entryPages(InsightContext $context): array
    {
        $entries = [];
        foreach ($context->pages($context->structural) as $path => $counts) {
            [$key] = $context->split((string) $path);
            $entries[$key] = ($entries[$key] ?? 0) + $counts['entries'];
        }

        $total = array_sum($entries);
        $entries = array_filter($entries, static fn (int $count): bool => $count >= $context->k());
        uksort($entries, static fn (string $a, string $b): int => [$entries[$b], $a] <=> [$entries[$a], $b]);
        $top = array_slice($entries, 0, self::ENTRY_PAGES, true);

        $pages = [];
        foreach (array_keys($top) as $path) {
            $pages[] = Param::page((string) $path, $context->titles()[$path] ?? null);
        }

        return [$pages, $total > 0 ? array_sum($top) / $total : 0.0];
    }
}
