<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Support;

use RuntimeException;
use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Writer\HitWriterInterface;

final class MemoryWriter implements HitWriterInterface
{
    /** @var list<Hit> */
    public array $hits = [];

    public bool $fails = false;

    public function write(Hit $hit): void
    {
        if ($this->fails) {
            throw new RuntimeException('The database is gone.');
        }

        $this->hits[] = $hit;
    }
}
