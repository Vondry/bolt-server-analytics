<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Support;

use Bolt\Configuration\Config;
use Illuminate\Support\Collection;
use ReflectionClass;

/**
 * Bolt's Config without its files and cache: just the paths and values the
 * extension asks for.
 */
final class FakeBoltConfig extends Config
{
    /** @var array<string, string> */
    private array $paths = [];

    private string $defaultPath = '/nowhere';

    /**
     * Skips the constructor of Bolt's Config, which parses the project's
     * config files.
     *
     * @param array<string, string> $paths
     * @param array<string, mixed> $values nested like the YAML files: `general/timezone` is `['general' => ['timezone' => …]]`
     */
    public static function create(array $paths = [], array $values = [], string $defaultPath = '/nowhere'): self
    {
        $config = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $config->paths = $paths;
        $config->data = new Collection($values);
        $config->defaultPath = $defaultPath;

        return $config;
    }

    public function getPath(string $path, bool $absolute = true, mixed $additional = null): string
    {
        return $this->paths[$path] ?? $this->defaultPath;
    }
}
