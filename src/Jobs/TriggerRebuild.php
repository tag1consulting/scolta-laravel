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
 * Trigger a full index rebuild from the queue.
 *
 * Dispatched by ScoltaObserver when auto-rebuild is enabled and content
 * changes. Uses cache-based debouncing to avoid rebuilding on every
 * single save — multiple edits within the delay window are batched
 * into a single rebuild.
 *
 * Content gathering, chunk-file writing, fingerprint checking, and job
 * chaining are delegated to QueueRebuildDispatcher — the same path used
 * by `artisan scolta:build` — so the documented publish filters and the
 * configured memory budget apply identically on the observer path.
 *
 * @since 0.2.0
 *
 * @stability experimental
 */
class TriggerRebuild implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Whether to force a full rebuild, bypassing the fingerprint check.
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
     * and hands off to the shared queue rebuild dispatcher.
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

        $dispatcher->dispatch($budget, $this->force);
    }
}
