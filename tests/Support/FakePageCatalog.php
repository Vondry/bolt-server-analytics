<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Support;

use TomVondracek\RequestAnalytics\Report\PageCatalogInterface;

/**
 * The pages of a site, as Bolt would list them.
 */
final readonly class FakePageCatalog implements PageCatalogInterface
{
    /**
     * @param list<string> $locales
     * @param array<string, string> $pages
     */
    public function __construct(
        private string $defaultLocale = 'en',
        private array $locales = ['en'],
        private array $pages = [],
    ) {
    }

    public function defaultLocale(): string
    {
        return $this->defaultLocale;
    }

    public function locales(): array
    {
        return $this->locales;
    }

    public function pages(): array
    {
        return $this->pages;
    }
}
