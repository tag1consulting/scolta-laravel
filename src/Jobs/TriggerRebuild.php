<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Log\Logger;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Psr\Log\LoggerInterface;
use Tag1\Scolta\Config\MemoryBudgetConfig;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\BuildState;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\ResumeChainPolicy;
use Tag1\Scolta\Index\ResumeChainRunner;
use Tag1\Scolta\Index\StatusReport;
use Tag1\ScoltaLaravel\Commands\BuildCommand;
use Tag1\ScoltaLaravel\Progress\LoggingProgressReporter;
use Tag1\ScoltaLaravel\Services\ContentSource;
use Tag1\ScoltaLaravel\Services\QueueRebuildDispatcher;
use Tag1\ScoltaLaravel\Services\ResumeChain;
use Tag1\ScoltaLaravel\Support\HmacSecret;

/**
 * Bring the index up to date from the queue.
 *
 * The one rebuild request in this package. ScoltaObserver dispatches it,
 * debounced, when content changes; the first-run auto-build, the rebuild-now
 * endpoint and `php artisan scolta:request-build` dispatch it directly. It is
 * only ever in the queue while a build is requested or in progress, so the
 * documented setup is a plain worker, not a scheduled job.
 *
 * Each run decides from the state directory alone what it is doing:
 *
 *  - An interrupted build on disk (ResumeChainPolicy::resumable(): a
 *    `building` manifest whose last recorded outcome is a memory yield or
 *    nothing at all) is continued with one BuildIntent::resume() segment in
 *    this process, whatever the request asked for: the incremental updater and
 *    a fresh build both write the ledger that build's remaining segments depend
 *    on. A segment that yields on memory pressure is chained to completion by
 *    scolta-php's ResumeChainRunner, one `php artisan scolta:build --resume`
 *    child per segment, because a second segment in the heap the first one
 *    fragmented would be judged a stall. When no child can be spawned the job
 *    re-dispatches itself and the next worker run takes the next segment.
 *  - Otherwise the run is **incremental** unless $force says otherwise: the
 *    dispatcher applies the tracked changes to the published index and only
 *    streams the whole corpus when it cannot.
 *
 * Surviving a killed process is left to the queue, which is what it is for. A
 * worker killed mid-segment (an evicted pod, the OOM killer) leaves this job
 * reserved; the connection's `retry_after` re-delivers it and the retry finds
 * the build resumable. $tries allows those re-deliveries and $maxExceptions
 * forbids retrying a verdict: a build that failed for any other reason fails
 * this job on the spot and lands in the failed-jobs table. Before a segment
 * or a chunk chain starts, one `resumeOnly` copy is queued to fire after the
 * build lock would have expired, so a build whose worker died with nothing
 * else in the queue still has a request standing to finish it; when the build
 * completed in time that copy finds nothing to resume and exits.
 *
 * @since 0.2.0
 *
 * @stability experimental
 */
