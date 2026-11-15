<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Collector;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Collector\Hit;

final class HitTest extends TestCase
{
    public function testRowHasEveryColumnOfTheTable(): void
    {
        $hit = new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/');

        self::assertSame(array_keys(Hit::COLUMNS), array_keys($hit->toRow()));
        self::assertSame([], array_diff(array_keys(Hit::TYPES), array_keys(Hit::COLUMNS)));
    }

    public function testSurvivesTheSpoolFormat(): void
    {
        $hit = new Hit('2026-11-13', 23, Hit::KIND_PAGEVIEW, '/kapely/žluťoučký', 'mobile', 'safari', 'ios', 'cs', 'social', 'facebook', 'léto', true, true, '/program', null, 'rockfestkurim.cz', 'kůň');

        $line = json_encode($hit->toRow(), JSON_THROW_ON_ERROR);
        $row = json_decode($line, true, 8, JSON_THROW_ON_ERROR);

        self::assertIsArray($row);
        self::assertEquals($hit, Hit::fromRow($row));
    }

    /**
     * @param array<string, mixed> $changes
     */
    #[DataProvider('invalidRows')]
    public function testInvalidRowIsRejected(array $changes): void
    {
        $row = array_merge((new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/'))->toRow(), $changes);

        self::assertNull(Hit::fromRow($row));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidRows(): iterable
    {
        yield 'day' => [[
            'day' => '13.11.2026',
        ]];
        yield 'hour' => [[
            'hour' => 24,
        ]];
        yield 'hour as a string' => [[
            'hour' => '10',
        ]];
        yield 'kind' => [[
            'kind' => 'click',
        ]];
        yield 'an outbound click of an earlier version' => [[
            'kind' => 'outbound',
        ]];
        yield 'flag' => [[
            'is_entry' => 1,
        ]];
        yield 'too long' => [[
            'lang' => 'cs-CZ-moravia',
        ]];
        yield 'null in a required column' => [[
            'path' => null,
        ]];
        yield 'not a string' => [[
            'ref' => ['a'],
        ]];
        yield 'invalid utf-8' => [[
            'path' => "/\xFF",
        ]];
    }

    public function testRowWithMissingColumnsIsRejected(): void
    {
        self::assertNull(Hit::fromRow([
            'day' => '2026-11-13',
            'hour' => 10,
            'kind' => 'pageview',
        ]));
        self::assertNull(Hit::fromRow([]));
    }

    /**
     * The last check before the database: whoever builds a hit, nothing
     * outside the whitelists gets stored.
     *
     * @param Closure(): Hit $build
     */
    #[DataProvider('unsafeHits')]
    public function testUnsafeValuesAreRefused(Closure $build, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $build();
    }

    /**
     * @return iterable<string, array{Closure(): Hit, string}>
     */
    public static function unsafeHits(): iterable
    {
        yield 'markup in the path' => [static fn (): Hit => new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/<script>'), 'not allowed in path'];
        yield 'protocol-relative path' => [static fn (): Hit => new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '//evil.com'), 'not allowed in path'];
        yield 'backslash path' => [static fn (): Hit => new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/\\evil.com'), 'not allowed in path'];
        yield 'path without slash' => [static fn (): Hit => new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, 'javascript:alert(1)'), 'not allowed in path'];
        yield 'space in the path' => [static fn (): Hit => new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/a b'), 'not allowed in path'];
        yield 'bidi override in a campaign' => [static fn (): Hit => new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/', campaign: "sale\u{202E}"), 'not allowed in campaign'];
        yield 'markup in a source' => [static fn (): Hit => new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/', source: '<img src=x>'), 'not allowed in source'];
        yield 'quote in a term' => [static fn (): Hit => new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/', term: 'a "b"'), 'not allowed in term'];
        yield 'newline in a term' => [static fn (): Hit => new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/', term: "a\nb"), 'not allowed in term'];
        yield 'markup in a referrer' => [static fn (): Hit => new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/', ref: '<b>'), 'not allowed in ref'];
        yield 'invented device' => [static fn (): Hit => new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/', device: 'Desktop<'), 'not allowed in device'];
        yield 'invented language' => [static fn (): Hit => new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/', lang: 'en"'), 'not allowed in lang'];
        yield 'invented bot' => [static fn (): Hit => new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/', bot: '{bot}'), 'not allowed in bot'];
        yield 'too long' => [static fn (): Hit => new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/', source: str_repeat('a', 65)), 'a too long source'];
        yield 'invalid day' => [static fn (): Hit => new Hit('2026-13-xx', 10, Hit::KIND_PAGEVIEW, '/'), 'an invalid day, hour or kind'];
        yield 'invalid kind' => [static fn (): Hit => new Hit('2026-11-13', 10, 'other', '/'), 'an invalid day, hour or kind'];
    }

    public function testSafeValuesOfEveryKindAreKept(): void
    {
        $hit = new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/kapely/žluťoučký-kůň/%3Cb%3E', 'mobile', 'samsung internet', 'android', 'cs', 'organic_search', 'google', "o'reilly 2026", true, false, '/program', null, 'poster-a2', 'vstupenky (redacted)');

        self::assertSame('/kapely/žluťoučký-kůň/%3Cb%3E', $hit->path);
        self::assertSame("o'reilly 2026", $hit->campaign);
    }
}
