<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Insight\Rule;

use TomVondracek\RequestAnalytics\Insight\Insight;
use TomVondracek\RequestAnalytics\Insight\InsightContext;
use TomVondracek\RequestAnalytics\Insight\InsightRule;
use TomVondracek\RequestAnalytics\Insight\Message;
use TomVondracek\RequestAnalytics\Insight\Param;

/**
 * 🔧 A translation that does not reach its visits (90 days): at least 5 %
 * and 100 visits prefer a language the site has, but its share of the page
 * views is less than half its share of the visits.
 *
 * The next step depends on the switches between languages (`/x` → `/de/x`):
 * many, and the visits find the switcher but arrive on the wrong language;
 * few, and they do not find it.
 *
 * More visits prefer the language than the default one, while most page
 * views stay in the default: the default language is probably wrong.
 */
final readonly class UnreachedTranslationRule implements InsightRule
{
    private const MIN_SHARE = 0.05;
    private const MIN_VISITS = 100;
    private const SHARE_RATIO = 2;
    private const MANY_SWITCHES = 0.05;
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
        $viewsByLanguage = [];
        foreach ($context->pages($context->structural) as $path => $counts) {
            [, $locale] = $context->split((string) $path);
            $language = InsightContext::language($locale);
            $viewsByLanguage[$language] = ($viewsByLanguage[$language] ?? 0) + $counts['views'];
        }

        $views = array_sum($viewsByLanguage);
        if ($visits <= 0 || $views <= 0) {
            return [];
        }

        $default = InsightContext::language($context->defaultLocale());
        $defaultVisits = $languages[$default]['entries'] ?? 0;
        $defaultViewShare = ($viewsByLanguage[$default] ?? 0) / $views;

        $insights = [];
        foreach ($context->siteLanguages() as $language) {
            $preferring = $languages[$language]['entries'] ?? 0;
            if ($language === $default || $preferring < $context->k(self::MIN_VISITS) || $preferring < self::MIN_SHARE * $visits) {
                continue;
            }

            $visitShare = $preferring / $visits;
            $viewShare = ($viewsByLanguage[$language] ?? 0) / $views;
            $wrongDefault = $preferring > $defaultVisits && $defaultViewShare > $viewShare;
            if (! $wrongDefault && $visitShare < self::SHARE_RATIO * $viewShare) {
                continue;
            }

            $params = [
                'visit_share' => Param::percent($visitShare),
                'view_share' => Param::percent($viewShare),
                'language' => Param::language($language),
                'default' => Param::language($default),
                'default_share' => Param::percent($defaultViewShare),
            ];

            if ($wrongDefault) {
                $message = Message::of('%visit_share% of visits prefer %language%, more than %default%, but the site opens in %default%, and %default_share% of page views stay in it.', $params);
                $action = Message::of('Make %language% the default language, or offer it on the homepage.', $params);
            } else {
                $message = Message::of('%visit_share% of visits prefer %language%, but only %view_share% of page views are in %language%.', $params);
                $action = $this->switchAction($context, $language, $preferring, $params);
            }

            $insights[] = new Insight(
                id: 'unreached-translation:' . $language,
                section: Insight::SECTION_LANGUAGES,
                kind: Insight::KIND_FIX,
                impact: max(0.0, $visitShare - $viewShare) * $visits,
                message: $message,
                action: $action,
                days: InsightContext::STRUCTURAL_DAYS,
            );
        }

        return $insights;
    }

    /**
     * @param array<string, Param> $params
     */
    private function switchAction(InsightContext $context, string $language, int $preferring, array $params): Message
    {
        $switches = [];
        foreach ($context->locales() as $locale) {
            if ($locale === $context->defaultLocale() || InsightContext::language($locale) !== $language) {
                continue;
            }

            foreach ($context->movesInto($context->structural, '/' . $locale) as $move) {
                [$to, $toLocale] = $context->split($move['to']);
                [$from, $fromLocale] = $context->split($move['from']);
                if ($toLocale === $locale && $fromLocale !== $locale && $from === $to) {
                    $switches[$from] = ($switches[$from] ?? 0) + $move['hits'];
                }
            }
        }

        if (array_sum($switches) < self::MANY_SWITCHES * $preferring) {
            return Message::of('Visits probably do not find the language switcher: put it in the header, as the name of the language and not only a flag.');
        }

        $switches = array_filter($switches, static fn (int $hits): bool => $hits >= $context->k());
        uksort($switches, static fn (string $a, string $b): int => [$switches[$b], $a] <=> [$switches[$a], $b]);
        $pages = [];
        foreach (array_slice(array_keys($switches), 0, self::ENTRY_PAGES) as $path) {
            $pages[] = Param::page((string) $path, $context->titles()[$path] ?? null);
        }

        if ($pages === []) {
            return Message::of('Visits find the language switcher, but arrive on the wrong language: add hreflang links, put the translations in the sitemap, and offer %language% on entry pages (a suggestion, never a forced redirect).', $params);
        }

        return Message::of('Visits find the language switcher, but arrive on the wrong language: add hreflang links, put the translations in the sitemap, and offer %language% on the pages they switch on most: %pages% (a suggestion, never a forced redirect).', [
            ...$params,
            'pages' => Param::list($pages),
        ]);
    }
}
