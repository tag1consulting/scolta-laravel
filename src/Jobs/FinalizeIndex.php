<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\MemoryBudget;

/**
 * Finalize the search index after all chunks have been processed.
 *
 * @since 0.2.0 (rewritten 0.3.0 to use IndexBuildOrchestrator)
 *
 * @stability experimental
 */
class FinalizeIndex implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue;

    public int $tries = 1;

    public function __construct(
        public readonly string $stateDir,
        public readonly string $outputDir,
        public readonly ?string $hmacSecret = null,
        public readonly string $language = 'en',
        public readonly string $memoryBudget = 'conservative',
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $budget = MemoryBudget::fromString($this->memoryBudget);
        $orchestrator = new IndexBuildOrchestrator(
            $this->stateDir,
            $this->outputDir,
            $this->hmacSecret,
            $this->language,
        );

        $report = $orchestrator->finalize($budget);

        if ($report->success) {
            Cache::increment('scolta_expand_generation');
        }
    }
}
