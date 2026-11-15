<?php

declare(strict_types=1);

namespace TomVondracek\RequestAnalytics\Tests\Support;

use RuntimeException;

/**
 * A collector running in another process (see spool-writer.php).
 */
final class WriterProcess
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    /** proc_get_status() reports the exit code only once. */
    private ?int $exitCode = null;

    public function __construct(Project $project, int $hits)
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/spool-writer.php', $project->dir, (string) $hits], [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $this->pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('The writer process could not be started.');
        }

        $this->process = $process;
    }

    public function waitUntilReady(): void
    {
        if (fgets($this->pipes[1]) !== "ready\n") {
            throw new RuntimeException('The writer process failed: ' . stream_get_contents($this->pipes[2]));
        }
    }

    public function isRunning(): bool
    {
        $status = proc_get_status($this->process);
        if (! $status['running'] && $this->exitCode === null) {
            $this->exitCode = $status['exitcode'];
        }

        return $status['running'];
    }

    /**
     * @throws RuntimeException when the process failed
     */
    public function finish(): void
    {
        $output = stream_get_contents($this->pipes[1]) . stream_get_contents($this->pipes[2]);
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }

        $closed = proc_close($this->process);
        $exitCode = $this->exitCode ?? $closed;
        if ($exitCode !== 0 || mb_trim($output) !== '') {
            throw new RuntimeException(sprintf('The writer process exited with %d: %s', $exitCode, $output));
        }
    }
}
