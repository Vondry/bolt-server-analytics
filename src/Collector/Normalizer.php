<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Collector;

/**
 * Turns raw request values into the bounded, normalised values that are stored.
 *
 * Everything a visitor sends (the URL, `Referer`, `utm_*`, search terms) is
 * untrusted. What is stored is safe by construction, not only escaped when it
 * is shown:
 *
 * - valid UTF-8, composed (NFC), so one text is always one row
 * - nothing invisible or deceptive: no control or format characters (bidi
 *   overrides, zero-width), private-use or unassigned code points, and no more
 *   than two combining marks in a row ("Zalgo" text that spills over a card)
 * - only the characters of a whitelist: paths keep letters, digits and the
 *   characters of an address and percent-encode any other (`<`, `"`, `'`,
 *   backtick, spaces, ...), so the address keeps its meaning; labels and
 *   search terms keep letters, digits and plain punctuation, anything else is
 *   a space
 * - bounded in length, never cut inside a percent-encoded character
 *
 * The is*() methods check a stored value against the same whitelists: Hit
 * refuses anything else, also from a spool file that was tampered with.
 */
final class Normalizer
{
    public const REDACTED = '(redacted)';
    public const ID = ':id';

    /**
     * Path segments that are ids rather than names: 5+ digits, a UUID, a
     * hash / token of 16+ hex characters, a ULID, or a random token: 20+
     * characters of mixed case with digits (base64url, as in reset and
     * unsubscribe links). Short numbers (years, page numbers, small content
     * ids) are kept: there are few of them. Slugs are lowercase.
     */
    private const ID_SEGMENT = '~^(?:\d{5,}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{16,}|[0-7][0-9a-hjkmnp-tv-z]{25})$~i';

    private const TOKEN_SEGMENT = '~^(?=(?:[^0-9]*[0-9]){2})(?=[^a-z]*[a-z])(?=[^A-Z]*[A-Z])[A-Za-z0-9_-]{20,}$~';

    /**
     * A JSON Web Token: three base64url parts.
     */
    private const JWT_SEGMENT = '~^[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}$~';

    /**
     * E-mail addresses are removed from paths and search terms alike.
     */
    private const EMAIL = '/[^\s@\/]+@[^\s@\/]+\.[^\s@\/]+/u';

    /**
     * Characters a path keeps as they are: letters, marks and digits of any
     * script and those of an address (RFC 3986 `pchar` and `/`). Any other is
     * stored percent-encoded. For a character class delimited by `#`.
     */
    private const PATH_CHARACTERS = '\p{L}\p{M}\p{N}\-._~!$&()*+,;=:@\/%';

    /**
     * Characters of a label or search term: letters, marks, digits, spaces and
     * plain punctuation. Any other becomes a space. For a class delimited by `#`.
     */
    private const LABEL_CHARACTERS = '\p{L}\p{M}\p{N} .,\-_+&\'()!?@:\/%*';

    /**
     * Invisible or deceptive: format characters (bidi overrides and isolates,
     * zero-width, BOM, soft hyphen), private-use, unassigned and surrogates.
     */
    private const INVISIBLE = '/[\p{Cf}\p{Co}\p{Cn}\p{Cs}]+/u';

    /**
     * Values of the classifier's own lists (device, browser, OS, bot, channel,
     * language) and the markers of the extension.
     */
    private const TOKEN = '/^[a-z0-9_ .()-]*$/';

    private const PATH_LENGTH = 255;
    private const TERM_LENGTH = 64;
    private const LABEL_LENGTH = 64;

    /**
     * Decoded path without query and fragment, backslashes read as slashes,
     * duplicate slashes collapsed, no trailing slash (except `/`), e-mail
     * addresses redacted, characters outside the whitelist percent-encoded, at
     * most 255 characters.
     *
     * @param bool $routeTemplates replace id and token segments with `:id` (`/product/12345` is `/product/:id`)
     */
    public static function path(string $path, bool $routeTemplates = false): string
    {
        $path = self::composed(rawurldecode(self::withoutQuery($path)));
        // A browser reads a backslash as `/`: `/\evil.com` links to another site.
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('~/{2,}~', '/', $path) ?? $path;
        $path = '/' . mb_trim($path, '/');

        // Always, whatever route_templates says: an address names a person.
        if (str_contains($path, '@')) {
            $path = preg_replace(self::EMAIL, self::REDACTED, $path) ?? self::REDACTED;
        }

        if ($routeTemplates && preg_match('~\d~', $path) === 1) {
            $segments = explode('/', $path);
            foreach ($segments as $index => $segment) {
                if (preg_match(self::ID_SEGMENT, $segment) === 1
                    || preg_match(self::TOKEN_SEGMENT, $segment) === 1
                    || preg_match(self::JWT_SEGMENT, $segment) === 1
                ) {
                    $segments[$index] = self::ID;
                }
            }
            $path = implode('/', $segments);
        }

        return self::truncateEncoded(self::encodeUnsafe($path), self::PATH_LENGTH);
    }

    /**
     * The decoded path as requested, for matching `exclude_paths`.
     */
    public static function decodedPath(string $path): string
    {
        return self::text(rawurldecode(self::withoutQuery($path)));
    }

    /**
     * Site-search term: lowercase, single spaces, without e-mail addresses,
     * phone-like numbers and long digit runs, at most 64 characters.
     */
    public static function searchTerm(string $term): ?string
    {
        $term = self::label($term, 4 * self::TERM_LENGTH);
        if ($term === '') {
            return null;
        }

        $term = preg_replace([
            self::EMAIL,
            '/\+?\d[\d\s().\/-]{5,}\d/u',
            '/\d{6,}/',
        ], self::REDACTED, $term) ?? self::REDACTED;

        return mb_substr($term, 0, self::TERM_LENGTH);
    }

