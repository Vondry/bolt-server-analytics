<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Insight;

/**
 * A sentence of an insight: a key of the `request_analytics` translation
 * domain (the English text, like every message of the extension) and its
 * values, by the name of their `%placeholder%` without the percent signs.
 *
 * Keys are written in the call of of(), on its first line, so the
 * translation tests find them.
 */
final readonly class Message
{
    /**
     * @param array<string, Param> $params
     */
    private function __construct(
        public string $key,
        public array $params,
    ) {
    }

    /**
     * @param array<string, Param> $params
     */
    public static function of(string $key, array $params = []): self
    {
        return new self($key, $params);
    }
}
