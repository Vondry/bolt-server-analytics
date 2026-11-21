<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Diagnostics;

use Bolt\Widget\Injector\RequestZone;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use TomVondracek\RequestAnalytics\Collector\ClassificationTrace;
use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Config\RequestAnalyticsConfig;
use TomVondracek\RequestAnalytics\SiteTime;
use TomVondracek\RequestAnalytics\Storage\PrivateFiles;

/**
 * The debug dump (`debug.dump`): for finding out why a request is counted
 * the way it is, or not at all. Appends one JSON line per request of the
 * site to `var/log/request-analytics-debug.ndjson`: when, the URL, the
 * status, the headers the extension reads, and what was decided.
 *
 * Holds no secret and nothing the statistics never read:
 * - headers from a fixed list only: never `Cookie` (the admin's session),
 *   `Authorization`, a CSRF token, or an IP address (`X-Forwarded-For`, …);
 *   of `Signature` and `Signature-Input` only that they were sent
 * - in the URL and the Referer, values of parameters named like a secret
 *   (`token`, `password`, `key`, `code`, …), path segments that look like
 *   tokens (random strings, hashes, UUIDs, JWTs) and e-mail addresses are
 *   masked
 * - of a visitor who sent an opt-out signal (GPC, DNT) only the path, the
 *   signal and the decision
 *
 * Ends by itself: only until `debug.until`, and up to `debug.max_mb`. The
 * file is deleted DELETE_AFTER_DAYS after its last line (by the daily
 * cleanup of the rollup). Owner and group only, never in the public folder.
 */
