<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Insight\Rule;

use TomVondracek\RequestAnalytics\Insight\Insight;
use TomVondracek\RequestAnalytics\Insight\InsightContext;
use TomVondracek\RequestAnalytics\Insight\InsightRule;
use TomVondracek\RequestAnalytics\Insight\Message;
use TomVondracek\RequestAnalytics\Insight\Param;

/**
 * 🔧 Broken links that lose visitors, in the last 28 days: a missing page
 * linked from a page of this site (any count: the link is in the site's own
 * markup), or requested at least max(k, 10) times from other sites.
 *
 * Suggests the page that was probably meant: a published page whose last
 * path segment is the same, close (edit distance ≤ 3), or one of its words;
 * only when exactly one page matches best.
 */
final readonly class BrokenLinkRule implements InsightRule
{
    private const MIN_EXTERNAL = 10;
    private const LISTED = 3;
    private const MAX_DISTANCE = 3;
    private const MIN_WORD = 4;

    public function dependsOn(): array
    {
        return [];
    }

    public function evaluate(InsightContext $context): array
    {
        if (! $context->coversRecent()) {
            return [];
        }

        $paths = [];
        foreach ($context->notFound($context->recent) as $row) {
            $path = $row['path'];
            $paths[$path] ??= [
                'hits' => 0,
                'internal' => [],
                'external' => [],
            ];
            $paths[$path]['hits'] += $row['hits'];
            if (str_starts_with($row['ref'], '/')) {
                $paths[$path]['internal'][$row['ref']] = ($paths[$path]['internal'][$row['ref']] ?? 0) + $row['hits'];
            } elseif ($row['ref'] !== '') {
                $paths[$path]['external'][$row['ref']] = ($paths[$path]['external'][$row['ref']] ?? 0) + $row['hits'];
            }
        }

        $paths = array_filter($paths, static fn (array $path): bool => $path['internal'] !== [] || array_sum($path['external']) >= $context->k(self::MIN_EXTERNAL));
        uksort($paths, static fn (string $a, string $b): int => [$paths[$b]['hits'], $a] <=> [$paths[$a]['hits'], $b]);

        $insights = [];
        foreach (array_slice($paths, 0, self::LISTED, true) as $path => $broken) {
            $insights[] = $this->insight($context, (string) $path, $broken);
        }

        if ($insights !== [] && count($paths) > self::LISTED) {
            $last = array_pop($insights);
            $insights[] = new Insight($last->id, $last->section, $last->kind, $last->impact, $last->message, $last->action, more: count($paths) - self::LISTED);
        }

        return $insights;
    }

    /**
     * @param array{hits: int, internal: array<string, int>, external: array<string, int>} $broken
     */
    private function insight(InsightContext $context, string $path, array $broken): Insight
    {
        $target = $this->suggest($context, $path);
        $count = Param::number($broken['hits']);
        $missing = Param::path($path);

        if ($broken['internal'] !== []) {
            arsort($broken['internal']);
            $message = Message::of('%page% links to %path%, which does not exist (requests: %count%).', [
                'page' => $context->page((string) array_key_first($broken['internal'])),
                'path' => $missing,
                'count' => $count,
            ]);
            $action = $target === null
                ? Message::of('Fix or remove the link on that page.')
                : Message::of('Probably %target% was meant: fix the link on that page.', [
                    'target' => $context->page($target),
                ]);
        } else {
            arsort($broken['external']);
            $host = (string) array_key_first($broken['external']);
            $message = $broken['external'][$host] >= $context->k()
                ? Message::of('%path% does not exist, and gets %count% requests from %host%.', [
                    'path' => $missing,
                    'count' => $count,
                    'host' => Param::text($host),
                ])
                : Message::of('%path% does not exist, and gets %count% requests from other sites.', [
                    'path' => $missing,
                    'count' => $count,
                ]);
            $action = $target === null
                ? Message::of('Redirect it to the page that replaced it.')
                : Message::of('Redirect it to %target%.', [
                    'target' => $context->page($target),
                ]);
        }

        return new Insight(
            id: 'broken-link:' . $path,
            section: Insight::SECTION_BROKEN_LINKS,
            kind: Insight::KIND_FIX,
            impact: $broken['hits'],
            message: $message,
            action: $action,
        );
    }

    /**
     * The published page that was probably meant, in the language of the
     * missing path; null when none, or several, match best.
     */
    private function suggest(InsightContext $context, string $missing): ?string
    {
        [$key, $locale] = $context->split($missing);
        $segment = self::lastSegment($key);
        if ($segment === '') {
            return null;
        }

        $best = null;
        $bestScore = PHP_INT_MAX;
        $tie = false;
        foreach (array_keys($context->titles()) as $page) {
            $page = (string) $page;
            $slug = self::lastSegment($page);
            $score = $page === $key || $slug === '' ? null : self::score($segment, $slug);
            if ($score === null || $score > $bestScore) {
                continue;
            }

            $tie = $score === $bestScore;
            $bestScore = $score;
            $best = $page;
        }

        if ($best === null || $tie) {
            return null;
        }

        return $locale === $context->defaultLocale() ? $best : '/' . $locale . $best;
    }

    /**
     * How well a slug matches the last segment of a missing path: 0 the
     * same, 1–3 the edit distance, 4 one is a word of the other
     * (`vstupenky` of `vstupenky-2025`); null not at all.
     */
    private static function score(string $segment, string $slug): ?int
    {
        if ($segment === $slug) {
            return 0;
        }

        if (mb_strlen($segment) <= 255 && mb_strlen($slug) <= 255) {
            $distance = levenshtein($segment, $slug);
            // Not for short slugs, which are all close to each other.
            if ($distance <= self::MAX_DISTANCE && $distance * 2 < min(mb_strlen($segment), mb_strlen($slug))) {
                return $distance;
            }
        }

        foreach ([[$segment, $slug], [$slug, $segment]] as [$long, $word]) {
            if (mb_strlen($word) >= self::MIN_WORD && preg_match('~(?:^|[-_.])' . preg_quote($word, '~') . '(?:$|[-_.])~u', $long) === 1) {
                return self::MAX_DISTANCE + 1;
            }
        }

        return null;
    }

    private static function lastSegment(string $path): string
    {
        $segments = array_values(array_filter(explode('/', mb_strtolower($path)), static fn (string $segment): bool => $segment !== ''));

        return $segments === [] ? '' : $segments[count($segments) - 1];
    }
}
