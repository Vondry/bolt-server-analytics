<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Query\DayRange;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;

#[Group('database')]
final class BreakdownQueryTest extends TestCase
{
    private Pipeline $pipeline;

    protected function setUp(): void
    {
        $this->pipeline = new Pipeline();
    }

    protected function tearDown(): void
    {
        $this->pipeline->remove();
    }

    public function testSumsEveryDimensionOverTheRangeLargestFirst(): void
    {
        $this->pipeline->clients('2026-11-10', 6, 'desktop', 'chrome', 'windows');
        $this->pipeline->clients('2026-11-10', 4, 'desktop', 'firefox', 'linux');
        $this->pipeline->clients('2026-11-12', 9, 'mobile', 'safari', 'ios');
        $this->pipeline->clients('2026-11-13', 5, 'mobile', 'chrome', 'android');
        // Out of the range.
        $this->pipeline->clients('2026-11-08', 100, 'tablet', 'edge', 'windows');

        $breakdown = $this->pipeline->breakdownQuery()
            ->breakdown('pageviews', '2026-11-09', '2026-11-13', 1);

        self::assertSame(['pageviews', '2026-11-09', '2026-11-13', 24], [$breakdown['metric'], $breakdown['from'], $breakdown['to'], $breakdown['total']]);
        self::assertSame([
            [
                'key' => 'mobile',
                'value' => 14,
                'previous' => null,
            ],
            [
                'key' => 'desktop',
                'value' => 10,
                'previous' => null,
            ],
        ], $breakdown['dimensions']['device']);
        self::assertSame([['chrome', 11], ['safari', 9], ['firefox', 4]], self::pairs($breakdown['dimensions']['browser']));
        self::assertSame([['ios', 9], ['windows', 6], ['android', 5], ['linux', 4]], self::pairs($breakdown['dimensions']['os']));
    }

    public function testEntriesCountOnlyEntryPageViews(): void
    {
        $this->pipeline->clients('2026-11-10', 6, 'desktop', entry: true);
        $this->pipeline->clients('2026-11-10', 4, 'mobile');

        $breakdown = $this->pipeline->breakdownQuery()
            ->breakdown('entries', '2026-11-10', '2026-11-10', 1);

        self::assertSame(6, $breakdown['total']);
        self::assertSame([['desktop', 6]], self::pairs($breakdown['dimensions']['device']));
    }

    /**
     * A value seen less often than `report_threshold_k` could single out a
     * visitor: it is counted as "other", and "other" always comes last.
     */
    public function testValuesBelowTheThresholdAreCountedAsOther(): void
    {
        $this->pipeline->clients('2026-11-10', 20, 'desktop', 'chrome', 'windows');
        $this->pipeline->clients('2026-11-10', 2, 'tv', 'other', 'other');
        $this->pipeline->clients('2026-11-10', 3, 'tablet', 'vivaldi', 'linux');
        $this->pipeline->clients('2026-11-10', 1, Hit::OPT_OUT, Hit::OPT_OUT, Hit::OPT_OUT);

        $breakdown = $this->pipeline->breakdownQuery()
            ->breakdown('pageviews', '2026-11-10', '2026-11-10', 3);

        self::assertSame([['desktop', 20], ['tablet', 3], ['other', 3]], self::pairs($breakdown['dimensions']['device']));
        self::assertSame([['chrome', 20], ['vivaldi', 3], ['other', 3]], self::pairs($breakdown['dimensions']['browser']));
        self::assertSame(26, $breakdown['total'], 'nothing is dropped');
    }

    public function testLongTailIsFoldedIntoOther(): void
    {
        foreach (['chrome', 'safari', 'edge', 'firefox', 'samsung', 'opera', 'yandex', 'brave', 'vivaldi'] as $index => $browser) {
            $this->pipeline->clients('2026-11-10', 100 - $index, browser: $browser);
        }

        $browsers = $this->pipeline->breakdownQuery()
            ->breakdown('pageviews', '2026-11-10', '2026-11-10', 1)['dimensions']['browser'];

        self::assertCount(7, $browsers);
        self::assertSame(['chrome', 'safari', 'edge', 'firefox', 'samsung', 'opera', 'other'], array_column($browsers, 'key'));
        self::assertSame(94 + 93 + 92, $browsers[6]['value']);
    }

