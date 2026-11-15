<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Collector;

/**
 * What a User-Agent string says, without the client hints of the request.
 */
final readonly class UserAgentInfo
{
    public function __construct(
        public string $device,
        public string $browser,
        public string $os,
        /** Bot family; null for a browser. */
        public ?string $bot,
        /** Major version of the Chromium the string claims to be; null for other engines. */
        public ?int $chromiumMajor,
    ) {
    }

    /**
     * @return array{string, string, string, ?string, ?int}
     */
    public function toArray(): array
    {
        return [$this->device, $this->browser, $this->os, $this->bot, $this->chromiumMajor];
    }

    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data) || count($data) !== 5 || ! array_is_list($data)) {
            return null;
        }

        [$device, $browser, $os, $bot, $chromiumMajor] = $data;
        if (! is_string($device) || ! is_string($browser) || ! is_string($os)
            || ($bot !== null && ! is_string($bot)) || ($chromiumMajor !== null && ! is_int($chromiumMajor))
        ) {
            return null;
        }

        return new self($device, $browser, $os, $bot, $chromiumMajor);
    }
}
