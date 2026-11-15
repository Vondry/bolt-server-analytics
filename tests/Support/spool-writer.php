<?php

/**
 * A collector in a process of its own, for the tests of concurrent writes:
 *
 *     php spool-writer.php <project dir> <number of hits>
 *
 * Prints "ready" once it is about to write.
 */

declare(strict_types=1);

use TomVondracek\RequestAnalytics\Collector\Hit;
use TomVondracek\RequestAnalytics\Config\ConfigLoader;
use TomVondracek\RequestAnalytics\Tests\Support\FakeBoltConfig;
use TomVondracek\RequestAnalytics\Writer\SpoolHitWriter;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$dir = $argv[1] ?? '';
$count = (int) ($argv[2] ?? 1);

$loader = new ConfigLoader(FakeBoltConfig::create([
    'extensions_config' => $dir . '/config/extensions',
], [], $dir), $dir . '/var/cache', $dir);
$writer = new SpoolHitWriter($loader);
$loader->load();

fwrite(STDOUT, "ready\n");

for ($index = 0; $index < $count; ++$index) {
    $writer->write(new Hit('2026-11-13', 10, Hit::KIND_PAGEVIEW, '/page-' . getmypid() . '-' . $index));
}
