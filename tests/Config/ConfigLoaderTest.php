<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Tests\Support\Project;

final class ConfigLoaderTest extends TestCase
{
    private Project $project;
    private string $main;
    private string $local;

    protected function setUp(): void
    {
        $this->project = new Project();
        $this->main = $this->project->dir . '/config/extensions/tomvondracek-requestanalytics.yaml';
        $this->local = $this->project->dir . '/config/extensions/tomvondracek-requestanalytics_local.yaml';
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testFallsBackToShippedDefaults(): void
    {
        $config = $this->project->configLoader()
            ->load();

        self::assertSame(['searchTerm', 'q', 's', 'search'], $config->searchParams);
        self::assertSame($this->project->dir . '/var/request-analytics/spool', $config->spoolDir);
    }

    public function testProjectConfigAndLocalOverride(): void
    {
        file_put_contents($this->main, "search_params: [q]\naliases:\n  hosts: [a.example]\n  target: https://example.com\n");
        file_put_contents($this->local, "aliases:\n  mode: tag\ncollector:\n  ua_cache: none\n");

        $config = $this->project->configLoader()
            ->load();

        self::assertSame(['q'], $config->searchParams);
        self::assertSame(['a.example'], $config->aliasHosts, 'mappings are merged key by key');
        self::assertSame('tag', $config->aliasMode);
        self::assertSame([], $config->warnings, 'ua_cache of version 1.0 is still a known key');
    }

    public function testLocalOverrideReplacesLists(): void
    {
        file_put_contents($this->main, "own_hosts: [a.example, b.example]\n");
        file_put_contents($this->local, "own_hosts: [c.example]\n");

        self::assertSame(['c.example'], $this->project->configLoader()->load()->ownHosts);
    }

    public function testLocalOverrideAppliesOnTopOfDefaults(): void
    {
        file_put_contents($this->local, "enabled: false\n");

        $config = $this->project->configLoader()
            ->load();

        self::assertFalse($config->enabled);
        self::assertSame(['searchTerm', 'q', 's', 'search'], $config->searchParams);
    }

    public function testLoadsOnceAndReadsChangesOnTheNextLoad(): void
    {
        $loader = $this->project->configLoader();
        $first = $loader->load();

        self::assertSame($first, $loader->load(), 'unchanged files give the same object');

        $this->project->configure([
            'enabled' => false,
        ]);

        self::assertFalse($loader->load()->enabled);
    }

    public function testParsedConfigIsCachedAsPhp(): void
    {
        $this->project->configure([
            'search_params' => ['hledat'],
        ]);
        $this->project->configLoader()
            ->load();

        $cache = $this->project->dir . '/var/cache/request-analytics-config.php';
        self::assertFileExists($cache);

        // A second process reads the validated config from the cache, not the
        // YAML: prove it with a cache that says something else for the same files.
        $cached = include $cache;
        self::assertIsArray($cached);
        self::assertIsArray($cached['config']);
        self::assertSame(['hledat'], $cached['config']['searchParams']);
        $cached['config']['searchParams'] = ['from-cache'];
        file_put_contents($cache, '<?php return ' . var_export($cached, true) . ';');
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($cache, true);
        }

        $fresh = new ConfigLoader($this->project->boltConfig(), $this->project->dir . '/var/cache', $this->project->dir);
        self::assertSame(['from-cache'], $fresh->load()->searchParams);

        // A changed file is parsed again.
        $this->project->configure([
            'search_params' => ['q'],
        ]);
        self::assertSame(['q'], $fresh->load()->searchParams);
    }

    public function testWorksWithoutAWritableCache(): void
    {
        $this->project->configure([
            'search_params' => ['q'],
        ]);
        $loader = new ConfigLoader($this->project->boltConfig(), $this->project->dir . '/var/cache/missing', $this->project->dir);

        self::assertSame(['q'], $loader->load()->searchParams);
        self::assertDirectoryDoesNotExist($this->project->dir . '/var/cache/missing');
    }

