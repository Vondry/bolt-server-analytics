<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Report;

use Bolt\Configuration\Config;
use Bolt\Configuration\Content\ContentType;
use Bolt\Entity\Content;
use Bolt\Repository\ContentRepository;
use Bolt\Twig\ContentExtension;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Throwable;

/**
 * The pages of a Bolt site: every published record that has a page (its link
 * in the default language, titled like Bolt titles it) and the listing of
 * every content type that has pages.
 *
 * Loading every record is not free on a big site, so the list is cached for
 * a few minutes (a page published meanwhile shows up a little later), content
 * types without pages (`viewless`) are not loaded at all, and at most
 * MAX_RECORDS records are, the newest first: beyond that, a page gets no title
 * (its path stands in) and is not listed among the unviewed ones.
 */
#[AsAlias(PageCatalogInterface::class)]
final readonly class BoltPageCatalog implements PageCatalogInterface
{
    private const CACHE_KEY = 'request_analytics.page_catalog';
    private const CACHE_SECONDS = 600;
    private const CACHE_SECONDS_AFTER_FAILURE = 60;
    public const MAX_RECORDS = 5000;
    private const BATCH = 500;

    /**
     * @param list<mixed> $locales `locales_array`: the codes Bolt accepts as `{_locale}` in a URL
     */
    public function __construct(
        private Config $config,
        private ContentRepository $contentRepository,
        private ContentExtension $contentExtension,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        #[Autowire(param: 'kernel.default_locale')]
        private string $defaultLocale,
        #[Autowire(param: 'locales_array')]
        private array $locales,
        private int $maxRecords = self::MAX_RECORDS,
    ) {
    }

    public function defaultLocale(): string
    {
        return $this->defaultLocale;
    }

    public function locales(): array
    {
        $locales = array_filter($this->locales, static fn (mixed $code): bool => is_string($code) && $code !== '');

        return array_values(array_unique([$this->defaultLocale, ...$locales]));
    }

    public function pages(): array
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_SECONDS);

            try {
                return $this->collect();
            } catch (Throwable $exception) {
                // The report works without titles and unvisited pages.
                $item->expiresAfter(self::CACHE_SECONDS_AFTER_FAILURE);
                $this->logger->warning('request-analytics: the pages of the site could not be listed: {message}', [
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]);

                return [];
            }
        });
    }

    /**
     * @return array<string, string>
     */
    private function collect(): array
    {
        $contentTypes = $this->config->get('contenttypes');
        if (! is_iterable($contentTypes)) {
            return [];
        }

        $pages = [];
        $remaining = $this->maxRecords;
        foreach ($contentTypes as $contentType) {
            if (! $contentType instanceof ContentType) {
                continue;
            }

            $slug = $contentType->get('slug');
            // No page for its records, and no listing.
            if (! is_string($slug) || $slug === '' || $contentType->get('viewless') === true) {
                continue;
            }

            if ($contentType->get('singleton') !== true) {
                $name = $contentType->get('name');
                $pages['/' . $slug] = is_string($name) && $name !== '' ? $name : $slug;
            }

            for ($offset = 0; $remaining > 0; $offset += self::BATCH) {
                $limit = min(self::BATCH, $remaining);
                $records = $this->contentRepository->findBy(['contentType' => $slug, 'status' => 'published'], ['id' => 'DESC'], $limit, $offset);
                $remaining -= count($records);
                foreach ($records as $content) {
                    if ($content instanceof Content) {
                        $this->add($pages, $content);
                    }
                }

                if (count($records) < $limit) {
                    break;
                }
            }
        }

        if ($remaining <= 0) {
            $this->logger->notice('request-analytics: the site has {max} or more published records; the pages report knows the titles of the newest {max} only.', [
                'max' => $this->maxRecords,
            ]);
        }

        return $pages;
    }

    /**
     * @param array<string, string> $pages
     */
    private function add(array &$pages, Content $content): void
    {
        // Null for records without a page of their own.
        $link = $this->contentExtension->getLink($content, false, $this->defaultLocale);
        $path = is_string($link) ? parse_url($link, PHP_URL_PATH) : null;
        if (is_string($path) && str_starts_with($path, '/')) {
            $title = mb_trim(strip_tags($this->contentExtension->getTitle($content, $this->defaultLocale)));
            $pages[$path] = $title === '' ? $path : html_entity_decode($title, ENT_QUOTES | ENT_HTML5);
        }
    }
}
