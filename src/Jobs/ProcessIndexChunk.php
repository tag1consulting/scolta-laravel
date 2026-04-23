<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Tag1\Scolta\Index\BuildCoordinator;
use Tag1\Scolta\Index\InvertedIndexBuilder;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\Stemmer;
use Tag1\Scolta\Index\Tokenizer;

/**
 * Process a single chunk of content through the PHP indexer.
 *
 * @since 0.2.0 (rewritten 0.3.0 to use BuildCoordinator)
 *
 * @stability experimental
 */
class ProcessIndexChunk implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $chunkIdx,
        public readonly array $items,
        public readonly int $totalPages,
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
        $coordinator = new BuildCoordinator($this->stateDir, $this->hmacSecret);

        $tokenizer = new Tokenizer;
        $stemmer = new Stemmer($this->language);
        $builder = new InvertedIndexBuilder($tokenizer, $stemmer);

        $offset = $this->chunkIdx * $budget->chunkSize();
        $partial = $builder->build($this->items, $offset);
        $coordinator->commitChunk($this->chunkIdx, $partial);
    }
}
