<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Config;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Typed reader for one mapping of the YAML config. A missing or empty value
 * means the default; a value of the wrong type is a configuration error, and a
 * key nobody asked for is reported, so a typo can't go unnoticed.
 *
 * @internal
 */
final class ConfigSection
{
    /** @var array<string, true> */
    private array $read = [];

    /**
     * @param array<array-key, mixed> $data
     */
    public function __construct(
        private readonly array $data,
        private readonly string $path = '',
    ) {
    }

    public function section(string $key): self
    {
        $value = $this->value($key);
        if ($value === null) {
            return new self([], $this->key($key));
        }

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $this->invalid($key, 'a mapping', $value);
        }

        return new self($value, $this->key($key));
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->value($key);
        if ($value === null) {
            return $default;
        }

        if (! is_bool($value)) {
            throw $this->invalid($key, 'true or false', $value);
        }

        return $value;
    }

    public function string(string $key, ?string $default = null): ?string
    {
        $value = $this->value($key);
        if ($value === null) {
            return $default;
        }

        if (! is_string($value)) {
            throw $this->invalid($key, 'a string', $value);
        }

        $value = mb_trim($value);

        return $value === '' ? $default : $value;
    }

    /**
     * A secret: like string(), but a wrong value is never shown in the
     * message (it is logged, cached and shown on the dashboard).
     */
    public function secret(string $key, string $expected): ?string
    {
        $value = $this->value($key);
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw $this->invalid($key, $expected, '(hidden)');
        }

        $value = mb_trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * A moment: `"2026-11-23 18:00"` (or with seconds), in the site's
     * timezone, as `Y-m-d H:i:s`; or a date and time YAML read as a Unix
     * timestamp (unquoted, with seconds), as `@<timestamp>`. Null when not set.
     */
    public function dateTime(string $key, string $expected): ?string
    {
        $value = $this->value($key);
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return '@' . $value;
        }

        if (is_string($value)) {
            $value = mb_trim($value);
            foreach (['!Y-m-d H:i:s', '!Y-m-d H:i'] as $format) {
                $moment = DateTimeImmutable::createFromFormat($format, $value);
                if ($moment !== false && $moment->format(mb_substr($format, 1)) === $value) {
                    return $moment->format('Y-m-d H:i:s');
                }
            }
        }

        throw $this->invalid($key, $expected, $value);
    }

    /**
     * A string, or `false` (or nothing) for a feature that is off: null.
     */
    public function stringOrOff(string $key): ?string
    {
        $value = $this->value($key);
        if ($value === null || $value === false) {
            return null;
        }

        if (! is_string($value)) {
            throw $this->invalid($key, 'a string, or false', $value);
        }

        $value = mb_trim($value);

        return $value === '' ? null : $value;
    }

    public function int(string $key, int $default, int $min, ?int $max = null): int
    {
        $value = $this->value($key);
        if ($value === null) {
            return $default;
        }

        if (! is_int($value) || $value < $min || ($max !== null && $value > $max)) {
            throw $this->invalid($key, $max === null ? sprintf('a whole number, %d or more', $min) : sprintf('a whole number from %d to %d', $min, $max), $value);
        }

        return $value;
    }

    /**
     * @template T of string|int
     *
     * @param non-empty-list<T> $allowed
     * @param T $default
     *
     * @return T
     */
    public function enum(string $key, array $allowed, string|int $default): string|int
    {
        $value = $this->value($key);
        if ($value === null) {
            return $default;
        }

        foreach ($allowed as $option) {
            if ($value === $option) {
                return $option;
            }
        }

        throw $this->invalid($key, 'one of ' . implode(' | ', $allowed), $value);
    }

    /**
     * @return list<string> trimmed, without empty entries and duplicates
     */
    public function stringList(string $key): array
    {
        $value = $this->value($key);
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw $this->invalid($key, 'a list of strings', $value);
        }

        $list = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw $this->invalid($key, 'a list of strings', $value);
            }

            $item = mb_trim($item);
            if ($item !== '' && ! in_array($item, $list, true)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * What to tell the administrator about keys of the mapping that were never
     * read; null when there are none.
     */
    public function unknownKeys(): ?string
    {
        $unknown = array_diff(array_map(strval(...), array_keys($this->data)), array_keys($this->read));
        if ($unknown === []) {
            return null;
        }

        return sprintf(
            'Unknown Request Analytics config key(s) "%s" are ignored; the known ones%s are "%s". Check config/extensions/%s.yaml.',
            implode('", "', array_map($this->key(...), $unknown)),
            $this->path === '' ? '' : sprintf(' in "%s"', $this->path),
            implode('", "', array_keys($this->read)),
            ConfigLoader::CONFIG_BASENAME
        );
    }

    public function invalid(string $key, string $expected, mixed $value): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'The Request Analytics `%s` must be %s, %s given. Check config/extensions/%s.yaml.',
            $this->key($key),
            $expected,
            is_scalar($value) ? var_export($value, true) : get_debug_type($value),
            ConfigLoader::CONFIG_BASENAME
        ));
    }

    private function value(string $key): mixed
    {
        $this->read[$key] = true;

        return $this->data[$key] ?? null;
    }

    private function key(string $key): string
    {
        return $this->path === '' ? $key : $this->path . '.' . $key;
    }
}
