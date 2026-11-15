<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Insight;

/**
 * The insights that fire, ranked, and what the card says when none does.
 */
final readonly class InsightsResult
{
    /**
     * @param list<Insight> $insights
     * @param ?InsightsStatus $status null when the insights could not be computed
     */
    public function __construct(
        public array $insights,
        public ?InsightsStatus $status,
    ) {
    }

    /**
     * The insights that ask for a fix: something broken (fix), or tracking
     * that cannot be trusted (setup).
     */
    public function fixes(): int
    {
        return count(array_filter($this->insights, static fn (Insight $insight): bool => in_array($insight->kind, [Insight::KIND_FIX, Insight::KIND_SETUP], true)));
    }
}
