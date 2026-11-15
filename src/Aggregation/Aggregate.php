<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Aggregation;

/**
 * One aggregate table and how its rows are derived from `ra_hit`.
 */
final readonly class Aggregate
{
    /**
     * @param array<string, string> $keys primary key column => SQL expression over `ra_hit` it is grouped by
     * @param array<string, string> $counters counter column => SQL aggregate expression
     * @param string|null $where SQL condition that selects the raw rows; null = all of them
     */
    public function __construct(
        public string $table,
        public array $keys,
        public array $counters,
        public ?string $where = null,
    ) {
    }
}
