<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Insight;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use TomVondracek\RequestAnalytics\Insight\Insight;
use TomVondracek\RequestAnalytics\Insight\InsightContext;
use TomVondracek\RequestAnalytics\Insight\InsightRule;
use TomVondracek\RequestAnalytics\Insight\InsightsReport;
use TomVondracek\RequestAnalytics\Insight\InsightsStatus;
use TomVondracek\RequestAnalytics\Insight\Message;
use TomVondracek\RequestAnalytics\Insight\Param;
use TomVondracek\RequestAnalytics\Tests\Support\FakePageCatalog;
use TomVondracek\RequestAnalytics\Tests\Support\Insights;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryInsightData;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryLogger;
use TomVondracek\RequestAnalytics\Tests\Support\Project;

final class InsightsReportTest extends TestCase
{
    private Project $project;
    private MemoryInsightData $data;
    private ArrayAdapter $cache;
    private MemoryLogger $logger;

    protected function setUp(): void
    {
        // 13 Nov 2026, 09:52 in Prague.
        $this->project = new Project();
        $this->data = new MemoryInsightData();
        $this->cache = new ArrayAdapter();
        $this->logger = new MemoryLogger();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testSetupFirstThenByImpactTimesWeight(): void
    {
        $rule = self::rule([
            self::insight('decide', Insight::KIND_DECIDE, 1000),
            self::insight('grow', Insight::KIND_GROW, 100),
            self::insight('fix', Insight::KIND_FIX, 70),
            self::insight('setup', Insight::KIND_SETUP, 1),
        ]);

        self::assertSame(['setup', 'decide', 'fix', 'grow'], Insights::ids($this->report([$rule])->insights()), '1000 × 1, 70 × 3, 100 × 2');
    }

    /**
     * While a setup insight says the counts cannot be trusted, the rules that
     * rely on them are not even run.
     */
    public function testUntrustedDataSuppressesTheRulesThatDependOnIt(): void
    {
        $trust = self::rule([self::insight('tracking-gap', Insight::KIND_SETUP, 1, [InsightContext::DATA_COUNTS])]);
        $drop = self::rule([self::insight('visit-drop', Insight::KIND_FIX, 500)], [InsightContext::DATA_COUNTS, InsightContext::DATA_REFERRERS]);
        $languages = self::rule([self::insight('missing-language:en', Insight::KIND_GROW, 300)], [InsightContext::DATA_REFERRERS]);

        // The dependent rule comes first: the order of the rules does not matter.
        self::assertSame(['tracking-gap', 'missing-language:en'], Insights::ids($this->report([$drop, $languages, $trust])->insights()));
        self::assertSame(0, $drop->runs);
    }

    /**
     * Days deleted on purpose make the totals of their windows too low: the
     * change rules wait until the deleted days are out of their 56 days,
     * without an insight about it.
     */
    public function testDeletedDaysSuppressTheChangeRules(): void
    {
        $drop = self::rule([self::insight('visit-drop', Insight::KIND_FIX, 500)], [InsightContext::DATA_COUNTS, InsightContext::DATA_REFERRERS]);
        $languages = self::rule([self::insight('missing-language:en', Insight::KIND_GROW, 300)], [InsightContext::DATA_REFERRERS]);

        $this->data->erased = [[
            'from' => '2026-11-06',
            'to' => '2026-11-13',
        ]];
        self::assertSame(['missing-language:en'], Insights::ids($this->report([$drop, $languages])->evaluate(Insights::context($this->data))));

        $this->data->erased = [[
            'from' => '2026-08-01',
            'to' => Insights::BEFORE_FROM,
        ]];
        self::assertSame(['missing-language:en'], Insights::ids($this->report([$drop, $languages])->evaluate(Insights::context($this->data))), 'the first day of the 56');

        $this->data->erased = [[
            'from' => '2026-08-01',
            'to' => '2026-09-17',
        ]];
        self::assertSame(['visit-drop', 'missing-language:en'], Insights::ids($this->report([$drop, $languages])->evaluate(Insights::context($this->data))), 'before the 56 days');
    }

    /**
     * Computed once a day, when the rollup has completed another day: the
     * cache holds them, as plain data. A rollup of today alone changes no
     * insight (they never look at today), so it is no reason to compute them.
     */
    public function testCachedUntilAnotherDayIsComplete(): void
    {
        $rule = self::rule([self::insight('fix', Insight::KIND_FIX, 70)]);
        $report = $this->report([$rule]);

        $first = $report->insights('example.com');
        self::assertEquals($first, $report->insights('example.com'));
        self::assertSame(1, $rule->runs);

        $this->data->lastRollupAt = '2026-11-13 08:30:00';
        $report->insights('example.com');
        self::assertSame(1, $rule->runs, 'another rollup of the same day');

        // Before the first rollup after midnight (Prague), the 11th was the last complete day.
        $this->data->lastRollupAt = '2026-11-11 22:30:00';
        $report->insights('example.com');
        self::assertSame(2, $rule->runs, 'another last complete day');

        $report->insights('www.example.org');
        self::assertSame(3, $rule->runs, 'another host of the site is another referral check');

        $this->data->erased = [[
            'from' => '2026-11-07',
            'to' => '2026-11-13',
        ]];
        $report->insights('www.example.org');
        self::assertSame(4, $rule->runs, 'deleted statistics, before the next rollup');
    }

    /**
     * The menu item asks on every backend page: from the cache, without
     * reading the statistics, until the analytics page computes them again.
     */
    public function testTheMenuReadsTheFixesFromTheCache(): void
    {
        $rule = self::rule([self::insight('fix-a', Insight::KIND_FIX, 70), self::insight('fix-b', Insight::KIND_FIX, 60)]);
        $report = $this->report([$rule]);

        self::assertSame(2, $report->fixes('example.com'));
        $this->data->lastRollupAt = null;
        $this->data->failure = new RuntimeException('Not to be read.');

        self::assertSame(2, $report->fixes('example.com'), 'from the cache, no statistics read');

        $this->data->failure = null;

        $report->result('example.com');
        self::assertSame(0, $report->fixes('example.com'), 'the page renewed it');
    }

    /**
     * A failure (the database cannot be reached) is not tried again on every
     * backend page.
     */
    public function testAFailureOfTheMenuIsRememberedForAWhile(): void
    {
        $rule = self::rule([self::insight('fix-a', Insight::KIND_FIX, 70), self::insight('fix-b', Insight::KIND_FIX, 60)]);
        $this->data->failure = new RuntimeException('Connection timed out');
        $report = $this->report([$rule]);

        self::assertSame(0, $report->fixes('example.com'));
        $this->data->failure = null;
        self::assertSame(0, $report->fixes('example.com'), 'not tried again at once');
        self::assertSame(0, $rule->runs);
    }

    /**
     * Today is never included, nor a day the rollup has not finished: before
     * the first rollup after midnight, the last complete day is the day
     * before yesterday.
     */
    public function testTheLastCompleteDay(): void
    {
        $rule = self::rule([]);

        $this->data->lastRollupAt = '2026-11-13 08:00:00';
        $this->report([$rule])->insights();
        self::assertSame('2026-11-12', $rule->context?->lastDay);

        // 22:30 UTC is 23:30 in Prague on the 11th.
        $this->data->lastRollupAt = '2026-11-11 22:30:00';
        $this->report([$rule])->insights();
        self::assertSame('2026-11-10', $rule->context?->lastDay);

        // 23:30 UTC is already the 12th in Prague.
        $this->data->lastRollupAt = '2026-11-11 23:30:00';
        $this->report([$rule])->insights();
        self::assertSame('2026-11-11', $rule->context?->lastDay);
    }

    public function testTheContextKnowsTheHostsOfTheSiteAndTheThreshold(): void
    {
        $this->project->configure([
            'own_hosts' => ['WWW.Example.org'],
            'aliases' => [
                'hosts' => ['example.net'],
                'target' => 'https://www.example.com',
            ],
            'privacy' => [
                'report_threshold_k' => 7,
            ],
        ]);
        $rule = self::rule([]);

        $this->report([$rule])->insights('admin.example.cz');

        self::assertSame(['admin.example.cz', 'example.com', 'example.org'], $rule->context?->ownHosts);
        self::assertSame(7, $rule->context->threshold);
    }

    public function testNothingBeforeTheFirstRollup(): void
    {
        $rule = self::rule([self::insight('fix', Insight::KIND_FIX, 70)]);
        $this->data->lastRollupAt = null;

        self::assertSame([], $this->report([$rule])->insights());
        self::assertSame(0, $rule->runs);
        self::assertSame(InsightsStatus::WAITING, $this->report([$rule])->result()->status?->state, 'the card says to wait');
    }

    public function testTheStatusIsCachedWithTheInsights(): void
    {
        $this->data->everyDay(Insights::structural(), 100, 50);
        $report = $this->report([self::rule([])]);

        $first = $report->result();
        $this->data->daily = [];
        $second = $report->result();

        self::assertSame(InsightsStatus::CLEAR, $first->status?->state);
        self::assertSame(InsightsStatus::CLEAR, $second->status?->state, 'until the next rollup');
    }

    /**
     * The page works without insights; what went wrong is logged, never the
     * insights themselves.
     */
    public function testAFailingRuleCostsTheInsightsNotThePage(): void
    {
        $failing = new class() implements InsightRule {
            public function evaluate(InsightContext $context): array
            {
                throw new RuntimeException('The database is gone.');
            }

            public function dependsOn(): array
            {
                return [];
            }
        };

        self::assertSame([], $this->report([$failing])->insights());
        self::assertSame([[
            'level' => 'warning',
            'message' => 'request-analytics: the insights could not be computed: The database is gone.',
        ]], $this->logger->records);

        $this->report([$failing])->insights();
        self::assertCount(2, $this->logger->records, 'a failure is not cached: the next render tries again');
        self::assertNull($this->report([$failing])->result()->status, 'no claim that all is fine');
    }

    /**
     * Each aggregate is read once, however many rules ask for it.
     */
    public function testEveryAggregateIsReadOnce(): void
    {
        $this->data->everyDay(Insights::structural(), 100, 50);
        $context = Insights::context($this->data, new FakePageCatalog());
        $context->totals($context->recent);
        $context->totals($context->recent);
        $context->daily($context->recent);
        $context->coversRecent();
        $context->coversStructural();

        self::assertSame([
            'daily' => 1,
            'firstDay' => 1,
        ], $this->data->reads);
    }

    /**
     * @param list<InsightRule> $rules
     */
    private function report(array $rules): InsightsReport
    {
        return new InsightsReport($rules, $this->data, new FakePageCatalog(), $this->project->configLoader(), $this->project->siteTime(), $this->cache, $this->logger);
    }

    /**
     * @param list<string> $suppresses
     */
    private static function insight(string $id, string $kind, float $impact, array $suppresses = []): Insight
    {
        return new Insight($id, Insight::SECTION_SOURCES, $kind, $impact, Message::of('%count% searches for %term%, and no page is about it.', [
            'count' => Param::number(20),
            'term' => Param::term($id),
        ]), suppresses: $suppresses);
    }

    /**
     * @param list<Insight> $insights
     * @param list<string> $dependsOn
     *
     * @return InsightRule&object{runs: int, context: ?InsightContext}
     */
    private static function rule(array $insights, array $dependsOn = []): InsightRule
    {
        return new class($insights, $dependsOn) implements InsightRule {
            public int $runs = 0;
            public ?InsightContext $context = null;

            /**
             * @param list<Insight> $insights
             * @param list<string> $dependsOn
             */
            public function __construct(
                private readonly array $insights,
                private readonly array $dependsOn,
            ) {
            }

            public function evaluate(InsightContext $context): array
            {
                ++$this->runs;
                $this->context = $context;

                return $this->insights;
            }

            public function dependsOn(): array
            {
                return $this->dependsOn;
            }
        };
    }
}
