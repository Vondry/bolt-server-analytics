<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Diagnostics;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reports a failure of the statistics that the visitor must not notice: the
 * page is served normally, and the error goes to the application log.
 *
 * Level `error`: Bolt's production log (`fingers_crossed`, action level
 * `error`) drops anything lower, so a `debug` or `warning` line would never
 * reach `var/log/prod.log`. Each distinct failure is logged once per
 * THROTTLE_SECONDS: a broken spool directory fails on every request, and every
 * `error` line also flushes the buffered log of its request.
 */
final readonly class FailureReporter
{
    public const THROTTLE_SECONDS = 300;

    public function __construct(
        private LoggerInterface $logger,
        private Throttle $throttle,
    ) {
    }

    /**
     * @param string $stage what failed, e.g. "collection"
     */
    public function report(string $stage, Throwable $exception): void
    {
        try {
            // Long numbers (the hour in a spool file name, a size, an id) do
            // not make another failure: one key per kind of failure.
            $message = preg_replace('/\d{4,}/', '#', $exception->getMessage()) ?? $exception->getMessage();
            if (! $this->throttle->allow('failure|' . $stage . '|' . $exception::class . '|' . $message, self::THROTTLE_SECONDS)) {
                return;
            }

            $this->logger->error('request-analytics: {stage} failed: {message}', [
                'stage' => $stage,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        } catch (Throwable) {
            // Reporting must not fail the request either.
        }
    }
}
