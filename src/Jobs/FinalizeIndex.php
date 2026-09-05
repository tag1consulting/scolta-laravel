<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Tag1\Scolta\Index\BuildCoordinator;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\ScoltaLaravel\Services\ContentSource;
use Tag1\ScoltaLaravel\Services\QueueRebuildDispatcher;

/**
 * Finalize the search index after all chunks have been processed.
 *
 * The last job in the chain, and therefore the only place that sees a queued
 * build land. That makes it the place the tracker is drained: nothing else on
 * the observer -> TriggerRebuild -> queued-jobs path — the most common
 * auto-rebuild configuration — ever cleared `scolta_tracker`, so `pending_index`
 * grew forever there while `scolta:status` and `/api/scolta/v1/health` reported
 * a backlog no rebuild could drain.
 *
 * @since 0.2.0 (rewritten 0.3.0 to use IndexBuildOrchestrator)
 *
 * @stability experimental
 */
class FinalizeIndex implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /**
     * @param  string|null  $lockOwner  Owner token of the cross-process build
     *                                  lock (QueueRebuildDispatcher::BUILD_LOCK)
     *                                  acquired at dispatch. Released here when
     *                                  the chain ends. Null when FinalizeIndex
     *                                  is invoked outside the dispatcher chain
     *                                  (a direct CLI/test call), where there is
     *                                  no cross-process lock to release.
     * @param  string|null  $trackerWatermark  The `changed_at` of the newest
     *                                         pending tracker row when the build
     *                                         gathered its content
     *                                         ({@see ContentSource::pendingWatermark()}).
     *                                         Rows at or before it are drained when
     *                                         this job publishes an index; anything
     *                                         written while the chain ran is left
     *                                         pending, so an edit mid-build is not
     *                                         thrown away. Null skips the drain, for a
     *                                         FinalizeIndex invoked outside a build
     *                                         whose change set it can vouch for.
     */
    public function __construct(
        public readonly string $stateDir,
        public readonly string $outputDir,
        public readonly ?string $hmacSecret = null,
        public readonly string $language = 'en',
        public readonly string $memoryBudget = 'conservative',
        public readonly ?string $lockOwner = null,
        public readonly ?string $trackerWatermark = null,
    ) {}

    public function handle(): void
    {
        try {
            // Same check as ProcessIndexChunk::assertBuildLockHeld(): the lock
            // expires after BUILD_LOCK_TTL and is never extended, and a chain
            // that outlived it may be sharing the ledger with another rebuild.
            // Inside the try so the finally still ends the build state.
            $lock = $this->lockOwner === null
                ? null
                : Cache::restoreLock(QueueRebuildDispatcher::BUILD_LOCK, $this->lockOwner);
            if ($lock instanceof Lock && ! $lock->isOwnedByCurrentProcess()) {
                throw new \RuntimeException(sprintf(
                    'The build lock this chain was dispatched under is no longer held (it expires after %d '
                    .'seconds and the chain outlived it), so another rebuild may have written the page-table '
                    .'ledger meanwhile. Refusing to publish; the previous index is untouched.',
                    QueueRebuildDispatcher::BUILD_LOCK_TTL,
                ));
            }

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

            // Only after a published index: a full build covers every change
            // recorded up to the gather by definition, and this is the first
            // moment that build is on disk. Inside the try, so a finalize that
            // threw above leaves the rows pending for the next rebuild.
            $this->clearTracker();
        } finally {
            // End the build state cleanly whether finalize succeeded or threw,
            // so the chain never leaves a lingering build behind. This runs in
            // the chain's last worker process — the one place that sees the
            // whole chain's terminal state.
            $this->releaseBuildState();
        }
    }

    /**
     * Drain the tracker rows the build that just landed covered.
     *
     * Never fatal: the index is published and serving by the time this runs, and
     * a failure here costs a re-run of work already done, not a broken index.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    private function clearTracker(): void
    {
        if ($this->trackerWatermark === null) {
            return;
        }

        try {
            app(ContentSource::class)->clearTracker($this->trackerWatermark);
        } catch (\Throwable $e) {
            logger()->warning('[scolta] Failed to clear the change tracker after finalize', ['error' => $e->getMessage()]);
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
