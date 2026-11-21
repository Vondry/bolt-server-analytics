<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Config;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Config\RequestAnalyticsConfig;

final class RequestAnalyticsConfigTest extends TestCase
{
    public function testEmptyConfigGivesTheDefaults(): void
    {
        $config = RequestAnalyticsConfig::fromArray([], '/project');

        self::assertTrue($config->enabled);
        self::assertSame([], $config->ownHosts);
        self::assertSame([], $config->aliasHosts);
        self::assertNull($config->aliasTarget);
        self::assertSame('count', $config->aliasMode);
        self::assertSame(301, $config->aliasRedirectStatus);
        self::assertSame('ra_via', $config->aliasParam);
        self::assertNull($config->excludePattern);
        self::assertSame([], $config->searchParams);
        self::assertTrue($config->countReloads);
        self::assertSame('/project/var/request-analytics/spool', $config->spoolDir);
        self::assertSame('/project/var/data/request-analytics.db', $config->storageDatabase, 'a SQLite file of its own');
        self::assertSame(0, $config->rawMaxDays, 'visits are kept until the rollup');
        self::assertSame(60, $config->visitIntervalMinutes);
        self::assertTrue($config->rollupOnVisit(), 'visits start a rollup by default');
        self::assertNull($config->rollupUrl, 'the rollup URL is off by default');
        self::assertNull($config->rollupToken);
        self::assertSame('admin', $config->aggregationTrigger);
        self::assertSame(1500, $config->timeBudgetMs);
        self::assertSame(25000, $config->chunkSize);
        self::assertSame('dashboard_aside_top', $config->widgetTarget);
        self::assertSame('30d', $config->widgetDefaultRange);
        self::assertSame('previous', $config->widgetCompare);
        self::assertSame('pageviews', $config->widgetMetric);
        self::assertTrue($config->honorGpc);
        self::assertTrue($config->honorDnt);
        self::assertSame('minimal', $config->optOutMode);
        self::assertSame(5, $config->reportThresholdK);
        self::assertSame(25, $config->retentionMonths);
        self::assertSame(['ROLE_ADMIN'], $config->viewRoles, 'administrators (and developers) see the statistics');
        self::assertSame(['ROLE_ADMIN'], $config->deleteRoles, 'and may delete them');
        self::assertTrue($config->rollupOnAdminVisit());
    }

    public function testAccessRolesAreConfigurable(): void
    {
        $config = RequestAnalyticsConfig::fromArray([
            'access' => [
                'view' => ['ROLE_ADMIN', 'ROLE_CHIEF_EDITOR'],
                'delete' => ['ROLE_DEVELOPER'],
            ],
        ], '/project');

        self::assertSame(['ROLE_ADMIN', 'ROLE_CHIEF_EDITOR'], $config->viewRoles);
        self::assertSame(['ROLE_DEVELOPER'], $config->deleteRoles);
        self::assertSame(['ROLE_ADMIN'], RequestAnalyticsConfig::fromArray([
            'access' => [
                'view' => [],
            ],
        ], '/project')->viewRoles, 'an empty list is the default, never nobody or everybody');
    }

