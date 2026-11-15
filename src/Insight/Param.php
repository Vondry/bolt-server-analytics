<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Insight;

/**
 * A value in the text of an insight, with what it is: InsightFormatter
 * formats a number in the backend language, gives a page its title, a channel
 * or a language its name, and marks up a path as code.
 *
 * Plain data, so an insight can be cached.
 */
final readonly class Param
{
    public const NUMBER = 'number';
    public const DECIMAL = 'decimal';
    public const PERCENT = 'percent';
    public const CHANGE = 'change';
    public const DAY = 'day';
    public const PAGE = 'page';
    public const PATH = 'path';
    public const CODE = 'code';
    public const TEXT = 'text';
    public const TERM = 'term';
    public const LANGUAGE = 'language';
    public const CHANNEL = 'channel';
    public const BROWSER = 'browser';
    public const DEVICE = 'device';
    public const LIST = 'list';

    /**
     * @param int|float|string|list<self> $value
     * @param ?string $title the title of a page
     * @param ?int $delta a change shown after a page, such as (−410)
     * @param bool $home a page is the homepage of a language (`/de`)
     */
    private function __construct(
        public string $type,
        public int|float|string|array $value,
        public ?string $title = null,
        public ?int $delta = null,
        public bool $home = false,
    ) {
    }

    public static function number(int $value): self
    {
        return new self(self::NUMBER, $value);
    }

    /**
     * A number with one decimal, such as pages per visit.
     */
    public static function decimal(float $value): self
    {
        return new self(self::DECIMAL, $value);
    }

    /**
     * @param float $share 0.22 for 22 %
     */
    public static function percent(float $share): self
    {
        return new self(self::PERCENT, $share);
    }

    /**
     * A signed relative change.
     *
     * @param float $change −0.24 for −24 %
     */
    public static function change(float $change): self
    {
        return new self(self::CHANGE, $change);
    }

    /**
     * @param string $day Y-m-d
     */
    public static function day(string $day): self
    {
        return new self(self::DAY, $day);
    }

    /**
     * A page of the site, by its title when it has one; `/` is the homepage.
     *
     * @param bool $home the path is the homepage of a language (`/de`)
     */
    public static function page(string $path, ?string $title, ?int $delta = null, bool $home = false): self
    {
        return new self(self::PAGE, $path, $title, $delta, $home || $path === '/');
    }

    /**
     * A path that is not a page (a missing one).
     */
    public static function path(string $path): self
    {
        return new self(self::PATH, $path);
    }

    /**
     * A setting, header or command, shown as code.
     */
    public static function code(string $code): self
    {
        return new self(self::CODE, $code);
    }

    public static function text(string $text): self
    {
        return new self(self::TEXT, $text);
    }

    /**
     * A site-search term.
     */
    public static function term(string $term): self
    {
        return new self(self::TERM, $term);
    }

    /**
     * @param string $code a language code, such as `en`
     */
    public static function language(string $code): self
    {
        return new self(self::LANGUAGE, $code);
    }

    /**
     * @param string $channel a Channel constant
     */
    public static function channel(string $channel): self
    {
        return new self(self::CHANNEL, $channel);
    }

    /**
     * @param string $browser as stored, such as `safari`
     */
    public static function browser(string $browser): self
    {
        return new self(self::BROWSER, $browser);
    }

    /**
     * @param string $device as stored, such as `mobile`
     */
    public static function device(string $device): self
    {
        return new self(self::DEVICE, $device);
    }

    /**
     * Values joined by commas.
     *
     * @param list<self> $items
     */
    public static function list(array $items): self
    {
        return new self(self::LIST, $items);
    }
}
