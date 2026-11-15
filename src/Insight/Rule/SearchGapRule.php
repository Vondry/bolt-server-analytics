<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Insight\Rule;

use Symfony\Component\String\UnicodeString;
use TomVondracek\RequestAnalytics\Collector\Normalizer;
use TomVondracek\RequestAnalytics\Insight\Insight;
use TomVondracek\RequestAnalytics\Insight\InsightContext;
use TomVondracek\RequestAnalytics\Insight\InsightRule;
use TomVondracek\RequestAnalytics\Insight\Message;
use TomVondracek\RequestAnalytics\Insight\Param;

/**
 * 🔧 / 🚀 Searched for, but not found (90 days): a site-search term with at
 * least max(k, 20) searches, not redacted.
 *
 * A term matches a page when every word of it (lowercase, without
 * diacritics) is in the page's title or address: the page exists but is hard
 * to find (fix). A term no page matches asks for a page (opportunity).
 */
final readonly class SearchGapRule implements InsightRule
{
    private const MIN_SEARCHES = 20;
    private const LISTED = 3;
    private const MIN_WORD = 2;

    public function dependsOn(): array
    {
        return [];
    }

    public function evaluate(InsightContext $context): array
    {
        if (! $context->coversStructural()) {
            return [];
        }

        $terms = [];
        foreach ($context->searches($context->structural) as $row) {
            if (! str_contains($row['term'], Normalizer::REDACTED)) {
                $terms[$row['term']] = ($terms[$row['term']] ?? 0) + $row['hits'];
            }
        }

        $terms = array_filter($terms, static fn (int $hits): bool => $hits >= $context->k(self::MIN_SEARCHES));
        uksort($terms, static fn (string $a, string $b): int => [$terms[$b], $a] <=> [$terms[$a], $b]);

        $pages = [];
        foreach ($context->titles() as $path => $title) {
            $pages[(string) $path] = self::plain($title . ' ' . str_replace(['/', '-', '_', '.'], ' ', (string) $path));
        }

        $listed = array_slice($terms, 0, self::LISTED, true);
        $insights = [];
        foreach ($listed as $term => $hits) {
            $term = (string) $term;
            // The last listed one tells how many more there are.
            $more = count($insights) === count($listed) - 1 ? count($terms) - count($listed) : 0;
            $page = self::match($term, $pages);
            $params = [
                'count' => Param::number($hits),
                'term' => Param::term($term),
            ];

            $insights[] = $page === null
                ? new Insight(
                    id: 'search-gap:' . $term,
                    section: Insight::SECTION_SEARCH,
                    kind: Insight::KIND_GROW,
                    impact: $hits,
                    message: Message::of('%count% searches for %term%, and no page is about it.', $params),
                    action: Message::of('Write one, or add the word to a page that answers it.'),
                    days: InsightContext::STRUCTURAL_DAYS,
                    more: $more,
                )
                : new Insight(
                    id: 'search-gap:' . $term,
                    section: Insight::SECTION_SEARCH,
                    kind: Insight::KIND_FIX,
                    impact: $hits,
                    message: Message::of('%count% searches for %term%, and %page% exists: it is probably hard to find.', [
                        ...$params,
                        'page' => $context->page($page),
                    ]),
                    action: Message::of('Link it from the menu or the homepage.'),
                    days: InsightContext::STRUCTURAL_DAYS,
                    more: $more,
                );
        }

        return $insights;
    }

    /**
     * The page whose title or address has every word of the term; the
     * shortest address among several.
     *
     * @param array<string, string> $pages path => its words, plain
     */
    private static function match(string $term, array $pages): ?string
    {
        $parts = preg_split('~[^\pL\pN]+~u', self::plain($term));
        $words = array_values(array_filter($parts === false ? [] : $parts, static fn (string $word): bool => mb_strlen($word) >= self::MIN_WORD));
        if ($words === []) {
            return null;
        }

        $found = null;
        foreach ($pages as $path => $text) {
            foreach ($words as $word) {
                if (! str_contains($text, $word)) {
                    continue 2;
                }
            }

            if ($found === null || [mb_strlen($path), $path] < [mb_strlen($found), $found]) {
                $found = $path;
            }
        }

        return $found;
    }

    /**
     * Lowercase, without diacritics: `Parkování` → `parkovani`.
     */
    private static function plain(string $text): string
    {
        return (new UnicodeString($text))->ascii()->lower()->toString();
    }
}
