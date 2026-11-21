<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Aggregation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TomVondracek\RequestAnalytics\Tests\Support\Pipeline;

#[Group('database')]
final class DailyCapTest extends TestCase
{
    private Pipeline $pipeline;

    protected function setUp(): void
    {
        $this->pipeline = new Pipeline();
        $this->pipeline->analyticsDatabase()
            ->install();
    }

    protected function tearDown(): void
    {
        $this->pipeline->remove();
    }

    /**
     * A flood of made-up missing pages: of a day with more rows than the cap,
     * the values seen fewer than k times go.
     */
    public function testDayWithTooManyRowsKeepsWhatReachesTheThreshold(): void
    {
        $this->pipeline->notFound('2026-11-12', '/wp-login.php', 40);
        $this->pipeline->notFound('2026-11-12', '/old-program', 5, '/kapely');
        for ($index = 1; $index <= 5; ++$index) {
            $this->pipeline->notFound('2026-11-12', '/random-' . $index, 4);
        }

        $deleted = $this->pipeline->dailyCap(3)
            ->apply('2026-11-13', 5);

        self::assertSame(5, $deleted);
        self::assertSame(['/old-program', '/wp-login.php'], $this->paths());
    }

    /**
     * However many made-up values arrive, and however often each, a value
     * that reaches k stays: a flood never pushes the site's values out.
     */
    public function testFloodNeverPushesOutValuesThatReachTheThreshold(): void
    {
        $this->pipeline->notFound('2026-11-12', '/real', 5);
        for ($index = 1; $index <= 10; ++$index) {
            $this->pipeline->notFound('2026-11-12', '/flood-' . $index, 50);
        }

        self::assertSame(0, $this->pipeline->dailyCap(3)->apply('2026-11-13', 5));
        self::assertContains('/real', $this->paths());
    }

    public function testDayWithinTheCapIsLeftAlone(): void
    {
        $this->pipeline->notFound('2026-11-12', '/a', 1);
        $this->pipeline->notFound('2026-11-12', '/b', 1);
        $this->pipeline->notFound('2026-11-12', '/c', 1);

        self::assertSame(0, $this->pipeline->dailyCap(3)->apply('2026-11-13', 5));
        self::assertSame(['/a', '/b', '/c'], $this->paths());
    }

    /**
     * Today is still counting; days long before cannot change any more.
     */
    public function testOnlyTheDaysBeforeTodayAreChecked(): void
    {
        foreach (['2026-11-13', '2026-09-01'] as $day) {
            for ($index = 1; $index <= 5; ++$index) {
                $this->pipeline->notFound($day, '/random-' . $index, 1);
            }
        }

        self::assertSame(0, $this->pipeline->dailyCap(3)->apply('2026-11-13', 5));
        self::assertCount(10, $this->paths());
    }

    /**
     * With k = 1 a flood of values sent once still goes.
     */
    public function testValuesSentOnceGoAlsoWithoutAThreshold(): void
    {
        for ($index = 1; $index <= 5; ++$index) {
            $this->pipeline->notFound('2026-11-12', '/random-' . $index, 1);
        }
        $this->pipeline->notFound('2026-11-12', '/twice', 2);

        self::assertSame(5, $this->pipeline->dailyCap(3)->apply('2026-11-13', 1));
        self::assertSame(['/twice'], $this->paths());
    }

    public function testEachTableIsCappedOnItsOwn(): void
    {
        for ($index = 1; $index <= 5; ++$index) {
            $this->pipeline->search('2026-11-12', 'term ' . $index, $index);
        }
        $this->pipeline->notFound('2026-11-12', '/a', 1);

        self::assertSame(3, $this->pipeline->dailyCap(2)->apply('2026-11-13', 4));
        self::assertSame(['term 4', 'term 5'], array_column($this->pipeline->database->rows($this->pipeline->database->tables->search(), 'term'), 'term'));
        self::assertSame(['/a'], $this->paths());
    }

    /**
     * @return list<string>
     */
    private function paths(): array
    {
        $paths = array_values(array_filter(array_column($this->pipeline->database->rows($this->pipeline->database->tables->notFound(), 'path'), 'path'), is_string(...)));
        sort($paths);

        return $paths;
    }
}