    /**
     * Each listed value comes with its count in the days compared with. One seen
     * too seldom then is new (0), and "other" is everything not listed, so the
     * counts add up to the total of that period.
     */
    public function testCountsOfThePeriodComparedWith(): void
    {
        $this->pipeline->clients('2026-11-10', 30, 'desktop', 'chrome', 'windows');
        $this->pipeline->clients('2026-11-10', 10, 'mobile', 'safari', 'ios');
        $this->pipeline->clients('2026-11-10', 5, 'tablet', 'firefox', 'android');
        $this->pipeline->clients('2026-11-10', 2, 'tv', 'edge', 'linux');
        $this->pipeline->clients('2026-11-03', 20, 'desktop', 'chrome', 'windows');
        $this->pipeline->clients('2026-11-03', 2, 'mobile', 'safari', 'ios');
        $this->pipeline->clients('2026-11-03', 8, 'tv', 'edge', 'linux');

        $breakdown = $this->pipeline->breakdownQuery()
            ->breakdown('pageviews', '2026-11-09', '2026-11-15', 3, DayRange::of('2026-11-02', '2026-11-08'));

        self::assertSame(['2026-11-02', '2026-11-08', 47, 30], [$breakdown['previousFrom'], $breakdown['previousTo'], $breakdown['total'], $breakdown['previousTotal']]);
        // Then: desktop 20, mobile 2 (too few: new), TV 8.
        self::assertSame([
            [
                'key' => 'desktop',
                'value' => 30,
                'previous' => 20,
            ],
            [
                'key' => 'mobile',
                'value' => 10,
                'previous' => 0,
            ],
            [
                'key' => 'tablet',
                'value' => 5,
                'previous' => 0,
            ],
            [
                'key' => 'other',
                'value' => 2,
                'previous' => 30 - 20,
            ],
        ], $breakdown['dimensions']['device']);
    }

    public function testWithoutComparison(): void
    {
        $this->pipeline->clients('2026-11-10', 30, 'desktop');

        $breakdown = $this->pipeline->breakdownQuery()
            ->breakdown('pageviews', '2026-11-10', '2026-11-10', 1);

        self::assertSame([null, null, null], [$breakdown['previousFrom'], $breakdown['previousTotal'], $breakdown['dimensions']['device'][0]['previous']]);
    }

    public function testEmptyRange(): void
    {
        $breakdown = $this->pipeline->breakdownQuery()
            ->breakdown('pageviews', '2026-11-10', '2026-11-16', 5);

        self::assertSame(0, $breakdown['total']);
        self::assertSame([
            'device' => [],
            'browser' => [],
            'os' => [],
        ], $breakdown['dimensions']);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'metric' => ['hits', '2026-11-10', '2026-11-10', '"metric" must be one of pageviews | entries, "hits" given.'];
        yield 'date' => ['pageviews', '2026-11-31', '2026-12-01', '"from" must be a date such as 2026-11-13, "2026-11-31" given.'];
        yield 'reversed' => ['pageviews', '2026-11-10', '2026-11-09', '"from" must be at most 1199 days before "to".'];
        yield 'too long' => ['pageviews', '2020-01-01', '2026-11-10', '"from" must be at most 1199 days before "to".'];
    }

    #[DataProvider('invalidArguments')]
    public function testInvalidArguments(string $metric, string $from, string $to, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->pipeline->breakdownQuery()
            ->breakdown($metric, $from, $to, 5);
    }

    /**
     * @param list<array{key: string, value: int, previous: ?int}> $slices
     *
     * @return list<array{string, int}>
     */
    private static function pairs(array $slices): array
    {
        return array_map(static fn (array $slice): array => [$slice['key'], $slice['value']], $slices);
    }
}
