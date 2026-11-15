<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Collector;

use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;

/**
 * One classified request: the raw row that is appended to `ra_hit` or to the
 * spool. Holds derived, coarse values only.
 *
 * The only way into the statistics, so the last check: a value outside the
 * whitelists of Normalizer, or longer than its column, is refused here
 * (InvalidArgumentException), whoever built the hit. Nothing a visitor sent
 * reaches the database unchecked, also not from a spool file that was
 * changed on disk.
 */
final readonly class Hit
{
    public const KIND_PAGEVIEW = 'pageview';
    public const KIND_NOT_FOUND = 'not_found';
    public const KIND_BOT = 'bot';
    public const KIND_ALIAS = 'alias';
    public const KINDS = [self::KIND_PAGEVIEW, self::KIND_NOT_FOUND, self::KIND_BOT, self::KIND_ALIAS];

    /**
     * Value of the visitor dimensions when the visitor sent an opt-out signal.
     */
    public const OPT_OUT = '(opt_out)';

    public const UNKNOWN_LANGUAGE = 'und';

    /**
     * Columns of `ra_hit` with their maximum length; null for the others.
     */
    public const COLUMNS = [
        'day' => null,
        'hour' => null,
        'kind' => 10,
        'path' => 255,
        'device' => 16,
        'browser' => 16,
        'os' => 16,
        'lang' => 8,
        'channel' => 16,
        'source' => 64,
        'campaign' => 64,
        'is_entry' => null,
        'is_reload' => null,
        'ref' => 255,
        'bot' => 32,
        'via' => 255,
        'term' => 64,
    ];

    /**
     * DBAL types of the columns that are not strings.
     */
    public const TYPES = [
        'hour' => Types::SMALLINT,
        'is_entry' => Types::BOOLEAN,
        'is_reload' => Types::BOOLEAN,
    ];

    public function __construct(
        /** Y-m-d, site timezone. */
        public string $day,
        /** 0-23, site timezone. */
        public int $hour,
        public string $kind,
        public string $path,
        public string $device = '',
        public string $browser = '',
        public string $os = '',
        public string $lang = self::UNKNOWN_LANGUAGE,
        public string $channel = '',
        public string $source = '',
        public string $campaign = '',
        public bool $isEntry = false,
        public bool $isReload = false,
        public ?string $ref = null,
        public ?string $bot = null,
        public ?string $via = null,
        public ?string $term = null,
    ) {
        $problem = self::problem($this);
        if ($problem !== null) {
            throw new InvalidArgumentException(sprintf('Request Analytics: a hit with %s was refused.', $problem));
        }
    }

    /**
     * @return array{day: string, hour: int, kind: string, path: string, device: string, browser: string, os: string, lang: string, channel: string, source: string, campaign: string, is_entry: bool, is_reload: bool, ref: ?string, bot: ?string, via: ?string, term: ?string}
     */
    public function toRow(): array
    {
        return [
            'day' => $this->day,
            'hour' => $this->hour,
            'kind' => $this->kind,
            'path' => $this->path,
            'device' => $this->device,
            'browser' => $this->browser,
            'os' => $this->os,
            'lang' => $this->lang,
            'channel' => $this->channel,
            'source' => $this->source,
            'campaign' => $this->campaign,
            'is_entry' => $this->isEntry,
            'is_reload' => $this->isReload,
            'ref' => $this->ref,
            'bot' => $this->bot,
            'via' => $this->via,
            'term' => $this->term,
        ];
    }

    /**
     * Rebuilds a hit from a spool line. Anything that is not a row this class
     * wrote (a truncated line, a foreign file) gives null.
     *
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): ?self
    {
        $day = $row['day'] ?? null;
        $hour = $row['hour'] ?? null;
        $kind = $row['kind'] ?? null;

        if (! is_string($day) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1
            || ! is_int($hour) || $hour < 0 || $hour > 23
            || ! in_array($kind, self::KINDS, true)
            || ! is_bool($row['is_entry'] ?? null) || ! is_bool($row['is_reload'] ?? null)
        ) {
            return null;
        }

        $path = self::text($row, 'path');
        $device = self::text($row, 'device');
        $browser = self::text($row, 'browser');
        $os = self::text($row, 'os');
        $lang = self::text($row, 'lang');
        $channel = self::text($row, 'channel');
        $source = self::text($row, 'source');
        $campaign = self::text($row, 'campaign');
        $ref = self::text($row, 'ref');
        $bot = self::text($row, 'bot');
        $via = self::text($row, 'via');
        $term = self::text($row, 'term');

        if (! is_string($path) || ! is_string($device) || ! is_string($browser) || ! is_string($os)
            || ! is_string($lang) || ! is_string($channel) || ! is_string($source) || ! is_string($campaign)
            || $ref === false || $bot === false || $via === false || $term === false
        ) {
            return null;
        }

        try {
            return new self($day, $hour, $kind, $path, $device, $browser, $os, $lang, $channel, $source, $campaign, $row['is_entry'], $row['is_reload'], $ref, $bot, $via, $term);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * What is wrong with a hit; null when nothing is.
     */
    private static function problem(self $hit): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hit->day) !== 1 || $hit->hour < 0 || $hit->hour > 23 || ! in_array($hit->kind, self::KINDS, true)) {
            return 'an invalid day, hour or kind';
        }

        foreach ($hit->toRow() as $column => $value) {
            $length = self::COLUMNS[$column];
            if (is_string($value) && $length !== null && mb_strlen($value) > $length) {
                return sprintf('a too long %s', $column);
            }
        }

        $checks = [
            'path' => Normalizer::isPath($hit->path),
            'ref' => $hit->ref === null || Normalizer::isPath($hit->ref) || Normalizer::isLabel($hit->ref),
            'source' => Normalizer::isLabel($hit->source),
            'campaign' => Normalizer::isLabel($hit->campaign),
            'via' => $hit->via === null || Normalizer::isLabel($hit->via),
            'term' => $hit->term === null || Normalizer::isLabel($hit->term),
            'device' => Normalizer::isToken($hit->device),
            'browser' => Normalizer::isToken($hit->browser),
            'os' => Normalizer::isToken($hit->os),
            'lang' => Normalizer::isToken($hit->lang),
            'channel' => Normalizer::isToken($hit->channel),
            'bot' => $hit->bot === null || Normalizer::isToken($hit->bot),
        ];
        foreach ($checks as $column => $safe) {
            if (! $safe) {
                return sprintf('characters that are not allowed in %s', $column);
            }
        }

        return null;
    }

    /**
     * The value of a text column: null when the row has none, false when it is
     * not valid text that fits the column.
     *
     * @param array<array-key, mixed> $row
     */
    private static function text(array $row, string $column): string|false|null
    {
        $value = $row[$column] ?? null;
        if ($value === null) {
            return null;
        }

        $length = self::COLUMNS[$column] ?? null;

        return is_string($value) && $length !== null && mb_strlen($value) <= $length && mb_check_encoding($value, 'UTF-8') ? $value : false;
    }
}
