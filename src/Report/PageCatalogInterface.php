<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Report;

/**
 * What the site itself knows about its pages: which ones are published, what
 * they are called, and in which languages a page can be viewed.
 */
interface PageCatalogInterface
{
    /**
     * The language of a path without a language prefix (`/page/about`).
     */
    public function defaultLocale(): string;

    /**
     * Codes that can prefix a path (`/nl/page/about`), the default included.
     *
     * @return list<string>
     */
    public function locales(): array;

    /**
     * Published pages, by their path in the default language: records with a
     * page of their own and the listings of their content types.
     *
     * @return array<string, string> path => title
     */
    public function pages(): array;
}
