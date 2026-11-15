<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Insight;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A question an editor or developer would act on, answered from the
 * aggregates. Most of the time a rule finds nothing.
 */
#[AutoconfigureTag(self::TAG)]
interface InsightRule
{
    public const TAG = 'request_analytics.insight_rule';

    /**
     * @return list<Insight> usually none
     */
    public function evaluate(InsightContext $context): array;

    /**
     * Data the rule relies on (InsightContext::DATA_*): it is not evaluated
     * while a setup insight says that data cannot be trusted.
     *
     * @return list<string>
     */
    public function dependsOn(): array;
}
