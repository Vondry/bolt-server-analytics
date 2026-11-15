<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use TomVondracek\RequestAnalytics\SiteTime;
use TomVondracek\RequestAnalytics\Tests\Support\FakeBoltConfig;

final class SiteTimeTest extends TestCase
{
    public function testDaysAreCountedInTheSiteTimezone(): void
    {
        $siteTime = self::siteTime('Europe/Prague');

        self::assertSame('2026-11-14 00:30', $siteTime->now()->format('Y-m-d H:i'));
        self::assertSame('Europe/Prague', $siteTime->at(0)->getTimezone()->getName());
    }

    public function testInvalidOrMissingTimezoneIsUtc(): void
    {
        self::assertSame('UTC', self::siteTime('Mars/Olympus_Mons')->timezone()->getName());
        self::assertSame('UTC', self::siteTime(null)->timezone()->getName());
    }

    private static function siteTime(?string $timezone): SiteTime
    {
        return new SiteTime(FakeBoltConfig::create([], [
            'general' => [
                'timezone' => $timezone,
            ],
        ]), new MockClock('2026-11-13 23:30:00', 'UTC'));
    }
}
