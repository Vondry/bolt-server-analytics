<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Config;

use Bolt\Configuration\Config;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Loads the extension config outside of the Extension object (listeners and
 * services can't use `BaseExtension::getConfig()`, which derives the filename
 * from the calling class's namespace).
 *
 * Same resolution as Bolt's `ConfigTrait`: the project's
 * `config/extensions/tomvondracek-requestanalytics.yaml`, overridden by
 * `…_local.yaml`, falling back to the defaults shipped with the package.
 *
 * The config is needed on every request (the alias listener runs before the
 * response), so the validated config (or what is wrong with it) is kept as a
 * PHP file in the cache directory, and the YAML is only parsed and checked
 * again when one of the files changes. `%env()%` values are resolved on every
 * load and never written to the cache.
 *
 * @phpstan-import-type Arguments from RequestAnalyticsConfig
 */
final class ConfigLoader
{
    public const CONFIG_BASENAME = 'tomvondracek-requestanalytics';
    private const CACHE_FILE = 'request-analytics-config.php';

    /**
     * Changes when the cached arguments change shape, e.g. after an update.
     */
    private const CACHE_VERSION = 12;

    private ?string $signature = null;
    private ?RequestAnalyticsConfig $config = null;
    private ?string $directory = null;

    public function __construct(
        private readonly Config $boltConfig,
        #[Autowire(param: 'kernel.cache_dir')]
        private readonly string $cacheDir,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        #[Autowire(param: 'bolt.public_folder')]
        private readonly string $publicFolder = RequestAnalyticsConfig::PUBLIC_FOLDER_DEFAULT,
    ) {
    }

    /**
     * @throws InvalidArgumentException when the config is not valid
     */
    public function load(): RequestAnalyticsConfig
    {
        $files = $this->files();
        $signature = self::signature($files);

        if ($this->config instanceof RequestAnalyticsConfig && $this->signature === $signature) {
            return $this->config;
        }

        $entry = $this->read();
        if ($entry !== null && $entry['signature'] === $signature) {
            $cached = $entry['config'];
        } else {
            try {
                $cached = RequestAnalyticsConfig::validate(self::parseFiles($files), $this->projectDir, publicFolder: $this->publicFolder);
            } catch (Throwable $exception) {
                // Also a broken config is checked only once, not on every request.
                $cached = $exception->getMessage();
            }

            $this->write($signature, $cached);
        }

        if (is_string($cached)) {
            throw new InvalidArgumentException($cached);
        }

        $this->config = RequestAnalyticsConfig::fromValidated($cached);
        $this->signature = $signature;

        return $this->config;
    }

    /**
     * @internal
     */
    public static function defaultConfigFile(): string
    {
        return dirname(__DIR__, 2) . '/config/config.yaml';
    }

    /**
     * Later files override earlier ones: single values and whole lists, while
     * mappings such as `aliases` are merged key by key.
     *
     * @internal
     *
     * @param list<string> $files
     *
     * @return array<array-key, mixed>
     */
    public static function parseFiles(array $files): array
    {
        $config = [];

        foreach ($files as $file) {
            if (! is_readable($file)) {
                continue;
            }

            $parsed = Yaml::parseFile($file);
            if (is_array($parsed)) {
                $config = self::merge($config, $parsed);
            }
        }

        return $config;
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $this->directory ??= $this->boltConfig->getPath('extensions_config');
        $main = $this->directory . DIRECTORY_SEPARATOR . self::CONFIG_BASENAME . '.yaml';
        $local = $this->directory . DIRECTORY_SEPARATOR . self::CONFIG_BASENAME . '_local.yaml';

        return [is_file($main) ? $main : self::defaultConfigFile(), $local];
    }

    /**
     * @param list<string> $files
     */
    private static function signature(array $files): string
    {
        $signature = '';
        foreach ($files as $file) {
            $signature .= $file . ':' . (file_exists($file) ? filemtime($file) . ':' . filesize($file) : '-') . '|';
        }

        return $signature;
    }

    /**
     * @return array{signature: string, config: Arguments|string}|null the cache entry: the validated config or the error; null when the cache is missing or of another version
     */
    private function read(): ?array
    {
        $file = $this->cacheDir . DIRECTORY_SEPARATOR . self::CACHE_FILE;
        if (! is_file($file)) {
            return null;
        }

        try {
            $cached = include $file;
        } catch (Throwable) {
            return null;
        }

        if (! is_array($cached) || ($cached['version'] ?? null) !== self::CACHE_VERSION || ! is_string($cached['signature'] ?? null)) {
            return null;
        }

        /** @var array{signature: string, config: Arguments|string} $cached written by write() */
        return $cached;
    }

    /**
     * A cache that can't be written (read-only filesystem) only costs the parsing.
     *
     * @param Arguments|string $config
     */
    private function write(string $signature, array|string $config): void
    {
        $file = $this->cacheDir . DIRECTORY_SEPARATOR . self::CACHE_FILE;
        $temporary = $file . '.' . bin2hex(random_bytes(4));
        $php = "<?php\n\nreturn " . var_export([
            'version' => self::CACHE_VERSION,
            'signature' => $signature,
            'config' => $config,
        ], true) . ";\n";

        if (! is_dir($this->cacheDir) || @file_put_contents($temporary, $php) === false) {
            return;
        }

        if (! @rename($temporary, $file)) {
            @unlink($temporary);

            return;
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $override
     *
     * @return array<array-key, mixed>
     */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $current = $base[$key] ?? null;
            $base[$key] = is_array($value) && is_array($current) && ! array_is_list($value) && ! array_is_list($current)
                ? self::merge($current, $value)
                : $value;
        }

        return $base;
    }
}
