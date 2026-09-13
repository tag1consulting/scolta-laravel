<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Progress;

use Psr\Log\LoggerInterface;
use Tag1\Scolta\Index\ProgressReporterInterface;

/**
 * Logs one progress line per chunk boundary, for builds with no terminal.
 *
 * The queued rebuild has no console to draw a progress bar on, so without a
 * reporter a worker's log shows only scolta-php's memory telemetry. The
 * orchestrator already calls advance() once per committed chunk; logging
 * there at notice level shows how far along the build is.
 *
 * @since 2.0.0
 *
 * @stability experimental
 */
final class LoggingProgressReporter implements ProgressReporterInterface
{
    private int $total = 0;

    private int $done = 0;

    public function __construct(private readonly LoggerInterface $logger) {}

    public function start(int $totalSteps, string $label): void
    {
        $this->total = $totalSteps;
        $this->done = 0;
        $this->logger->notice("{$label}: {$totalSteps} chunks to build.");
    }

    public function advance(int $steps = 1, ?string $detail = null): void
    {
        $this->done += $steps;
        $pct = $this->total > 0 ? (int) round($this->done / $this->total * 100) : 0;
        $this->logger->notice(rtrim("Progress {$this->done}/{$this->total} chunks ({$pct}%) {$detail}"));
    }

    public function finish(?string $summary = null): void
    {
        if ($summary !== null) {
            $this->logger->notice("Finished: {$summary}");
        }
    }
}