    /**
     * The name of a campaign or source of a tagged link (`utm_campaign`,
     * `utm_source`): a label without what newsletter and CRM tools put there
     * per recipient. E-mail addresses and phone numbers (9+ digits, also
     * with single separators: also a long id, or two dates in a row) are
     * redacted; other ids become `:id`, as in paths: UUIDs, hashes of 16+ hex
     * characters and numbers of 5 to 8 digits (`uid-48213377` is `uid-:id`).
     */
    public static function campaign(string $value): string
    {
        $value = self::label($value, 4 * self::LABEL_LENGTH);
        if ($value === '') {
            return '';
        }

        // In this order: an address first, ids before the digits of a UUID
        // look like a phone number, a phone number before its digits look
        // like an id.
        $value = preg_replace(self::EMAIL, self::REDACTED, $value) ?? self::REDACTED;
        $value = preg_replace([
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/',
            '/(?<![0-9a-z])[0-9a-f]{16,}(?![0-9a-z])/',
        ], self::ID, $value) ?? self::ID;
        // 9+ digits, single separators between them: a phone number, not a date.
        $value = preg_replace('/\+?\d(?:[\s().\/-]?\d){8,}/u', self::REDACTED, $value) ?? self::REDACTED;
        $value = preg_replace('/\d{5,}/', self::ID, $value) ?? self::ID;

        // Cut to length; a marker cut in two goes.
        $value = mb_substr($value, 0, self::LABEL_LENGTH);
        if (mb_strlen($value) === self::LABEL_LENGTH) {
            $value = preg_replace('/(?:\((?:r(?:e(?:d(?:a(?:c(?:t(?:e(?:d)?)?)?)?)?)?)?)?|:i?)$/', '', $value) ?? $value;
        }

        return mb_trim($value);
    }

    /**
     * A campaign value, source name or marker: lowercase, single spaces, truncated.
     */
    public static function label(string $value, int $length = self::LABEL_LENGTH): string
    {
        $value = preg_replace(self::INVISIBLE, '', mb_strtolower(self::composed($value))) ?? '';
        // Control characters, other spaces and anything not on the whitelist
        // separate words here, they are not dropped.
        $value = preg_replace('#[^' . self::LABEL_CHARACTERS . ']+#u', ' ', $value) ?? '';
        $value = preg_replace('/ {2,}/', ' ', $value) ?? '';

        return mb_trim(mb_substr(mb_trim($value), 0, $length));
    }

    /**
     * Lowercase host without port, trailing dot and `www.`.
     */
    public static function host(string $host): string
    {
        $host = mb_rtrim(mb_strtolower(self::composed($host)), '.');
        // Labels of letters, digits and inner hyphens (also IDN), dot-separated;
        // anything else is no host: ''.
        if (mb_strlen($host) > 253 || preg_match('/^[\p{L}\p{N}](?:[\p{L}\p{M}\p{N}-]*[\p{L}\p{M}\p{N}])?(?:\.[\p{L}\p{N}](?:[\p{L}\p{M}\p{N}-]*[\p{L}\p{M}\p{N}])?)*$/u', $host) !== 1) {
            return '';
        }

        return str_starts_with($host, 'www.') ? mb_substr($host, 4) : $host;
    }

    /**
     * A stored path: empty (bots), or `/` and whitelisted characters only,
     * never `//`.
     */
    public static function isPath(string $value): bool
    {
        return $value === '' || preg_match('#^/(?!/)[' . self::PATH_CHARACTERS . ']*$#u', $value) === 1;
    }

    /**
     * A stored label, search term, host or marker.
     */
    public static function isLabel(string $value): bool
    {
        return preg_match('#^[' . self::LABEL_CHARACTERS . ']*$#u', $value) === 1 && preg_match('/\p{M}{3}/u', $value) !== 1;
    }

    /**
     * A value of one of the classifier's own lists.
     */
    public static function isToken(string $value): bool
    {
        return preg_match(self::TOKEN, $value) === 1;
    }

    /**
     * Valid UTF-8 without control characters.
     */
    private static function text(string $value): string
    {
        return preg_replace('/[\x00-\x1F\x7F]+/', '', mb_scrub($value, 'UTF-8')) ?? '';
    }

    /**
     * Valid UTF-8, composed (NFC) where PHP has intl, at most two combining
     * marks in a row.
     */
    private static function composed(string $value): string
    {
        $value = mb_scrub($value, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
            $value = is_string($normalized) ? $normalized : $value;
        }

        return preg_replace('/(\p{M}{2})\p{M}+/u', '$1', $value) ?? '';
    }

    /**
     * Characters outside the path whitelist as `%XX` (their UTF-8 bytes), so
     * the address keeps its meaning but holds no markup, space or invisible
     * character.
     */
    private static function encodeUnsafe(string $path): string
    {
        return preg_replace_callback(
            '#[^' . self::PATH_CHARACTERS . ']#u',
            static fn (array $match): string => rawurlencode($match[0]),
            $path,
        ) ?? '/';
    }

    /**
     * At most $length characters, without a `%XX` cut in two.
     */
    private static function truncateEncoded(string $path, int $length): string
    {
        if (mb_strlen($path) <= $length) {
            return $path;
        }

        return preg_replace('/%[0-9A-F]?$/', '', mb_substr($path, 0, $length)) ?? '/';
    }

    private static function withoutQuery(string $path): string
    {
        return preg_replace('/[?#].*$/s', '', $path) ?? $path;
    }
}
