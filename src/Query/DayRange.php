<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Query;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A range of calendar days a report asks for: from the first to the last day
 * the trend chart shows, at most the 36 months of its years view.
 */
final readonly class DayRange
{
    public const MAX_DAYS = 1200;

    private function __construct(
        /** Y-m-d */
        public string $from,
        /** Y-m-d */
        public string $to,
    ) {
    }

    /**
     * @throws InvalidArgumentException for an invalid or too long range
     */
    public static function of(string $from, string $to): self
    {
        $fromDay = self::date($from, 'from');
        $toDay = self::date($to, 'to');
        if ($fromDay > $toDay || (int) $fromDay->diff($toDay)->days >= self::MAX_DAYS) {
            throw new InvalidArgumentException(sprintf('"from" must be at most %d days before "to".', self::MAX_DAYS - 1));
        }

        return new self($from, $to);
    }

    /**
     * The range up to today at most: the trend chart's current week or month
     * runs into the future, which has no data yet and would make a period
     * look worse than the one before.
     *
     * @param string $today Y-m-d
     */
    public function until(string $today): self
    {
        if ($this->to <= $today) {
            return $this;
        }

        return new self(min($this->from, $today), $today);
    }

    /**
     * The same number of days right before: what this range is compared with.
     */
    public function previous(): self
    {
        $from = self::date($this->from, 'from');
        $days = $this->length();

        return new self($from->modify(sprintf('-%d days', $days))->format('Y-m-d'), $from->modify('-1 day')->format('Y-m-d'));
    }

    /**
     * Number of days, both ends included.
     */
    public function length(): int
    {
        return (int) self::date($this->from, 'from')->diff(self::date($this->to, 'to'))->days + 1;
    }

    /**
     * Every day of the range, first to last.
     *
     * @return list<string>
     */
    public function days(): array
    {
        $days = [];
        $end = self::date($this->to, 'to');
        for ($day = self::date($this->from, 'from'); $day <= $end; $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    /**
     * A calendar day, as midnight UTC: compared and shifted as a date.
     */
    private static function date(string $value, string $name): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException(sprintf('"%s" must be a date such as 2026-11-13, "%s" given.', $name, $value));
        }

        return $date;
    }
}
