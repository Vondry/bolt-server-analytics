<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Support;

use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;
use TomVondracek\RequestAnalytics\Collector\ChannelResolver;
use TomVondracek\RequestAnalytics\Collector\HitClassifier;
use TomVondracek\RequestAnalytics\Collector\UserAgentParser;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Diagnostics\FailureReporter;
use TomVondracek\RequestAnalytics\Diagnostics\Throttle;
use TomVondracek\RequestAnalytics\SiteTime;

/**
 * A throwaway project directory with an extension config, for tests that need
 * the services the way a Bolt project wires them.
 */
final class Project
{
    public readonly string $dir;
    public readonly MockClock $clock;
    public string $timezone = 'Europe/Prague';
    private ?ConfigLoader $configLoader = null;
    private ?SiteTime $siteTime = null;

    public function __construct(string $now = '2026-11-13 09:52:00')
    {
        $this->dir = sys_get_temp_dir() . '/request-analytics-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/config/extensions', 0o777, true);
        mkdir($this->dir . '/var/cache', 0o777, true);
        $this->clock = new MockClock($now, 'UTC');
    }

    public function remove(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    /**
     * Writes config/extensions/tomvondracek-requestanalytics.yaml.
     *
     * @param array<string, mixed> $config
     */
    public function configure(array $config): void
    {
        $file = $this->dir . '/config/extensions/' . ConfigLoader::CONFIG_BASENAME . '.yaml';
        file_put_contents($file, Yaml::dump($config, 4, 2));
        // Two writes within the same second must not look like the same file.
        touch($file, time() + random_int(1, 100_000));
        clearstatcache();
    }

    /**
     * The same project as a later request sees it: the services are built
     * anew, the files and the cache directory are shared.
     */
    public function withFreshServices(): self
    {
        $fresh = clone $this;
        $fresh->configLoader = null;
        $fresh->siteTime = null;

        return $fresh;
    }

    public function boltConfig(): FakeBoltConfig
    {
        return FakeBoltConfig::create([
            'extensions_config' => $this->dir . '/config/extensions',
        ], [
            'general' => [
                'timezone' => $this->timezone,
            ],
        ], $this->dir);
    }

    public function configLoader(): ConfigLoader
    {
        return $this->configLoader ??= new ConfigLoader($this->boltConfig(), $this->dir . '/var/cache', $this->dir);
    }

    public function siteTime(): SiteTime
    {
        return $this->siteTime ??= new SiteTime($this->boltConfig(), $this->clock);
    }

    public function classifier(): HitClassifier
    {
        $loader = $this->configLoader();

        return new HitClassifier($loader, new UserAgentParser($loader), new ChannelResolver(), $this->siteTime());
    }

    /**
     * Throttled within this project, like the reporter of a real one.
     */
    public function failureReporter(LoggerInterface $logger): FailureReporter
    {
        return new FailureReporter($logger, new Throttle($this->dir));
    }

    public function spoolDir(): string
    {
        return $this->dir . '/var/request-analytics/spool';
    }
}
