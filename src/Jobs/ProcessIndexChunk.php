<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Tag1\Scolta\Index\BuildCoordinator;
use Tag1\Scolta\Index\InvertedIndexBuilder;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\Stemmer;
use Tag1\Scolta\Index\Tokenizer;
use Tag1\ScoltaLaravel\Services\ContentItemCodec;

/**
 * Process a single chunk of content through the PHP indexer.
 *
 * The chunk's content items are read from a JSON payload file in the
 * state directory (written by QueueRebuildDispatcher) rather than being
 * embedded in the job payload — full bodyHtml in queue payloads blows up
 * dispatch RAM and exceeds queue-driver payload caps (e.g. SQS's 256 KB).
 * The payload file is deleted after the chunk is committed.
 *
 * @since 0.2.0 (rewritten 0.3.0 to use BuildCoordinator; file-based
 *   payloads since 1.0.4)
 *
 * @stability experimental
 */
class ProcessIndexChunk implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $chunkIdx,
        public readonly string $itemsFile,
        public readonly int $totalPages,
        public readonly string $stateDir,
        public readonly string $outputDir,
        public readonly ?string $hmacSecret = null,
        public readonly string $language = 'en',
        public readonly string $memoryBudget = 'conservative',
        public readonly ?int $chunkSize = null,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $items = ContentItemCodec::decode(File::get($this->itemsFile));

        $budget = MemoryBudget::fromOptions($this->memoryBudget, $this->chunkSize);
        $coordinator = new BuildCoordinator($this->stateDir, $this->hmacSecret);

        $tokenizer = new Tokenizer;
        $stemmer = new Stemmer($this->language);
        $builder = new InvertedIndexBuilder($tokenizer, $stemmer);

        $offset = $this->chunkIdx * $budget->chunkSize();
        $partial = $builder->build($items, $offset);
        $coordinator->commitChunk($this->chunkIdx, $partial);

        File::delete($this->itemsFile);
    }
}