    /**
     * Anything in the web root can be downloaded by anybody: the statistics
     * are refused there, however the path is written.
     */
    public function testStatisticsAreNeverInThePublicFolder(): void
    {
        $project = sys_get_temp_dir() . '/ra-public-' . bin2hex(random_bytes(4));
        mkdir($project . '/public/files', 0o777, true);
        mkdir($project . '/var');
        symlink($project . '/public/files', $project . '/var/link');

        try {
            foreach ([
                ['storage' => ['database' => '%kernel.project_dir%/public/stats.db']],
                ['storage' => ['database' => '%kernel.project_dir%/var/../public/files/stats.db']],
                ['storage' => ['database' => '%kernel.project_dir%/var/link/stats.db']],
                ['storage' => ['database' => 'sqlite:///%kernel.project_dir%/public/stats.db']],
                ['collector' => ['spool_dir' => '%kernel.project_dir%/public/spool']],
                ['collector' => ['spool_dir' => '%kernel.project_dir%/public']],
            ] as $config) {
                try {
                    RequestAnalyticsConfig::fromArray($config, $project);
                    self::fail('Accepted: ' . json_encode($config));
                } catch (InvalidArgumentException $exception) {
                    self::assertStringContainsString('must not be in the public folder of the site', $exception->getMessage());
                }
            }

            $_ENV['RA_TEST_DIR'] = $project . '/public';
            try {
                RequestAnalyticsConfig::fromArray(['storage' => ['database' => '%env(RA_TEST_DIR)%/stats.db']], $project);
                self::fail('Accepted from an environment variable.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('`storage.database` must not be in the public folder', $exception->getMessage());
            } finally {
                unset($_ENV['RA_TEST_DIR']);
            }

            // Bolt's public folder by another name (`bolt.public_folder`).
            $renamed = RequestAnalyticsConfig::validate(['storage' => ['database' => '%kernel.project_dir%/public/stats.db']], $project, publicFolder: 'public_html');
            self::assertSame($project . '/public/stats.db', RequestAnalyticsConfig::fromValidated($renamed)->storageDatabase);
            $this->expectExceptionMessage('must not be in the public folder');
            RequestAnalyticsConfig::fromValidated(RequestAnalyticsConfig::validate(['storage' => ['database' => '%kernel.project_dir%/public_html/stats.db']], $project, publicFolder: 'public_html'));
        } finally {
            unlink($project . '/var/link');
            rmdir($project . '/var');
            rmdir($project . '/public/files');
            rmdir($project . '/public');
            rmdir($project);
        }
    }

    /**
     * The file `extensions:configure --with-config` copies must be valid, and
     * say the same as the defaults in the code.
     */
    public function testVisitRollupsCanBeTurnedOff(): void
    {
        self::assertFalse(RequestAnalyticsConfig::fromArray([
            'aggregation' => [
                'visit_interval_minutes' => 0,
            ],
        ], '/project')->rollupOnVisit());
        self::assertFalse(RequestAnalyticsConfig::fromArray([
            'aggregation' => [
                'trigger' => 'cron',
            ],
        ], '/project')->rollupOnVisit(), '`cron` keeps rollups out of web requests');
        self::assertFalse(RequestAnalyticsConfig::fromArray([
            'enabled' => false,
        ], '/project')->rollupOnVisit());

        $this->expectException(InvalidArgumentException::class);
        RequestAnalyticsConfig::fromArray([
            'aggregation' => [
                'visit_interval_minutes' => 1441,
            ],
        ], '/project');
    }

    public function testRollupUrl(): void
    {
        $token = '3f9c2a7e1b4d8f60c5e9a2d7b1f4c8e03a6d9b2f';
        $config = RequestAnalyticsConfig::fromArray([
            'aggregation' => [
                'rollup_url' => '/_request-analytics/rollup/',
                'rollup_token' => $token,
            ],
        ], '/project');

        self::assertSame('/_request-analytics/rollup', $config->rollupUrl);
        self::assertSame($token, $config->rollupToken);
        self::assertNull(RequestAnalyticsConfig::fromArray([
            'aggregation' => [
                'rollup_url' => false,
            ],
        ], '/project')->rollupUrl);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidRollupUrls(): iterable
    {
        $token = '3f9c2a7e1b4d8f60c5e9a2d7b1f4c8e03a6d9b2f';

        yield 'no token' => [[
            'rollup_url' => '/_rollup',
        ], 'rollup_token'];
        yield 'short token' => [[
            'rollup_url' => '/_rollup',
            'rollup_token' => 'tooShortToken42',
        ], 'rollup_token'];
        yield 'no secret' => [[
            'rollup_url' => '/_rollup',
            'rollup_token' => str_repeat('abc', 14),
        ], 'rollup_token'];
        yield 'a number' => [[
            'rollup_url' => '/_rollup',
            'rollup_token' => 48213377482133774821337748213377,
        ], 'rollup_token'];
        yield 'not a path' => [[
            'rollup_url' => 'https://example.com/_rollup',
            'rollup_token' => $token,
        ], 'rollup_url'];
        yield 'the home page' => [[
            'rollup_url' => '/',
            'rollup_token' => $token,
        ], 'rollup_url'];
        yield 'a query' => [[
            'rollup_url' => '/_rollup?x=1',
            'rollup_token' => $token,
        ], 'rollup_url'];
        yield 'true' => [[
            'rollup_url' => true,
            'rollup_token' => $token,
        ], 'rollup_url'];
    }

    /**
     * @param array<string, mixed> $aggregation
     */
    #[DataProvider('invalidRollupUrls')]
    public function testInvalidRollupUrl(array $aggregation, string $key): void
    {
        try {
            RequestAnalyticsConfig::fromArray([
                'aggregation' => $aggregation,
            ], '/project');
            self::fail('The config was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('aggregation.' . $key, $exception->getMessage());
            self::assertStringNotContainsString('tooShortToken42', $exception->getMessage(), 'the token is not shown');
            self::assertStringNotContainsString('4821337', $exception->getMessage(), 'the token is not shown');
            self::assertStringNotContainsString('E+31', $exception->getMessage(), 'the token is not shown');
        }
    }

    public function testRollupTokenFromTheEnvironment(): void
    {
        $config = ['aggregation' => [
            'rollup_url' => '/_rollup',
            'rollup_token' => '%env(RA_TEST_ROLLUP_TOKEN)%',
        ]];

        try {
            $unset = RequestAnalyticsConfig::fromArray($config, '/project');
            self::assertNull($unset->rollupToken, 'not set: nobody gets in');
            self::assertCount(1, $unset->warnings, 'the dashboard says why the URL answers no call');

            $_ENV['RA_TEST_ROLLUP_TOKEN'] = 'Zq8xVt2LmR7nKp4WsY9cHd3JfB6gTe1u';
            self::assertSame('Zq8xVt2LmR7nKp4WsY9cHd3JfB6gTe1u', RequestAnalyticsConfig::fromArray($config, '/project')->rollupToken);

            // A wrong variable must not stop the statistics: the URL is off,
            // and the dashboard says why.
            $_ENV['RA_TEST_ROLLUP_TOKEN'] = str_repeat('ab', 20);
            $weak = RequestAnalyticsConfig::fromArray($config, '/project');
            self::assertNull($weak->rollupToken);
            self::assertCount(2, $weak->warnings, 'the token, and the URL that answers no call');
            self::assertStringNotContainsString('abab', implode(' ', $weak->warnings));
        } finally {
            unset($_ENV['RA_TEST_ROLLUP_TOKEN']);
        }
    }

    public function testShippedConfigMatchesTheDefaults(): void
    {
        $shipped = RequestAnalyticsConfig::fromArray(ConfigLoader::parseFiles([ConfigLoader::defaultConfigFile()]), '/project');
        $defaults = RequestAnalyticsConfig::fromArray([], '/project');

        $expected = get_object_vars($defaults);
        // The shipped file lists what a Bolt site wants to leave out.
        unset($expected['excludePattern'], $expected['searchParams']);
        $actual = get_object_vars($shipped);
        unset($actual['excludePattern'], $actual['searchParams']);

        self::assertSame($expected, $actual);
        self::assertSame(['searchTerm', 'q', 's', 'search'], $shipped->searchParams, "Bolt's own search sends searchTerm");
        self::assertTrue($shipped->isExcluded('/api/contents'));
        self::assertFalse($shipped->isExcluded('/apiary'), 'a page whose name starts like a technical path');
        self::assertFalse($shipped->isExcluded('/boltcms-meetup'), 'the backend is kept out by the collector itself, wherever it is');
        self::assertTrue($shipped->isExcluded('/theme/skeleton/css/app.CSS'));
        self::assertTrue($shipped->isExcluded('/robots.txt'));
        self::assertFalse($shipped->isExcluded('/'));
        self::assertFalse($shipped->isExcluded('/pages/about'));
    }

    public function testReadsTheWholeConfig(): void
    {
        $config = RequestAnalyticsConfig::fromArray([
            'enabled' => false,
            'own_hosts' => ['Rockfest-Kurim.cz', ' www.rockfest-kurim.cz ', 'rockfest-kurim.cz'],
            'aliases' => [
                'hosts' => ['RockfestKurim.cz.', 'poster-a2'],
                'target' => 'https://rockfest-kurim.cz/',
                'mode' => 'tag',
                'redirect_status' => 307,
                'param' => 'via',
            ],
            'exclude_paths' => ['^/admin', '\.pdf$'],
            'search_params' => ['q'],
            'count_reloads' => false,
            'collector' => [
                'spool_dir' => '/var/spool/ra/',
                'ua_cache' => 'none',
            ],
            'aggregation' => [
                'trigger' => 'cron',
                'time_budget_ms' => 500,
                'chunk_size' => 100,
            ],
            'widget' => [
                'target' => 'dashboard_aside_top',
                'default_range' => 'ytd',
                'compare' => 'none',
                'metric' => 'entries',
            ],
            'privacy' => [
                'honor_gpc' => false,
                'honor_dnt' => false,
                'opt_out_mode' => 'skip',
                'report_threshold_k' => 10,
                'retention_months' => 0,
                'raw_max_days' => 0,
            ],
        ], '/project');

        self::assertFalse($config->enabled);
        self::assertSame(['rockfest-kurim.cz', 'www.rockfest-kurim.cz'], $config->ownHosts);
        self::assertSame(['rockfestkurim.cz', 'poster-a2'], $config->aliasHosts);
        self::assertSame('https://rockfest-kurim.cz', $config->aliasTarget);
        self::assertSame('tag', $config->aliasMode);
        self::assertSame(307, $config->aliasRedirectStatus);
        self::assertSame('via', $config->aliasParam);
        self::assertTrue($config->isExcluded('/Admin/users'));
        self::assertTrue($config->isExcluded('/files/a.pdf'));
        self::assertFalse($config->isExcluded('/pdf'));
        self::assertSame(['q'], $config->searchParams);
        self::assertFalse($config->countReloads);
        self::assertSame('/var/spool/ra', $config->spoolDir);
        self::assertSame(0, $config->rawMaxDays);
        self::assertSame([], $config->warnings);
        self::assertSame('cron', $config->aggregationTrigger);
        self::assertFalse($config->rollupOnAdminVisit());
        self::assertSame(500, $config->timeBudgetMs);
        self::assertSame(100, $config->chunkSize);
        self::assertSame('dashboard_aside_top', $config->widgetTarget);
        self::assertSame('ytd', $config->widgetDefaultRange);
        self::assertSame('none', $config->widgetCompare);
        self::assertSame('entries', $config->widgetMetric);
        self::assertFalse($config->honorGpc);
        self::assertFalse($config->honorDnt);
        self::assertSame('skip', $config->optOutMode);
        self::assertSame(10, $config->reportThresholdK);
        self::assertSame(0, $config->retentionMonths);

        self::assertTrue($config->isAlias('rockfestkurim.cz'));
        self::assertFalse($config->isAlias('rockfest-kurim.cz'));
        self::assertTrue($config->isAliasMarker('poster-a2'));
        self::assertFalse($config->isAliasMarker('poster-a3'));
        self::assertTrue($config->isOwnHost('www.rockfest-kurim.cz'));
        self::assertFalse($config->isOwnHost('example.com'));
    }

    public function testEmptyValuesMeanTheDefault(): void
    {
        $config = RequestAnalyticsConfig::fromArray([
            'enabled' => null,
            'own_hosts' => null,
            'aliases' => null,
            'collector' => [
                'spool_dir' => '  ',
            ],
        ], '/project');

        self::assertTrue($config->enabled);
        self::assertSame([], $config->ownHosts);
        self::assertSame('/project/var/request-analytics/spool', $config->spoolDir);
    }

    public function testAliasesNeedATarget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The Request Analytics `aliases.target` must be the URL the alias hosts redirect to');

        RequestAnalyticsConfig::fromArray([
            'aliases' => [
                'hosts' => ['rockfestkurim.cz'],
            ],
        ], '/project');
    }

    public function testAliasWithoutHostsIsNoAlias(): void
    {
        $config = RequestAnalyticsConfig::fromArray([
            'aliases' => [
                'target' => 'https://example.com',
            ],
        ], '/project');

        self::assertFalse($config->isAlias('example.org'));
    }

    /**
     * A typo must not stop the statistics: the key is ignored and the dashboard
     * card names it.
     */
    public function testUnknownKeysAreWarningsNotErrors(): void
    {
        $config = RequestAnalyticsConfig::fromArray([
            'enabeld' => false,
            'aliases' => [
                'host' => ['a.example'],
            ],
        ], '/project');

        self::assertTrue($config->enabled);
        self::assertSame([
            'Unknown Request Analytics config key(s) "enabeld" are ignored; the known ones are "aliases", "storage", "collector", "aggregation", "widget", "privacy", "access", "enabled", "own_hosts", "exclude_paths", "route_templates", "search_params", "count_reloads". Check config/extensions/tomvondracek-requestanalytics.yaml.',
            'Unknown Request Analytics config key(s) "aliases.host" are ignored; the known ones in "aliases" are "hosts", "target", "param", "mode", "redirect_status". Check config/extensions/tomvondracek-requestanalytics.yaml.',
        ], $config->warnings);
    }

    /**
     * Besides a SQLite file, a MySQL / MariaDB or PostgreSQL database of the
     * statistics' own, by its URL.
     */
    public function testStorageCanBeADatabaseServer(): void
    {
        foreach ([
            'mysql://stats:secret@db.example:3306/bolt_stats?serverVersion=8.0',
            'mariadb://stats:secret@db.example/bolt_stats',
            'postgresql://stats:secret@127.0.0.1:5432/bolt_stats',
        ] as $url) {
            self::assertSame($url, RequestAnalyticsConfig::fromArray([
                'storage' => [
                    'database' => $url,
                ],
            ], '/project')->storageDatabase);
        }
    }

    /**
     * A relative path is below the project, not below the working directory
     * (`public/` for the web server, the project for the console).
     */
    public function testRelativePathsAreBelowTheProject(): void
    {
        $config = RequestAnalyticsConfig::fromArray([
            'storage' => [
                'database' => 'var/stats/analytics.db',
            ],
            'collector' => [
                'spool_dir' => './var/spool/',
            ],
        ], '/project');

        self::assertSame('/project/var/stats/analytics.db', $config->storageDatabase);
        self::assertSame('/project/./var/spool', $config->spoolDir);
        self::assertSame('/project/data/stats.db', RequestAnalyticsConfig::fromArray([
            'storage' => [
                'database' => 'sqlite:///data/stats.db',
            ],
        ], '/project')->storageDatabase, 'also a relative sqlite:// URL');
        self::assertSame('/srv/stats.db', RequestAnalyticsConfig::fromArray([
            'storage' => [
                'database' => '/srv/stats.db',
            ],
        ], '/project')->storageDatabase, 'an absolute path stays');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidStorages(): iterable
    {
        yield 'another database system' => ['oracle://stats:secret@db.example/stats', 'a URL of "oracle://" given'];
        yield 'no database name' => ['mysql://stats:secret@db.example', 'a URL that names the database'];
        yield 'not a URL' => ['mysql://', 'a valid database URL'];
        yield 'SQLite in memory' => ['sqlite:///:memory:', 'the path of a SQLite file'];
    }

    #[DataProvider('invalidStorages')]
    public function testStorageMustBeAFileOrADatabaseOfItsOwn(string $database, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        RequestAnalyticsConfig::fromArray([
            'storage' => [
                'database' => $database,
            ],
        ], '/project');
    }

    /**
     * A URL from the environment is only known at runtime: it is checked when
     * the database is opened (see AnalyticsDatabase), not cached as valid or not.
     */
    public function testStorageFromTheEnvironmentIsResolvedOnEveryLoad(): void
    {
        $validated = RequestAnalyticsConfig::validate([
            'storage' => [
                'database' => '%env(RA_TEST_STORAGE_URL)%',
            ],
        ], '/project');
        self::assertSame('%env(RA_TEST_STORAGE_URL)%', $validated['storageDatabase']);

        $_ENV['RA_TEST_STORAGE_URL'] = 'postgresql://stats:secret@db.example/bolt_stats';
        try {
            self::assertSame('postgresql://stats:secret@db.example/bolt_stats', RequestAnalyticsConfig::fromValidated($validated)->storageDatabase);
        } finally {
            unset($_ENV['RA_TEST_STORAGE_URL']);
        }
    }

    /**
     * The example config of request-analytics.md works as it is.
     */
    public function testConfigOfTheSpecificationIsValid(): void
    {
        $config = RequestAnalyticsConfig::fromArray([
            'enabled' => true,
            'own_hosts' => ['rockfest-kurim.cz', 'www.rockfest-kurim.cz'],
            'aliases' => [
                'hosts' => ['rockfestkurim.cz', 'www.rockfestkurim.cz'],
                'target' => 'https://rockfest-kurim.cz',
                'mode' => 'count',
                'redirect_status' => 301,
                'param' => 'ra_via',
            ],
            'exclude_paths' => ['^/bolt', '^/api', '^/_', '^/theme/', '^/files/', '^/thumbs/', '\.(css|js|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|txt|xml)$'],
            'route_templates' => true,
            'search_params' => ['q', 's', 'search'],
            'count_reloads' => true,
            'collector' => [
                'spool_dir' => '%kernel.project_dir%/var/request-analytics/spool',
                'ua_cache' => 'apcu',
            ],
            'aggregation' => [
                'trigger' => 'admin',
                'time_budget_ms' => 1500,
                'chunk_size' => 25000,
            ],
            'widget' => [
                'target' => 'dashboard_below_header',
                'default_range' => '30d',
                'compare' => 'previous',
                'metric' => 'pageviews',
            ],
            'privacy' => [
                'honor_gpc' => true,
                'honor_dnt' => true,
                'opt_out_mode' => 'minimal',
                'report_threshold_k' => 5,
                'retention_months' => 25,
            ],
        ], '/project');

        self::assertSame([], $config->warnings);
        self::assertTrue($config->routeTemplates);
    }

    public function testRouteTemplatesCanBeSwitchedOff(): void
    {
        self::assertTrue(RequestAnalyticsConfig::fromArray([], '/project')->routeTemplates);
        self::assertFalse(RequestAnalyticsConfig::fromArray([
            'route_templates' => false,
        ], '/project')->routeTemplates);
    }

    /**
     * What ConfigLoader caches holds no resolved `%env()%` values: they are
     * looked up whenever the config is built.
     */
    public function testValidatedArgumentsKeepEnvPlaceholders(): void
    {
        $arguments = RequestAnalyticsConfig::validate([
            'collector' => [
                'spool_dir' => '%env(RA_TEST_DIR)%/spool/',
            ],
            'storage' => [
                'database' => '%env(RA_TEST_DIR)%/stats.db',
            ],
        ], '/project');
        self::assertSame('%env(RA_TEST_DIR)%/spool/', $arguments['spoolDir']);

        $_ENV['RA_TEST_DIR'] = '/data';

        try {
            $first = RequestAnalyticsConfig::fromValidated($arguments);
            $_ENV['RA_TEST_DIR'] = '/srv';
            $second = RequestAnalyticsConfig::fromValidated($arguments);
        } finally {
            unset($_ENV['RA_TEST_DIR']);
        }

        self::assertSame(['/data/spool', '/srv/spool'], [$first->spoolDir, $second->spoolDir]);
        self::assertSame('/data/stats.db', $first->storageDatabase);
    }

    public function testBoltsDatabaseIsNotAStorage(): void
    {
        try {
            RequestAnalyticsConfig::fromArray([
                'storage' => [
                    'database' => 'main',
                ],
            ], '/project');
            self::fail('`main` (Bolt\'s database) was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString("never stored in Bolt's database", $exception->getMessage());
        }

        self::assertSame('/project/var/stats.db', RequestAnalyticsConfig::fromArray([
            'storage' => [
                'database' => '%kernel.project_dir%/var/stats.db',
            ],
        ], '/project')->storageDatabase);
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigs')]
    public function testInvalidConfigIsAnError(array $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        RequestAnalyticsConfig::fromArray($config, '/project');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'string for a boolean' => [[
            'enabled' => 'yes',
        ], "The Request Analytics `enabled` must be true or false, 'yes' given. Check config/extensions/tomvondracek-requestanalytics.yaml."];
        yield 'string for a list' => [[
            'own_hosts' => 'example.com',
        ], '`own_hosts` must be a list of strings'];
        yield 'mapping for a list' => [[
            'aliases' => [
                'hosts' => [
                    'main' => 'example.org',
                ],
            ],
        ], '`aliases.hosts` must be a list of strings'];
        yield 'list for a string' => [[
            'aliases' => [
                'target' => ['https://example.com'],
            ],
        ], '`aliases.target` must be a string, array given'];
        yield 'list with a number' => [[
            'search_params' => ['q', 5],
        ], '`search_params` must be a list of strings'];
        yield 'list for a section' => [[
            'aliases' => ['example.org'],
        ], '`aliases` must be a mapping'];
        yield 'unknown mode' => [[
            'aliases' => [
                'mode' => 'both',
            ],
        ], '`aliases.mode` must be one of count | tag'];
        yield 'unknown status' => [[
            'aliases' => [
                'redirect_status' => 303,
            ],
        ], '`aliases.redirect_status` must be one of 301 | 302 | 307 | 308, 303 given'];
        yield 'target without scheme' => [[
            'aliases' => [
                'hosts' => ['a.example'],
                'target' => 'example.com',
            ],
        ], '`aliases.target` must be an http(s) URL'];
        yield 'target is an alias' => [[
            'aliases' => [
                'hosts' => ['example.com'],
                'target' => 'https://Example.com',
            ],
        ], 'that would redirect forever'];
        yield 'invalid param' => [[
            'aliases' => [
                'param' => 'ra via',
            ],
        ], '`aliases.param` must be a query parameter name'];
        yield 'invalid regex' => [[
            'exclude_paths' => ['^/ok', '(unclosed'],
        ], "`exclude_paths` must be a list of valid regular expressions (without delimiters), '(unclosed' given"];
        yield 'negative budget' => [[
            'aggregation' => [
                'time_budget_ms' => -1,
            ],
        ], '`aggregation.time_budget_ms` must be a whole number from 0 to 600000'];
        yield 'chunk size of zero' => [[
            'aggregation' => [
                'chunk_size' => 0,
            ],
        ], '`aggregation.chunk_size` must be a whole number, 1 or more'];
        yield 'string for a number' => [[
            'privacy' => [
                'retention_months' => '25',
            ],
        ], "`privacy.retention_months` must be a whole number, 0 or more, '25' given"];
        yield 'role that is no role' => [[
            'access' => [
                'view' => ['IS_AUTHENTICATED_REMEMBERED'],
            ],
        ], "`access.view` must be a list of roles such as ROLE_ADMIN, 'IS_AUTHENTICATED_REMEMBERED' given"];
        yield 'lowercase role' => [[
            'access' => [
                'delete' => ['role_admin'],
            ],
        ], '`access.delete` must be a list of roles such as ROLE_ADMIN'];
        yield 'alias marker with markup' => [[
            'aliases' => [
                'hosts' => ['<b>poster</b>'],
                'target' => 'https://example.com',
            ],
        ], '`aliases.hosts` must be a list of host names or markers of letters, digits, dots and hyphens'];
        yield 'unknown widget target' => [[
            'widget' => [
                'target' => 'sidebar',
            ],
        ], '`widget.target` must be one of dashboard_below_header'];
    }
}
