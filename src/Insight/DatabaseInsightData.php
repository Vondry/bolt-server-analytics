<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Insight;

use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use TomVondracek\RequestAnalytics\Aggregation\RollupState;
use TomVondracek\RequestAnalytics\Query\BreakdownQuery;
use TomVondracek\RequestAnalytics\Query\ContentGapsQuery;
use TomVondracek\RequestAnalytics\Query\DayRange;
use TomVondracek\RequestAnalytics\Query\FlowQuery;
use TomVondracek\RequestAnalytics\Query\PagesQuery;
use TomVondracek\RequestAnalytics\Query\SourcesQuery;
use TomVondracek\RequestAnalytics\Query\SummaryQuery;

/**
 * The aggregates of the extension's tables, through the queries the cards of
 * the analytics page use.
 */
#[AsAlias(InsightData::class)]
final readonly class DatabaseInsightData implements InsightData
{
    public function __construct(
        private RollupState $state,
        private SummaryQuery $summary,
        private SourcesQuery $sources,
        private PagesQuery $pages,
        private BreakdownQuery $breakdown,
        private FlowQuery $flow,
        private ContentGapsQuery $gaps,
    ) {
    }

    public function lastRollupAt(): ?string
    {
        return $this->state->lastRollupAt();
    }

    public function firstDay(): ?string
    {
        return $this->summary->firstDay();
    }

    public function erased(): array
    {
        try {
            return $this->state->erased();
        } catch (InvalidFieldNameException) {
            // Before the schema update that adds the column (the next API
            // request makes it), nothing can have been recorded.
            return [];
        }
    }

    public function daily(DayRange $range): array
    {
        return $this->summary->daily($range);
    }

    public function sources(DayRange $range): array
    {
        return $this->sources->sources($range);
    }

    public function pages(DayRange $range): array
    {
        return self::merge(
            $this->pages->views('pageviews', $range->from, $range->to),
            $this->pages->views('entries', $range->from, $range->to),
        );
    }

    public function languages(DayRange $range): array
    {
        return self::merge(
            $this->pages->visitorLanguages('pageviews', $range->from, $range->to),
            $this->pages->visitorLanguages('entries', $range->from, $range->to),
        );
    }

    public function clients(DayRange $range): array
    {
        return $this->breakdown->daily($range);
    }

    public function movesInto(DayRange $range, string $prefix): array
    {
        return $this->flow->into($range, $prefix);
    }

    public function notFound(DayRange $range): array
    {
        return $this->gaps->notFound($range);
    }

    public function searches(DayRange $range): array
    {
        return $this->gaps->searches($range);
    }

    /**
     * @param array<string, int> $views
     * @param array<string, int> $entries
     *
     * @return array<string, array{views: int, entries: int}>
     */
    private static function merge(array $views, array $entries): array
    {
        $merged = [];
        foreach ($views as $key => $count) {
            $merged[(string) $key] = [
                'views' => $count,
                'entries' => $entries[$key] ?? 0,
            ];
        }

        // A visit is also a page view; none without one, but be safe.
        foreach ($entries as $key => $count) {
            $merged[(string) $key] ??= [
                'views' => $count,
                'entries' => $count,
            ];
        }

        return $merged;
    }
}
