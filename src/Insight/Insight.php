<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Insight;

/**
 * One finding of the analytics page: what was found and how big it is, its
 * likely cause, and one next step.
 *
 * Computed from the aggregates on every render (cached for the day); it
 * disappears by itself when its numbers recover, so it has no state.
 */
final readonly class Insight
{
    /**
     * The numbers cannot be trusted; listed first.
     */
    public const KIND_SETUP = 'setup';

    /**
     * Something is broken.
     */
    public const KIND_FIX = 'fix';

    /**
     * An opportunity.
     */
    public const KIND_GROW = 'grow';

    /**
     * A choice to make.
     */
    public const KIND_DECIDE = 'decide';

    /**
     * How much an insight of a kind weighs in the ranking (impact × weight).
     */
    public const WEIGHTS = [
        self::KIND_SETUP => 1,
        self::KIND_FIX => 3,
        self::KIND_GROW => 2,
        self::KIND_DECIDE => 1,
    ];

    /**
     * The insights card itself.
     */
    public const SECTION_HEADLINE = 'headline';

    public const SECTION_SOURCES = 'sources';
    public const SECTION_BROKEN_LINKS = 'broken-links';
    public const SECTION_SEARCH = 'search';
    public const SECTION_LANGUAGES = 'languages';
    public const SECTION_BROWSERS = 'browsers';
    public const SECTION_DEVICES = 'devices';

    /**
     * The element of the analytics page a section is.
     */
    public const ANCHORS = [
        self::SECTION_HEADLINE => 'ra-insights',
        self::SECTION_SOURCES => 'ra-sources',
        self::SECTION_BROKEN_LINKS => 'ra-broken-links',
        self::SECTION_SEARCH => 'ra-site-search',
        self::SECTION_LANGUAGES => 'ra-languages',
        self::SECTION_BROWSERS => 'ra-dimension-browser',
        self::SECTION_DEVICES => 'ra-dimension-device',
    ];

    /**
     * @param string $id stable: the rule and its subject, such as `broken-link:/old`
     * @param string $section the card that shows it (SECTION_*)
     * @param float $impact visits, page views, requests or searches it is about
     * @param int $days the days it is computed from (the last 28 or 90 complete days)
     * @param bool $compared the days are compared with as many days before
     * @param ?string $anchor the element it links to; null = its section
     * @param int $more further findings of the same rule, not listed
     * @param list<string> $suppresses data that cannot be trusted while it fires (InsightContext::DATA_*)
     */
    public function __construct(
        public string $id,
        public string $section,
        public string $kind,
        public float $impact,
        public Message $message,
        public ?Message $action = null,
        public int $days = InsightContext::RECENT_DAYS,
        public bool $compared = false,
        public ?string $anchor = null,
        public int $more = 0,
        public array $suppresses = [],
    ) {
    }

    public function score(): float
    {
        return $this->impact * self::WEIGHTS[$this->kind];
    }

    public function target(): string
    {
        return $this->anchor ?? self::ANCHORS[$this->section];
    }
}
