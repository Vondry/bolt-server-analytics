<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Support;

use Doctrine\DBAL\ParameterType;
use TomVondracek\RequestAnalytics\Aggregation\Aggregates;
use TomVondracek\RequestAnalytics\Aggregation\RollupService;
use TomVondracek\RequestAnalytics\Aggregation\RollupState;
use TomVondracek\RequestAnalytics\Aggregation\SpoolIngestor;
use TomVondracek\RequestAnalytics\Aggregation\StatisticsEraser;
use TomVondracek\RequestAnalytics\Aggregation\UpsertSqlBuilder;
use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Query\BreakdownQuery;
use TomVondracek\RequestAnalytics\Query\ContentGapsQuery;
use TomVondracek\RequestAnalytics\Query\FlowQuery;
use TomVondracek\RequestAnalytics\Query\PagesQuery;
use TomVondracek\RequestAnalytics\Query\SourcesQuery;
use TomVondracek\RequestAnalytics\Query\SummaryQuery;
use TomVondracek\RequestAnalytics\Query\TrafficQuery;
use TomVondracek\RequestAnalytics\Query\TrendQuery;
use TomVondracek\RequestAnalytics\Report\ContentGapsReport;
use TomVondracek\RequestAnalytics\Report\PageCatalogInterface;
use TomVondracek\RequestAnalytics\Report\PageFlowReport;
use TomVondracek\RequestAnalytics\Report\PagesReport;
use TomVondracek\RequestAnalytics\Report\SourcesReport;
use TomVondracek\RequestAnalytics\Report\SummaryReport;
use TomVondracek\RequestAnalytics\Report\TrafficReport;
use TomVondracek\RequestAnalytics\Storage\AnalyticsDatabase;
use TomVondracek\RequestAnalytics\Writer\HitWriterInterface;
use TomVondracek\RequestAnalytics\Writer\SpoolHitWriter;

/**
 * The cold path wired like in a project: project directory, database, rollup.
 */
