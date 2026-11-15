<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics;

use Bolt\Configuration\Config;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Days and hours are counted in the site's timezone (`general/timezone` in
 * config/bolt/config.yaml), the same one at collection and in the reports.
 */
final class SiteTime
{
    private ?string $timezoneName = null;
    private ?DateTimeZone $timezone = null;

    public function __construct(
        private readonly Config $boltConfig,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * UTC when the configured timezone is missing or not valid.
     */
    public function timezone(): DateTimeZone
    {
        $name = $this->boltConfig->get('general/timezone');
        $name = is_string($name) && $name !== '' ? $name : 'UTC';

        if (! $this->timezone instanceof DateTimeZone || $this->timezoneName !== $name) {
            try {
                $this->timezone = new DateTimeZone($name);
            } catch (Throwable) {
                $this->timezone = new DateTimeZone('UTC');
            }

            $this->timezoneName = $name;
        }

        return $this->timezone;
    }

    public function now(): DateTimeImmutable
    {
        return $this->clock->now()
            ->setTimezone($this->timezone());
    }

    public function at(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($this->timezone());
    }
}
