<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Tag1\Scolta\Config\MemoryBudgetConfig;
use Tag1\ScoltaLaravel\Services\QueueRebuildDispatcher;

/**
 * Bring the index up to date from the queue.
 *
 * Dispatched by ScoltaObserver when auto-rebuild is enabled and content
 * changes. Uses cache-based debouncing to avoid rebuilding on every
 * single save — multiple edits within the delay window are batched
 * into a single run.
 *
 * The run is **incremental** unless $force says otherwise: the dispatcher
 * applies the tracked changes to the published index and only streams the whole
 * corpus when it cannot. This job used to stream the corpus every time, so the
 * operation that fires on every content edit was the most expensive one the
 * package has. scolta-drupal has always split it this way — its queue worker
 * tries an incremental update, `drush scolta:build` is always full — and
 * `scolta:build` here now matches.
 *
 * $force is the "rebuild it all" request (POST /api/scolta/v1/rebuild-now with
 * force=true) and skips the incremental attempt along with the fingerprint
 * check, because both are ways of not doing the rebuild that was asked for.
 *
 * Change-set resolution, content gathering, chunk-file writing, fingerprint
 * checking, and job chaining are all delegated to QueueRebuildDispatcher — the
 * same path used by `artisan scolta:build --queue` — so the documented publish
 * filters and the configured memory budget apply identically on the observer
 * path.
 *
 * @since 0.2.0
 *
 * @stability experimental
 */
class TriggerRebuild implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

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
     * Create a new TriggerRebuild job instance.
     *
     * @param  bool  $force  Skip fingerprint check and force a full rebuild.
     *
     * @since 0.2.0
     *
     * @stability experimental
     */
    public function __construct(bool $force = false)
    {
        $this->force = $force;
    }

    /**
     * Execute the rebuild.
     *
     * Clears the debounce flag, resolves the configured memory budget,
     * and hands off to the shared queue rebuild dispatcher, asking for an
     * incremental update unless a full rebuild was explicitly requested.
     *
     * @since 0.2.0
     *
     * @stability experimental
     */
    public function handle(QueueRebuildDispatcher $dispatcher): void
    {
        // Clear debounce flag so future changes can schedule new rebuilds.
        Cache::forget('scolta_rebuild_scheduled');

        $budget = MemoryBudgetConfig::fromCliAndConfig(
            null,
            null,
            fn () => [
                'profile' => config('scolta.memory_budget.profile', 'conservative'),
                'chunk_size' => config('scolta.memory_budget.chunk_size'),
            ],
        );

        $dispatcher->dispatch($budget, $this->force, incremental: ! $this->force);
    }
}
