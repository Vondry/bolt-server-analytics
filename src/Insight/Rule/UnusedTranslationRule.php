<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Insight\Rule;

use TomVondracek\RequestAnalytics\Insight\Insight;
use TomVondracek\RequestAnalytics\Insight\InsightContext;
use TomVondracek\RequestAnalytics\Insight\InsightRule;
use TomVondracek\RequestAnalytics\Insight\Message;
use TomVondracek\RequestAnalytics\Insight\Param;

/**
 * ⚖️ A translation nobody uses (90 days, from 1 000 page views): a language
 * of the site other than the default one has less than 1 % of the page views,
 * and less than 1 % of the visits prefer it. Several become one insight.
 *
 * Low impact by design: it comes after every fix and opportunity.
 */
final readonly class UnusedTranslationRule implements InsightRule
{
    private const MIN_VIEWS = 1000;
    private const MAX_SHARE = 0.01;

    public function dependsOn(): array
    {
        return [];
    }

    public function evaluate(InsightContext $context): array
    {
        if (! $context->coversStructural()) {
            return [];
        }

        $viewsByLanguage = [];
        foreach ($context->pages($context->structural) as $path => $counts) {
            [, $locale] = $context->split((string) $path);
            $language = InsightContext::language($locale);
            $viewsByLanguage[$language] = ($viewsByLanguage[$language] ?? 0) + $counts['views'];
        }

        $views = array_sum($viewsByLanguage);
        if ($views < self::MIN_VIEWS) {
            return [];
        }

        $languages = $context->languages($context->structural);
        $visits = array_sum(array_column($languages, 'entries'));
        $default = InsightContext::language($context->defaultLocale());

        $unused = [];
        foreach ($context->siteLanguages() as $language) {
            $viewed = $viewsByLanguage[$language] ?? 0;
            $preferring = $languages[$language]['entries'] ?? 0;
            if ($language !== $default && $viewed < self::MAX_SHARE * $views && ($visits <= 0 || $preferring < self::MAX_SHARE * $visits)) {
                $unused[$language] = [$viewed, $preferring];
            }
        }

        if ($unused === []) {
            return [];
        }

        $viewed = array_sum(array_column($unused, 0));
        $preferring = array_sum(array_column($unused, 1));
        $params = [
            'count' => Param::number($viewed),
            'share' => Param::percent($viewed / $views),
            'visit_share' => Param::percent($visits > 0 ? $preferring / $visits : 0.0),
        ];

        if (count($unused) === 1) {
            $message = Message::of('%language%: %count% page views in 90 days (%share%), and %visit_share% of visits prefer it.', [
                ...$params,
                'language' => Param::language((string) array_key_first($unused)),
            ]);
            $action = Message::of('Keeping it up to date may not pay off: translate only the key pages, stop translating new content, or remove the language.');
        } else {
            $message = Message::of('%languages% together: %count% page views in 90 days (%share%), and %visit_share% of visits prefer one of them.', [
                ...$params,
                'languages' => Param::list(array_map(static fn (int|string $language): Param => Param::language((string) $language), array_keys($unused))),
            ]);
            $action = Message::of('Keeping them up to date may not pay off: translate only the key pages, stop translating new content, or remove some languages.');
        }

        return [new Insight(
            id: 'unused-translation',
            section: Insight::SECTION_LANGUAGES,
            kind: Insight::KIND_DECIDE,
            impact: 0,
            message: $message,
            action: $action,
            days: InsightContext::STRUCTURAL_DAYS,
        )];
    }
}
