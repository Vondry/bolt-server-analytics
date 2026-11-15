<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Writer;

use TomVondracek\RequestAnalytics\Collector\Hit;

/**
 * Appends one raw row. Called after the response has been sent; must not read
 * and must not aggregate.
 */
interface HitWriterInterface
{
    public function write(Hit $hit): void;
}
