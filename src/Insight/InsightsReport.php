<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Insight;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Throwable;
use TomVondracek\RequestAnalytics\Collector\Normalizer;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Query\DayRange;
use TomVondracek\RequestAnalytics\Report\PageCatalogInterface;
use TomVondracek\RequestAnalytics\SiteTime;

/**
 * Runs the rules, keeps out what a setup insight says cannot be trusted, and
 * ranks the rest: setup first, then by impact × weight (fix 3, grow 2,
 * decide 1).
 *
 * Computed while the backend renders the analytics page,
 * never through a route of its own. The result is cached on the server for
 * the day, keyed by the last complete day they cover, so it is computed again
 * once that day is in (the first rollup after midnight), not after every
 * rollup: the insights never look at today. It holds only the aggregated
 * numbers that are in the database anyway. Insights are not logged.
 *
 * The menu item, on every backend page, only asks how many insights ask for a
 * fix (fixes()): kept in the cache for an hour, so a backend page neither
 * reads the statistics nor computes insights, also when their database cannot
 * be reached.
 */
final readonly class InsightsReport
{
    /**
     * The insights card lists the first few; every card shows its own.
     */
    public const SHOWN = 5;

    private const CACHE_PREFIX = 'request_analytics.insights.';
    private const CACHE_SECONDS = 86_400;
    private const FIXES_CACHE_PREFIX = 'request_analytics.insight_fixes.';

    /**
     * How long the menu item keeps the number of fixes; after a failure only
     * FIXES_RETRY_SECONDS, so it is tried again soon, but not on every page.
     */
    private const FIXES_CACHE_SECONDS = 3600;

    private const FIXES_RETRY_SECONDS = 300;

    /**
     * Changes when an Insight changes shape, so a cached one is not read back.
     */
    private const CACHE_VERSION = 2;

    /**
     * @param iterable<InsightRule> $rules
     */
    public function __construct(
        #[AutowireIterator(InsightRule::TAG)]
        private iterable $rules,
        private InsightData $data,
        private PageCatalogInterface $catalog,
        private ConfigLoader $configLoader,
        private SiteTime $siteTime,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Every insight that fires, ranked; none when they cannot be computed
     * (no statistics yet, a broken config): the page works without them.
     *
     * @param string $host the host of the request: a Referer from it is this site
     *
     * @return list<Insight>
     */
    public function insights(string $host = ''): array
    {
        return $this->result($host)->insights;
    }

    /**
     * The insights, and whether their absence means "wait" or "all fine".
     * Without either when they cannot be computed (a broken config, a
     * failing rule).
     *
     * @param string $host the host of the request: a Referer from it is this site
     */
    public function result(string $host = ''): InsightsResult
    {
        try {
            $result = $this->compute($host);
        } catch (Throwable $exception) {
            $this->logger->warning('request-analytics: the insights could not be computed: {message}', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return new InsightsResult([], null);
        }

        // The menu item follows what the page shows.
        try {
            $this->cache->delete($this->fixesKey($host));
        } catch (Throwable) {
        }

        return $result;
    }

    /**
     * How many insights ask for a fix, for the menu item on every backend
     * page: from the cache, computed at most once an hour (and then mostly
     * from the cache of the insights). 0 when they cannot be computed, and not
     * logged: the analytics page logs it.
     *
     * @param string $host the host of the request: a Referer from it is this site
     */
    public function fixes(string $host = ''): int
    {
        try {
            return $this->cache->get($this->fixesKey($host), function (ItemInterface $item) use ($host): int {
                $item->expiresAfter(self::FIXES_CACHE_SECONDS);

                try {
                    return $this->compute($host)->fixes();
                } catch (Throwable) {
                    $item->expiresAfter(self::FIXES_RETRY_SECONDS);

                    return 0;
                }
            });
        } catch (Throwable) {
            return 0;
        }
    }

    private function fixesKey(string $host): string
    {
        return self::FIXES_CACHE_PREFIX . hash('xxh128', (string) json_encode([self::CACHE_VERSION, $this->siteTime->now()->format('Y-m-d'), Normalizer::host($host)]));
    }

    private function compute(string $host): InsightsResult
    {
        $lastRollupAt = $this->data->lastRollupAt();
        if ($lastRollupAt === null) {
            return new InsightsResult([], InsightsStatus::noStatistics());
        }

        $config = $this->configLoader->load();
        $today = $this->siteTime->now()->format('Y-m-d');
        $lastDay = $this->lastDay($today, $lastRollupAt);
        $ownHosts = self::hosts([$host, ...$config->ownHosts, (string) parse_url((string) $config->aliasTarget, PHP_URL_HOST)]);
        $key = self::CACHE_PREFIX . hash('xxh128', (string) json_encode([self::CACHE_VERSION, $today, $lastDay, $this->data->erased(), $ownHosts, $config->reportThresholdK]));

        /** @var InsightsResult */
        return $this->cache->get($key, function (ItemInterface $item) use ($lastDay, $config, $ownHosts): InsightsResult {
            $item->expiresAfter(self::CACHE_SECONDS);
            $context = new InsightContext($this->data, $this->catalog, $lastDay, $config->reportThresholdK, $ownHosts);

            return new InsightsResult($this->evaluate($context), InsightsStatus::of($context));
        });
    }

    /**
     * Rules that need no trusted data run first; a setup insight among their
     * findings keeps the rules that depend on that data from running. So do
     * days deleted on purpose in the windows of the change rules: their
     * totals are too low, though nothing is wrong.
     *
     * @return list<Insight> ranked
     */
    public function evaluate(InsightContext $context): array
    {
        $insights = [];
        $suppressed = $context->isErasedIn(DayRange::of($context->before->from, $context->recent->to)) ? [InsightContext::DATA_COUNTS] : [];
        $dependent = [];
        foreach ($this->rules as $rule) {
            if ($rule->dependsOn() !== []) {
                $dependent[] = $rule;

                continue;
            }

            foreach ($rule->evaluate($context) as $insight) {
                $insights[] = $insight;
                array_push($suppressed, ...$insight->suppresses);
            }
        }

        foreach ($dependent as $rule) {
            if (array_intersect($rule->dependsOn(), $suppressed) === []) {
                array_push($insights, ...$rule->evaluate($context));
            }
        }

        return self::rank($insights);
    }

    /**
     * @param list<Insight> $insights
     *
     * @return list<Insight>
     */
    public static function rank(array $insights): array
    {
        usort($insights, static fn (Insight $a, Insight $b): int => [$a->kind !== Insight::KIND_SETUP, -$a->score(), $a->id]
            <=> [$b->kind !== Insight::KIND_SETUP, -$b->score(), $b->id]);

        return $insights;
    }

    /**
     * Yesterday, or the day before the last rollup when it has not run since
     * midnight: the counts of that day are not complete yet.
     */
    private function lastDay(string $today, string $lastRollupAt): string
    {
        $zone = $this->siteTime->timezone();
        $yesterday = (new DateTimeImmutable($today, $zone))->modify('-1 day')->format('Y-m-d');

        try {
            $rollup = (new DateTimeImmutable($lastRollupAt, new DateTimeZone('UTC')))->setTimezone($zone);
        } catch (Throwable) {
            return $yesterday;
        }

        return min($yesterday, $rollup->modify('-1 day')->format('Y-m-d'));
    }

    /**
     * @param list<string> $hosts
     *
     * @return list<string> without `www.`, unique, sorted
     */
    private static function hosts(array $hosts): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(Normalizer::host(...), $hosts), static fn (string $host): bool => $host !== '')));
        sort($normalized);

        return $normalized;
    }
}
