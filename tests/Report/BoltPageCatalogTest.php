<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Report;

use Bolt\Configuration\Content\ContentType;
use Bolt\Entity\Content;
use Bolt\Repository\ContentRepository;
use Bolt\Twig\ContentExtension;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use TomVondracek\RequestAnalytics\Report\BoltPageCatalog;
use TomVondracek\RequestAnalytics\Tests\Support\FakeBoltConfig;
use TomVondracek\RequestAnalytics\Tests\Support\MemoryLogger;

final class BoltPageCatalogTest extends TestCase
{
    public function testPublishedRecordsAndListingsWithTheirTitles(): void
    {
        $catalog = $this->catalog([
            'pages' => [new ContentType(['slug' => 'pages', 'name' => 'Pages']), [
                ['/page/about', 'About <em>us</em> &amp; more'],
                ['/page/blank', ''],
            ]],
            'homepage' => [new ContentType(['slug' => 'homepage', 'name' => 'Homepage', 'singleton' => true]), [
                ['/', 'Rockfest'],
            ]],
            // No pages of their own: no listing, and records without a link.
            'blocks' => [new ContentType(['slug' => 'blocks', 'name' => 'Blocks', 'viewless' => true]), [
                [null, 'Footer'],
            ]],
        ]);

        self::assertSame([
            '/pages' => 'Pages',
            '/page/about' => 'About us & more',
            '/page/blank' => '/page/blank',
            '/' => 'Rockfest',
        ], $catalog->pages());
    }

    /**
     * A big site: the newest records up to the limit, and every listing; the
     * records of viewless types are never loaded.
     */
    public function testLoadsAtMostTheLimitAndNoViewlessRecords(): void
    {
        $queried = [];
        $catalog = $this->catalog([
            'blocks' => [new ContentType(['slug' => 'blocks', 'name' => 'Blocks', 'viewless' => true]), [
                [null, 'Footer'],
            ]],
            'pages' => [new ContentType(['slug' => 'pages', 'name' => 'Pages']), [
                ['/page/c', 'C'],
                ['/page/b', 'B'],
            ]],
            'entries' => [new ContentType(['slug' => 'entries', 'name' => 'Entries']), [
                ['/entry/z', 'Z'],
                ['/entry/y', 'Y'],
            ]],
        ], queried: $queried, maxRecords: 3);

        self::assertSame([
            '/pages' => 'Pages',
            '/page/c' => 'C',
            '/page/b' => 'B',
            '/entries' => 'Entries',
            '/entry/z' => 'Z',
        ], $catalog->pages());
        self::assertSame(['pages', 'entries'], array_values(array_unique($queried)));
    }

    public function testListIsCached(): void
    {
        $calls = 0;
        $catalog = $this->catalog([
            'pages' => [new ContentType(['slug' => 'pages', 'name' => 'Pages']), [['/page/about', 'About']]],
        ], $calls);

        $catalog->pages();
        $catalog->pages();

        self::assertSame(1, $calls, 'the records were loaded once');
    }

    public function testFailureLeavesAnEmptyListAndALogEntry(): void
    {
        $logger = new MemoryLogger();
        $repository = self::createStub(ContentRepository::class);
        $repository->method('findBy')
            ->willThrowException(new RuntimeException('Database gone.'));

        $catalog = new BoltPageCatalog(
            FakeBoltConfig::create([], [
                'contenttypes' => new Collection([
                    'pages' => new ContentType(['slug' => 'pages', 'name' => 'Pages']),
                ]),
            ]),
            $repository,
            self::createStub(ContentExtension::class),
            new ArrayAdapter(),
            $logger,
            'en',
            ['en'],
        );

        self::assertSame([], $catalog->pages());
        self::assertSame('warning', $logger->records[0]['level'] ?? null);
    }

    public function testLanguagesStartWithTheDefault(): void
    {
        $catalog = new BoltPageCatalog(
            FakeBoltConfig::create(),
            self::createStub(ContentRepository::class),
            self::createStub(ContentExtension::class),
            new ArrayAdapter(),
            new MemoryLogger(),
            'cs',
            ['en', 'cs', 'nl', '', 7],
        );

        self::assertSame('cs', $catalog->defaultLocale());
        self::assertSame(['cs', 'en', 'nl'], $catalog->locales());
    }

    /**
     * @param array<string, array{ContentType, list<array{?string, string}>}> $contentTypes slug => type, records as [link, title], newest first
     * @param list<string> $queried the content types whose records were loaded
     */
    private function catalog(array $contentTypes, int &$calls = 0, array &$queried = [], int $maxRecords = BoltPageCatalog::MAX_RECORDS): BoltPageCatalog
    {
        $records = [];
        $links = [];
        foreach ($contentTypes as $slug => [, $items]) {
            foreach ($items as [$link, $title]) {
                $content = self::createStub(Content::class);
                $records[$slug][] = $content;
                $links[] = [$content, $link, $title];
            }
        }

        $repository = self::createStub(ContentRepository::class);
        $repository->method('findBy')
            ->willReturnCallback(static function (array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null) use ($records, &$calls, &$queried): array {
                ++$calls;
                self::assertSame('published', $criteria['status'] ?? null);
                self::assertSame(['id' => 'DESC'], $orderBy, 'the newest first');

                $type = $criteria['contentType'] ?? null;
                if (! is_string($type)) {
                    return [];
                }

                $queried[] = $type;

                return array_slice($records[$type] ?? [], $offset ?? 0, $limit);
            });

        $find = static function (Content $content) use ($links): array {
            foreach ($links as [$candidate, $link, $title]) {
                if ($candidate === $content) {
                    return [$link, $title];
                }
            }

            return [null, ''];
        };
        $extension = self::createStub(ContentExtension::class);
        $extension->method('getLink')
            ->willReturnCallback(static fn (Content $content, bool $canonical, ?string $locale): ?string => $find($content)[0]);
        $extension->method('getTitle')
            ->willReturnCallback(static fn (Content $content, string $locale): string => $find($content)[1]);

        return new BoltPageCatalog(
            FakeBoltConfig::create([], [
                'contenttypes' => new Collection(array_map(static fn (array $type): ContentType => $type[0], $contentTypes)),
            ]),
            $repository,
            $extension,
            new ArrayAdapter(),
            new MemoryLogger(),
            'en',
            ['en', 'nl'],
            $maxRecords,
        );
    }
}
