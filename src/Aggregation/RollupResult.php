<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Aggregation;

/**
 * Outcome of one rollup run.
 */
final readonly class RollupResult
{
    public function __construct(
        /** Raw rows that were aggregated and deleted. */
        public int $processed,
        /** Raw rows that are still waiting, in `ra_hit` and in the spool. */
        public int $pending,
        public int $durationMs,
        /** Another rollup was running; nothing was done. */
        public bool $busy = false,
    ) {
    }

    /**
     * @return array{processed: int, pending: int, durationMs: int, busy: bool}
     */
    public function toArray(): array
    {
        return [
            'processed' => $this->processed,
            'pending' => $this->pending,
            'durationMs' => $this->durationMs,
            'busy' => $this->busy,
        ];
    }
}