final readonly class DebugDump
{
    public const FILE = 'var/log/request-analytics-debug.ndjson';
    public const DELETE_AFTER_DAYS = 7;
    public const MASK = '***';

    /**
     * The headers the extension reads, as they are written.
     */
    private const HEADERS = [
        'User-Agent', 'Accept', 'Accept-Language', 'Referer', 'Cache-Control',
        'Sec-Fetch-Dest', 'Sec-Fetch-Mode', 'Sec-Fetch-Site', 'Sec-Fetch-User',
        'Sec-CH-UA', 'Sec-CH-UA-Mobile', 'Sec-CH-UA-Platform',
        'Sec-Purpose', 'Purpose', 'X-Moz', 'X-Purpose',
        'Sec-GPC', 'DNT', 'Signature-Agent',
    ];

    /**
     * Headers whose value is a secret: only that they were sent.
     */
    private const PRESENCE_ONLY = ['Signature', 'Signature-Input'];

    private const OPT_OUT_HEADERS = ['Sec-GPC', 'DNT'];

    /**
     * Words of a parameter name (split at `_`, `-`, `.`, `[`, `]`) that make its value a secret.
     */
    private const SECRET_WORDS = ['token', 'password', 'passwd', 'pass', 'pwd', 'secret', 'key', 'apikey', 'auth', 'authorization', 'signature', 'sig', 'session', 'sid', 'phpsessid', 'csrf', 'code', 'otp', 'nonce', 'ticket', 'hash'];

    /**
     * Path segments that are tokens rather than names: random strings of
     * mixed case with digits, hashes of 16+ hex characters, UUIDs, JWTs.
     */
    private const SECRET_SEGMENTS = [
        '~^(?=(?:[^0-9]*[0-9]){2})(?=[^a-z]*[a-z])(?=[^A-Z]*[A-Z])[A-Za-z0-9_-]{16,}$~',
        '~^[0-9a-f]{16,}$~i',
        '~^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$~i',
        '~^[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}$~',
    ];

    private const EMAIL = '/[^\s@\/&=?#]+@[^\s@\/&=?#]+\.[^\s@\/&=?#]+/u';
    private const HEADER_LENGTH = 512;
    private const URL_LENGTH = 2048;

    public function __construct(
        private ConfigLoader $configLoader,
        private SiteTime $siteTime,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    /**
     * Whether a request is dumped now: switched on, before `until`, and the
     * file below `max_mb`.
     */
    public function isActive(): bool
    {
        $config = $this->configLoader->load();
        if (! $this->isRunning($config)) {
            return false;
        }

        $file = $this->file();
        clearstatcache(true, $file);
        $size = @filesize($file);

        return $size === false || $size < $config->debugMaxMb * 1024 * 1024;
    }

    /**
     * @param ClassificationTrace $trace why it was not counted
     * @param bool $collected whether the hit went to the statistics
     */
    public function dump(Request $request, Response $response, ?Hit $hit, ClassificationTrace $trace, bool $collected): void
    {
        $record = [
            'at' => $this->siteTime->now()->format(DATE_ATOM),
            'method' => $request->getMethod(),
            // As the client sent it (getUri() sorts and re-encodes the query).
            'url' => self::url($request->getSchemeAndHttpHost() . ($trace->optOut ? $request->getBaseUrl() . $request->getPathInfo() : $request->getRequestUri())),
            'status' => $response->getStatusCode(),
            'zone' => RequestZone::getFromRequest($request),
            'headers' => $this->headers($request, $trace->optOut),
            'result' => $hit instanceof Hit ? $hit->kind : 'skipped',
            'collected' => $collected,
            'reason' => $trace->skipped,
            'hit' => $hit?->toRow(),
        ];

        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
        $file = $this->file();
        PrivateFiles::directory(dirname($file));
        $new = ! is_file($file);

        $handle = @fopen($file, 'a');
        if ($handle === false) {
            throw new RuntimeException(sprintf('The debug dump "%s" could not be opened.', $file));
        }

        if ($new) {
            PrivateFiles::restrict($file);
        }

        try {
            if (! flock($handle, LOCK_EX) || fwrite($handle, $line) !== mb_strlen($line, '8bit')) {
                throw new RuntimeException(sprintf('The debug dump "%s" could not be written.', $file));
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Deletes the file DELETE_AFTER_DAYS after its last line, unless the
     * dump is still running.
     *
     * @return bool whether it was deleted
     */
    public function cleanUp(): bool
    {
        $file = $this->file();
        clearstatcache(true, $file);
        $modified = @filemtime($file);
        if ($modified === false || time() - $modified < self::DELETE_AFTER_DAYS * 86_400) {
            return false;
        }

        try {
            if ($this->isRunning($this->configLoader->load())) {
                return false;
            }
        } catch (Throwable) {
            // A broken config: the dump does not run.
        }

        return @unlink($file);
    }

    public function file(): string
    {
        return $this->projectDir . '/' . self::FILE;
    }

    private function isRunning(RequestAnalyticsConfig $config): bool
    {
        if (! $config->debugDump || $config->debugUntil === null) {
            return false;
        }

        $until = str_starts_with($config->debugUntil, '@')
            ? $this->siteTime->at((int) mb_substr($config->debugUntil, 1))
            : DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $config->debugUntil, $this->siteTime->timezone());

        return $until !== false && $this->siteTime->now() < $until;
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request, bool $optOut): array
    {
        $headers = [];
        foreach ($optOut ? self::OPT_OUT_HEADERS : self::HEADERS as $name) {
            $value = $request->headers->get($name);
            if ($value !== null) {
                $headers[$name] = $name === 'Referer' ? self::url($value) : mb_substr($value, 0, self::HEADER_LENGTH);
            }
        }

        if (! $optOut) {
            foreach (self::PRESENCE_ONLY as $name) {
                if ($request->headers->has($name)) {
                    $headers[$name] = '(sent)';
                }
            }
        }

        return $headers;
    }

    /**
     * The URL without its secrets: secret parameters, token-like path
     * segments and e-mail addresses masked; no fragment.
     *
     * @internal public for the tests
     */
    public static function url(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return self::MASK;
        }

        $result = isset($parts['scheme'], $parts['host']) ? mb_strtolower($parts['scheme']) . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') : '';

        $segments = explode('/', $parts['path'] ?? '');
        foreach ($segments as $index => $segment) {
            $decoded = rawurldecode($segment);
            if (array_any(self::SECRET_SEGMENTS, static fn (string $pattern): bool => preg_match($pattern, $decoded) === 1)) {
                $segments[$index] = self::MASK;
            }
        }

        $result .= self::withoutEmails(implode('/', $segments));

        if (isset($parts['query']) && $parts['query'] !== '') {
            $pairs = [];
            foreach (explode('&', $parts['query']) as $pair) {
                $separator = mb_strpos($pair, '=');
                $name = $separator === false ? $pair : mb_substr($pair, 0, $separator);
                $value = $separator === false ? null : mb_substr($pair, $separator + 1);
                $pairs[] = self::isSecretName(rawurldecode($name))
                    ? $name . '=' . self::MASK
                    : $name . ($value === null ? '' : '=' . self::withoutEmails($value));
            }

            $result .= '?' . implode('&', $pairs);
        }

        return mb_substr($result, 0, self::URL_LENGTH);
    }

    private static function isSecretName(string $name): bool
    {
        $words = preg_split('/[_\-.\[\]]+/', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY);

        return array_any($words === false ? [] : $words, static fn (string $word): bool => in_array($word, self::SECRET_WORDS, true));
    }

    /**
     * E-mail addresses masked, also percent-encoded ones (`jane%40example.com`).
     */
    private static function withoutEmails(string $value): string
    {
        $decoded = rawurldecode(str_replace('+', ' ', $value));
        if (! str_contains($decoded, '@')) {
            return $value;
        }

        return preg_replace(self::EMAIL, '(redacted)', $decoded) ?? self::MASK;
    }
}
