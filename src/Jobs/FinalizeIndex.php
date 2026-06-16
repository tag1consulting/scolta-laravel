<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Tag1\Scolta\Index\BuildCoordinator;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\ScoltaLaravel\Services\QueueRebuildDispatcher;

/**
 * Finalize the search index after all chunks have been processed.
 *
 * @since 0.2.0 (rewritten 0.3.0 to use IndexBuildOrchestrator)
 *
 * @stability experimental
 */
class FinalizeIndex implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /**
     * @param  string|null  $lockOwner  Owner token of the cross-process build
     *                                  lock (QueueRebuildDispatcher::BUILD_LOCK)
     *                                  acquired at dispatch. Released here when
     *                                  the chain ends. Null when FinalizeIndex
     *                                  is invoked outside the dispatcher chain
     *                                  (a direct CLI/test call), where there is
     *                                  no cross-process lock to release.
     */
    public function __construct(
        public readonly string $stateDir,
        public readonly string $outputDir,
        public readonly ?string $hmacSecret = null,
        public readonly string $language = 'en',
        public readonly string $memoryBudget = 'conservative',
        public readonly ?string $lockOwner = null,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        try {
            $budget = MemoryBudget::fromString($this->memoryBudget);
            $orchestrator = new IndexBuildOrchestrator(
                $this->stateDir,
                $this->outputDir,
                $this->hmacSecret,
                $this->language,
            );

            $report = $orchestrator->finalize($budget);

            if (! $report->success) {
                // A swallowed failure here makes the whole chain look successful
                // while no index was published. Log and fail the job so queue
                // monitoring (failed_jobs, Horizon) surfaces it.
                logger()->error('[scolta] Index finalize failed', ['error' => $report->error]);

                throw new \RuntimeException('Scolta index finalize failed: '.($report->error ?? 'unknown error'));
            }

            Cache::increment('scolta_expand_generation');
        } finally {
            // End the build state cleanly whether finalize succeeded or threw,
            // so the chain never leaves a lingering build behind. This runs in
            // the chain's last worker process — the one place that sees the
            // whole chain's terminal state.
            $this->releaseBuildState();
        }
    }

    /**
     * Reset the build manifest and release the cross-process build lock.
     *
     * On success the orchestrator already released the flock and wiped the
     * state dir; on failure it left the manifest 'building' to allow a
     * --resume. The queue chain has no resume wiring (tries = 1, a fresh
     * dispatch each time), so a lingering 'building' manifest would only block
     * status queries and confuse the next rebuild — release() drops the lock,
     * marks the manifest idle, and wipes the state files, leaving a clean
     * slate. This touches only the state dir, never the published pagefind/
     * output, so a failed rebuild keeps the prior index serving (atomic-swap
     * fail-safe holds).
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    private function releaseBuildState(): void
    {
        try {
            (new BuildCoordinator($this->stateDir, $this->hmacSecret))->release();
        } catch (\Throwable $e) {
            logger()->warning('[scolta] Failed to reset build state after finalize', ['error' => $e->getMessage()]);
        }

        if ($this->lockOwner === null) {
            return;
        }

        // restoreLock() lets this worker release a lock acquired in another
        // process (the dispatcher) by its owner token.
        try {
            Cache::restoreLock(QueueRebuildDispatcher::BUILD_LOCK, $this->lockOwner)->release();
        } catch (\Throwable $e) {
            logger()->warning('[scolta] Failed to release build lock after finalize', ['error' => $e->getMessage()]);
        }
    }
}