    public function testBrokenCacheFileIsIgnored(): void
    {
        file_put_contents($this->project->dir . '/var/cache/request-analytics-config.php', '<?php return "nonsense";');

        self::assertTrue($this->project->configLoader()->load()->enabled);
    }

    public function testMalformedYamlIsAnError(): void
    {
        file_put_contents($this->main, "own_hosts: [\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed inline YAML string');

        $this->project->configLoader()
            ->load();
    }

    /**
     * The alias listener loads the config before every response: a broken
     * config must not be parsed and checked on every request either.
     */
    public function testInvalidConfigIsCheckedOnlyOnceUntilTheFileChanges(): void
    {
        file_put_contents($this->main, "enabled: maybe\n");
        $mtime = (int) filemtime($this->main);

        try {
            $this->project->configLoader()
                ->load();
            self::fail('The invalid config was accepted.');
        } catch (InvalidArgumentException) {
        }

        // Valid content of the same size and time: only a new parse would see it.
        file_put_contents($this->main, "enabled: false\n");
        touch($this->main, $mtime);
        clearstatcache();

        $fresh = new ConfigLoader($this->project->boltConfig(), $this->project->dir . '/var/cache', $this->project->dir);
        try {
            $fresh->load();
            self::fail('The cached error was not used.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('`enabled` must be true or false', $exception->getMessage());
        }

        touch($this->main, $mtime + 10);
        clearstatcache();
        self::assertFalse($fresh->load()->enabled, 'parsed again once the file changed');
    }

    public function testEnvironmentValuesAreNotWrittenToTheCache(): void
    {
        $this->project->configure([
            'collector' => [
                'spool_dir' => '%env(RA_TEST_LOADER_DIR)%/spool',
            ],
        ]);
        $_ENV['RA_TEST_LOADER_DIR'] = '/very/private';

        try {
            self::assertSame('/very/private/spool', $this->project->configLoader()->load()->spoolDir);
            $_ENV['RA_TEST_LOADER_DIR'] = '/moved';
            $fresh = new ConfigLoader($this->project->boltConfig(), $this->project->dir . '/var/cache', $this->project->dir);
            self::assertSame('/moved/spool', $fresh->load()->spoolDir, 'resolved on every load');
        } finally {
            unset($_ENV['RA_TEST_LOADER_DIR']);
        }

        $cache = (string) file_get_contents($this->project->dir . '/var/cache/request-analytics-config.php');
        self::assertStringContainsString('%env(RA_TEST_LOADER_DIR)%', $cache);
        self::assertStringNotContainsString('/very/private', $cache);
    }

    public function testCacheOfAnOlderVersionIsIgnored(): void
    {
        file_put_contents($this->project->dir . '/var/cache/request-analytics-config.php', '<?php return ' . var_export([
            'signature' => 'anything',
            'config' => [
                'search_params' => ['q'],
            ],
        ], true) . ';');

        self::assertTrue($this->project->configLoader()->load()->enabled);
    }

    public function testInvalidConfigIsAnErrorOnEveryLoad(): void
    {
        file_put_contents($this->main, "enabled: maybe\n");
        $loader = $this->project->configLoader();

        foreach ([1, 2] as $attempt) {
            try {
                $loader->load();
                self::fail('The invalid config was accepted on load ' . $attempt);
            } catch (InvalidArgumentException $exception) {
                self::assertStringContainsString('`enabled` must be true or false', $exception->getMessage());
            }
        }
    }

    public function testParseFilesSkipsMissingAndNonArrayFiles(): void
    {
        file_put_contents($this->project->dir . '/scalar.yaml', "just a string\n");
        file_put_contents($this->project->dir . '/a.yaml', "a: 1\nb: {x: 1, y: 1}\n");
        file_put_contents($this->project->dir . '/b.yaml', "b: {y: 2}\n");

        self::assertSame(
            [
                'a' => 1,
                'b' => [
                    'x' => 1,
                    'y' => 2,
                ],
            ],
            ConfigLoader::parseFiles([$this->project->dir . '/missing.yaml', $this->project->dir . '/scalar.yaml', $this->project->dir . '/a.yaml', $this->project->dir . '/b.yaml'])
        );
    }
}
