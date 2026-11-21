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
        /** A Firefox or Safari that sends `Sec-Fetch-*` and `Accept-Language` with every page it opens. */
        public bool $sendsFetchMetadata = false,
    ) {
    }
}