class TriggerRebuild implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * The dedicated queue every Scolta job runs on.
     *
     * Hardcoded, as scolta-drupal's `ScoltaRebuildWorker::QUEUE_NAME` is. A
     * build segment may hold a worker for `BUILD_LOCK_TTL` seconds and wants a
     * `retry_after` longer than that; neither belongs on the queue an
     * application's mail and notifications share.
     *
     * @since 2.0.0
     *
     * @stability experimental
     */
    public const QUEUE_NAME = 'scolta';

    /**
     * Cache key ScoltaObserver and scolta:request-build debounce requests under.
     *
     * Present while a request is queued and not yet running; the job forgets
     * it first thing so the next change schedules a new request.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public const DEBOUNCE_KEY = 'scolta_rebuild_scheduled';

    /**
     * Seconds before a request that found a build running tries again.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public const RETRY_DELAY = 60;

    /**
     * Re-deliveries a killed worker may cause before the job is failed.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public int $tries = 3;

    /**
     * An exception is a verdict, not a kill: the first one fails the job.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public int $maxExceptions = 1;

    /**
     * A segment may run as long as the build lock is held.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public int $timeout = QueueRebuildDispatcher::BUILD_LOCK_TTL;

    /**
     * Whether to force a full rebuild, bypassing both the incremental attempt
     * and the fingerprint check.
     *
     * @since 0.2.0
     *
     * @stability experimental
     */
    public bool $force;

    /**
     * Whether this is the standing copy that only continues an interrupted build.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public bool $resumeOnly;

    /**
     * Set when a chained child handed finalize to the queue (exit 3).
     */
    private bool $deferred = false;

    /**
     * Create a new TriggerRebuild job instance.
     *
     * @param  bool  $force  Skip fingerprint check and force a full rebuild.
     * @param  bool  $resumeOnly  Do nothing unless an interrupted build is on disk.
     *
     * @since 0.2.0
     *
     * @stability experimental
     */
    public function __construct(bool $force = false, bool $resumeOnly = false)
    {
        $this->force = $force;
        $this->resumeOnly = $resumeOnly;
        // In the constructor, not as a `$queue` property: Queueable's own
        // property is untyped on Laravel 11/12 and typed on 13, and a
        // redeclaration cannot match both.
        $this->onQueue(self::QUEUE_NAME);
    }

    /**
     * Execute the rebuild.
     *
     * @throws \RuntimeException When the build failed for a reason resuming cannot fix.
     *
     * @since 0.2.0
     *
     * @stability experimental
     */
    public function handle(QueueRebuildDispatcher $dispatcher): void
    {
        // Clear debounce flag so future changes can schedule new rebuilds.
        Cache::forget(self::DEBOUNCE_KEY);

        $budget = self::budget();
        $stateDir = config('scolta.state_dir', storage_path('app/scolta'));
        $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));
        $orchestrator = $this->orchestrator($stateDir, $outputDir);
        $buildState = $orchestrator->coordinator()->buildState();

        if (! ResumeChainPolicy::resumable($buildState)) {
            if ($this->resumeOnly) {
                return;
            }

            $result = $dispatcher->dispatch($budget, $this->force, incremental: ! $this->force);
            if ($result['status'] === QueueRebuildDispatcher::STATUS_IN_PROGRESS) {
                // A build owns the lock; this request is applied to the finished
                // index on a later run rather than dropped.
                $this->later(new self($this->force), self::RETRY_DELAY);
            } elseif ($result['status'] === QueueRebuildDispatcher::STATUS_DISPATCHED) {
                $this->standBy();
            }

            return;
        }

        $lock = Cache::lock(QueueRebuildDispatcher::BUILD_LOCK, QueueRebuildDispatcher::BUILD_LOCK_TTL);
        if (! $lock->get()) {
            $this->later(new self($this->force), self::RETRY_DELAY);

            return;
        }

        try {
            $this->standBy();
            $this->resume($orchestrator, $buildState, $budget, $outputDir, $lock->owner());
        } finally {
            $lock->release();
        }
    }

    /**
     * Run one more segment of the interrupted build on disk, then settle it.
     */
    private function resume(IndexBuildOrchestrator $orchestrator, BuildState $buildState, MemoryBudget $budget, string $outputDir, string $lockOwner): void
    {
        $source = app(ContentSource::class);
        $logger = new Logger(app('log')->driver(), app('events'));
        $watermark = $source->pendingWatermark();
        $segment = $buildState->segment();

        $report = $this->runSegment($orchestrator, BuildIntent::resume($budget), $source, $outputDir, $logger);

        if (! $report->success && $report->isMemoryAbort()) {
            // A stall or a runaway chain is recorded as the build's outcome, so
            // the next run starts fresh instead of resuming into the same wall.
            $reason = $this->policy()->stopReason($report, $buildState);
            if ($reason !== null) {
                throw new \RuntimeException('Scolta index rebuild failed: '.$reason);
            }

            $chained = $this->chain($buildState, $report, $lockOwner, $logger);
            if ($chained === null) {
                if ($this->deferred) {
                    $logger->warning('[scolta] A resume segment handed finalize to the queue; the index is published once a worker drains it.');

                    return;
                }
                $logger->info(sprintf('[scolta] Queued rebuild yielded on memory pressure after %d pages; the next run resumes it.', $report->pagesProcessed));
                $this->later(new self($this->force), self::RETRY_DELAY);

                return;
            }
            $report = $chained;
        }

        if (! $report->success) {
            throw new \RuntimeException('Scolta index rebuild failed: '.($report->error ?? 'unknown error'));
        }

        $source->clearTracker($watermark);
        Cache::increment('scolta_expand_generation');
        $logger->info(sprintf(
            '[scolta] Index rebuilt via queue after resuming at segment %d: %d pages in %.1fs.',
            $segment,
            $report->pagesProcessed,
            $report->durationSeconds,
        ));
    }

    /**
     * Run the remaining segments as child processes, in this job.
     *
     * @return StatusReport|null The chain's final report, or null when no child could run here.
     */
    // phpstan cannot see SegmentNotRun cross ResumeChainRunner::run(), which
    // carries no @throws for what the host's callable raises.
    // @phpstan-ignore return.unusedType
    private function chain(BuildState $buildState, StatusReport $yielded, string $lockOwner, LoggerInterface $logger): ?StatusReport
    {
        $chain = app(ResumeChain::class);
        $runner = new ResumeChainRunner($buildState, $this->policy(), function (array $env) use ($chain, $lockOwner): int {
            // The child inherits this job's build lock: it would otherwise find
            // the lock held and exit deferred without building anything.
            $chain->env = $env + [ResumeChain::LOCK_OWNER_ENV => $lockOwner];
            $exitCode = $chain->runSegment(null, null, $this->force);
            if ($exitCode === null) {
                throw new SegmentNotRun(sprintf('No artisan binary at %s to spawn a resume segment; the next run resumes the build instead.', base_path('artisan')));
            }
            if ($exitCode === BuildCommand::DEFERRED) {
                $this->deferred = true;
                throw new SegmentNotRun('A resume segment handed finalize to the queue.');
            }

            return $exitCode;
        }, $logger);

        try {
            return $runner->run($yielded);
        } catch (SegmentNotRun $e) { // @phpstan-ignore catch.neverThrown
            $logger->warning('[scolta] '.$e->getMessage());

            return null;
        }
    }

    /**
     * Stream the corpus through one build segment; a seam for tests.
     */
    protected function runSegment(IndexBuildOrchestrator $orchestrator, BuildIntent $intent, ContentSource $source, string $outputDir, LoggerInterface $logger): StatusReport
    {
        $items = (new ContentExporter)->filterItems($source->getPublishedContent());

        return $orchestrator->build($intent, $items, $logger, new LoggingProgressReporter($logger), $this->force);
    }

    /**
     * The orchestrator for a build; a seam for tests to inject a pressure probe.
     */
    protected function orchestrator(string $stateDir, string $outputDir): IndexBuildOrchestrator
    {
        return new IndexBuildOrchestrator(
            $stateDir,
            $outputDir,
            HmacSecret::normalize(config('app.key')),
            config('scolta.ai_languages.0', 'en'),
        );
    }

    private function policy(): ResumeChainPolicy
    {
        return new ResumeChainPolicy(ini_get('memory_limit') ?: null);
    }

    /**
     * Queue the standing request that outlives a killed build.
     */
    private function standBy(): void
    {
        $this->later(new self(false, true), QueueRebuildDispatcher::BUILD_LOCK_TTL + self::RETRY_DELAY);
    }

    /**
     * Queue a copy of this job for later; a no-op on a sync connection, which
     * would run it here and now, inside the build it is waiting for.
     */
    private function later(self $job, int $seconds): void
    {
        $connection = $this->connection ?? config('queue.default');
        if (config("queue.connections.{$connection}.driver") === 'sync') {
            return;
        }

        dispatch($job->delay(now()->addSeconds($seconds)));
    }

    private static function budget(): MemoryBudget
    {
        return MemoryBudgetConfig::fromCliAndConfig(
            null,
            null,
            fn () => [
                'profile' => config('scolta.memory_budget.profile', 'conservative'),
                'chunk_size' => config('scolta.memory_budget.chunk_size'),
            ],
        );
    }
}
