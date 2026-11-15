<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Storage;

use Doctrine\ORM\Mapping\Table;
use ReflectionClass;
use RuntimeException;
use TomVondracek\RequestAnalytics\Entity\Alias;
use TomVondracek\RequestAnalytics\Entity\Bot;
use TomVondracek\RequestAnalytics\Entity\Client;
use TomVondracek\RequestAnalytics\Entity\Daily;
use TomVondracek\RequestAnalytics\Entity\Hit;
use TomVondracek\RequestAnalytics\Entity\Hourly;
use TomVondracek\RequestAnalytics\Entity\Locale;
use TomVondracek\RequestAnalytics\Entity\NotFound;
use TomVondracek\RequestAnalytics\Entity\Page;
use TomVondracek\RequestAnalytics\Entity\Search;
use TomVondracek\RequestAnalytics\Entity\Source;
use TomVondracek\RequestAnalytics\Entity\SpoolProgress;
use TomVondracek\RequestAnalytics\Entity\State;
use TomVondracek\RequestAnalytics\Entity\Transition;

/**
 * Names of the extension's tables as they exist in the database: the name of
 * the entity's `#[ORM\Table]`, with the prefix of the database (none in the
 * extension's own SQLite file; tests may use one). All reads and writes are
 * plain SQL.
 */
final class TableNames
{
    /**
     * Options of every table of the extension (`#[ORM\Table]`). On MySQL /
     * MariaDB: UTF-8 with all of Unicode, whatever the default of the database
     * (latin1 on many older servers would refuse any path or term outside of
     * it, and stop the rollup at that row), and binary comparison, as on
     * SQLite and PostgreSQL: `/Cafe` and `/café` are two pages. The other
     * platforms ignore them.
     */
    public const OPTIONS = [
        'charset' => self::MYSQL_CHARSET,
        'collation' => self::MYSQL_COLLATION,
    ];

    public const MYSQL_CHARSET = 'utf8mb4';
    public const MYSQL_COLLATION = 'utf8mb4_bin';

    /** @var array<class-string, string> */
    private array $names = [];

    public function __construct(
        private readonly ?AnalyticsDatabase $database = null,
        private ?string $prefix = null,
    ) {
    }

    public static function withPrefix(string $prefix): self
    {
        return new self(null, $prefix);
    }

    public function hit(): string
    {
        return $this->of(Hit::class);
    }

    public function state(): string
    {
        return $this->of(State::class);
    }

    public function spoolProgress(): string
    {
        return $this->of(SpoolProgress::class);
    }

    public function daily(): string
    {
        return $this->of(Daily::class);
    }

    public function hourly(): string
    {
        return $this->of(Hourly::class);
    }

    public function pages(): string
    {
        return $this->of(Page::class);
    }

    public function clients(): string
    {
        return $this->of(Client::class);
    }

    public function locales(): string
    {
        return $this->of(Locale::class);
    }

    public function sources(): string
    {
        return $this->of(Source::class);
    }

    public function transitions(): string
    {
        return $this->of(Transition::class);
    }

    public function search(): string
    {
        return $this->of(Search::class);
    }

    public function aliases(): string
    {
        return $this->of(Alias::class);
    }

    public function notFound(): string
    {
        return $this->of(NotFound::class);
    }

    public function bots(): string
    {
        return $this->of(Bot::class);
    }

    /**
     * @param class-string $entity
     */
    private function of(string $entity): string
    {
        return $this->names[$entity] ??= ($this->prefix ??= $this->database?->tablePrefix() ?? '') . self::baseName($entity);
    }

    /**
     * @param class-string $entity
     */
    private static function baseName(string $entity): string
    {
        $table = (new ReflectionClass($entity))->getAttributes(Table::class)[0] ?? null;
        $name = $table?->newInstance()->name;
        if (! is_string($name) || $name === '') {
            throw new RuntimeException(sprintf('%s has no table name.', $entity));
        }

        return $name;
    }
}
