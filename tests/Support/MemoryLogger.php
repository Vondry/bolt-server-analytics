<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string}> */
    public array $records = [];

    /**
     * @param array<array-key, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        // `{placeholder}`s replaced, as Monolog's PsrLogMessageProcessor does.
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        $this->records[] = [
            'level' => $level,
            'message' => strtr((string) $message, $replacements),
        ];
    }
}