final readonly class Pipeline
{
    public Project $project;
    public Database $database;

    public function __construct(
        ?Project $project = null,
        ?Database $database = null
    ) {
        $this->project = $project ?? new Project();
        $this->database = $database ?? new Database();
    }

    public function remove(): void
    {
        $this->project->remove();
    }

    /**
     * Puts hits straight into `ra_hit`, as if the rollup had moved them there
     * from the spool already; creates the tables on first use. Only for
     * tests: the collector itself only ever appends to the spool.
     */
    public function rawHitWriter(): HitWriterInterface
    {
        $database = $this->database;
        $tables = $this->analyticsDatabase();

        return new class($database, $tables) implements HitWriterInterface {
            public function __construct(
                private readonly Database $database,
                private readonly AnalyticsDatabase $tables,
            ) {
            }

            public function write(Hit $hit): void
            {
                $this->tables->withSchema(fn (): int|string => $this->database->connection->insert($this->database->tables->hit(), $hit->toRow(), Hit::TYPES));
            }
        };
    }

    /**
     * The tables of this pipeline's database, created on first use.
     */
    public function analyticsDatabase(): AnalyticsDatabase
    {
        return AnalyticsDatabase::fixed($this->database->connection, $this->database->prefix, $this->project->dir);
    }

    public function spoolWriter(): SpoolHitWriter
    {
        return new SpoolHitWriter($this->project->configLoader());
    }

    public function spoolIngestor(): SpoolIngestor
    {
        return new SpoolIngestor($this->project->configLoader(), $this->database->connection, $this->database->tables, $this->state());
    }

    public function state(): RollupState
    {
        return new RollupState($this->database->connection, $this->database->tables);
    }

    public function rollupService(): RollupService
    {
        return new RollupService(
            $this->project->configLoader(),
            $this->database->connection,
            $this->database->tables,
            new Aggregates($this->database->tables),
            new UpsertSqlBuilder(),
            $this->spoolIngestor(),
            $this->state(),
            $this->project->siteTime(),
            $this->project->dir,
        );
    }

    public function eraser(int $lockWaitMs = 0): StatisticsEraser
    {
        return new StatisticsEraser(
            $this->database->connection,
            $this->database->tables,
            new Aggregates($this->database->tables),
            $this->spoolIngestor(),
            $this->rollupService(),
            $this->state(),
            $this->project->siteTime(),
            $lockWaitMs,
        );
    }

    public function trendQuery(): TrendQuery
    {
        return new TrendQuery($this->database->connection, $this->database->tables, $this->project->siteTime());
    }

    public function breakdownQuery(): BreakdownQuery
    {
        return new BreakdownQuery($this->database->connection, $this->database->tables);
    }

    public function pagesReport(?PageCatalogInterface $catalog = null): PagesReport
    {
        return new PagesReport(new PagesQuery($this->database->connection, $this->database->tables), $catalog ?? new FakePageCatalog(), $this->summaryReport());
    }

    public function contentGapsReport(?PageCatalogInterface $catalog = null): ContentGapsReport
    {
        return new ContentGapsReport(new ContentGapsQuery($this->database->connection, $this->database->tables), $catalog ?? new FakePageCatalog(), $this->summaryReport());
    }

    /**
     * Adds page views of visitors of one language directly.
     */
    public function locales(string $day, string $lang, int $hits, bool $entry = false): void
    {
        $this->database->connection->insert($this->database->tables->locales(), [
            'day' => $day,
            'lang' => $lang,
            'is_entry' => $entry,
            'hits' => $hits,
        ], [
            'is_entry' => ParameterType::BOOLEAN,
        ]);
    }

    /**
     * Adds requests for a missing page directly; `$ref` a path of the site, a host or ''.
     */
    public function notFound(string $day, string $path, int $hits, string $ref = ''): void
    {
        $this->database->connection->insert($this->database->tables->notFound(), [
            'day' => $day,
            'path' => $path,
            'ref' => $ref,
            'hits' => $hits,
        ]);
    }

    public function pageFlowReport(?PageCatalogInterface $catalog = null): PageFlowReport
    {
        return new PageFlowReport(new FlowQuery($this->database->connection, $this->database->tables), $catalog ?? new FakePageCatalog(), $this->summaryReport());
    }

    public function trafficReport(): TrafficReport
    {
        return new TrafficReport(new TrafficQuery($this->database->connection, $this->database->tables), $this->summaryReport(), $this->project->siteTime());
    }

    /**
     * Adds moves from one page of the site to another directly.
     */
    public function transitions(string $day, string $from, string $to, int $hits): void
    {
        $this->database->connection->insert($this->database->tables->transitions(), [
            'day' => $day,
            'from_path' => $from,
            'to_path' => $to,
            'hits' => $hits,
        ]);
    }

    /**
     * Adds requests of a bot family directly.
     */
    public function bots(string $day, string $bot, int $hits): void
    {
        $this->database->connection->insert($this->database->tables->bots(), [
            'day' => $day,
            'bot' => $bot,
            'hits' => $hits,
        ]);
    }

    /**
     * Adds page views of one hour of a day directly.
     */
    public function hourly(string $day, int $hour, int $hits): void
    {
        $this->database->connection->insert($this->database->tables->hourly(), [
            'day' => $day,
            'hour' => $hour,
            'hits' => $hits,
        ]);
    }

    /**
     * Adds searches for a term directly.
     */
    public function search(string $day, string $term, int $hits): void
    {
        $this->database->connection->insert($this->database->tables->search(), [
            'day' => $day,
            'term' => $term,
            'hits' => $hits,
        ]);
    }

    public function summaryReport(): SummaryReport
    {
        return new SummaryReport(new SummaryQuery($this->database->connection, $this->database->tables), $this->project->siteTime());
    }

    public function sourcesReport(?PageCatalogInterface $catalog = null): SourcesReport
    {
        return new SourcesReport(new SourcesQuery($this->database->connection, $this->database->tables), $catalog ?? new FakePageCatalog(), $this->summaryReport());
    }

    /**
     * Adds a row of visits of one source and landing page directly.
     */
    public function sources(string $day, string $path, int $hits, string $channel, string $source = '', string $campaign = ''): void
    {
        $this->database->connection->insert($this->database->tables->sources(), [
            'day' => $day,
            'path' => $path,
            'channel' => $channel,
            'source' => $source,
            'campaign' => $campaign,
            'hits' => $hits,
        ]);
    }

    /**
     * Adds a row of visits through an alias or a marker directly.
     */
    public function aliases(string $day, string $via, string $path, int $hits, string $channel = 'direct', string $source = ''): void
    {
        $this->database->connection->insert($this->database->tables->aliases(), [
            'day' => $day,
            'via' => $via,
            'path' => $path,
            'channel' => $channel,
            'source' => $source,
            'hits' => $hits,
        ]);
    }

    /**
     * Adds a row of page views of one path directly.
     */
    public function pages(string $day, string $path, int $hits, bool $entry = false): void
    {
        $this->database->connection->insert($this->database->tables->pages(), [
            'day' => $day,
            'path' => $path,
            'is_entry' => $entry,
            'hits' => $hits,
        ], [
            'is_entry' => ParameterType::BOOLEAN,
        ]);
    }

    /**
     * Adds a row of page views per device, browser and OS directly.
     */
    public function clients(string $day, int $hits, string $device = 'desktop', string $browser = 'chrome', string $os = 'windows', bool $entry = false): void
    {
        $this->database->connection->insert($this->database->tables->clients(), [
            'day' => $day,
            'device' => $device,
            'browser' => $browser,
            'os' => $os,
            'is_entry' => $entry,
            'hits' => $hits,
        ], [
            'is_entry' => ParameterType::BOOLEAN,
        ]);
    }

    /**
     * Sets the daily totals directly.
     *
     * @param array<string, int|array{int, int}> $days day => page views, or [page views, entries]
     */
    public function daily(array $days): void
    {
        foreach ($days as $day => $values) {
            [$pageviews, $entries] = is_array($values) ? $values : [$values, 0];
            $this->database->connection->insert($this->database->tables->daily(), [
                'day' => $day,
                'pageviews' => $pageviews,
                'entries' => $entries,
            ]);
        }
    }
}
