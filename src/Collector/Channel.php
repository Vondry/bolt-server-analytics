<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Collector;

/**
 * How a visitor arrived at a page.
 */
final readonly class Channel
{
    public const PAID_SEARCH = 'paid_search';
    public const DISPLAY = 'display';
    public const SOCIAL = 'social';
    public const EMAIL = 'email';
    public const AFFILIATE = 'affiliate';
    public const CAMPAIGN = 'campaign';
    public const INTERNAL = 'internal';
    public const ORGANIC_SEARCH = 'organic_search';
    public const AI_ASSISTANT = 'ai_assistant';
    public const APP = 'app';
    public const REFERRAL = 'referral';
    public const DIRECT = 'direct';
    public const UNKNOWN = 'unknown';

    public function __construct(
        public string $channel,
        public string $source = '',
        public string $campaign = '',
        /** Where the request came from: the path for a page of this site, the host (or app package) otherwise. */
        public ?string $referrer = null,
    ) {
    }

    /**
     * A page view that did not come from another page of the site.
     */
    public function isEntry(): bool
    {
        return $this->channel !== self::INTERNAL;
    }
}
