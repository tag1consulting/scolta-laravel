<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Tag1\Scolta\Index\PhpIndexer;

/**
 * Process a single chunk of content through the PHP indexer.
 *
 * Each chunk runs as an independent queue job, enabling parallel
 * processing across multiple workers. The PhpIndexer writes partial
 * index files to disk, which FinalizeIndex later merges.
 *
 * This mirrors the chunked pipeline in BuildCommand::buildWithPhpIndexer()
 * but decoupled for queue execution. The state directory and output
 * directory are serialized so the job can reconstruct the PhpIndexer
 * on any worker.
 *
 * @since 0.2.0
 *
 * @stability experimental
 */
class ProcessIndexChunk implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, SerializesModels;

    /**
     * Create a new chunk processing job.
     *
     * @param  int  $chunkIdx  Zero-based chunk index.
     * @param  array  $items  ContentItem objects for this chunk.
     * @param  int  $totalPages  Total page count across all chunks.
     * @param  string  $stateDir  Path to the indexer state directory.
     * @param  string  $outputDir  Path to the index output directory.
     * @param  string|null  $hmacSecret  HMAC secret for chunk integrity.
     * @param  string  $language  Language code for stemming.
     *
     * @since 0.2.0
     *
     * @stability experimental
     */
    public function __construct(
        public readonly int $chunkIdx,
        public readonly array $items,
        public readonly int $totalPages,
        public readonly string $stateDir,
        public readonly string $outputDir,
        public readonly ?string $hmacSecret = null,
        public readonly string $language = 'en',
    ) {}

    /**
     * Process the chunk through PhpIndexer.
     *
     * @since 0.2.0
     *
     * @stability experimental
     */
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $indexer = new PhpIndexer(
            $this->stateDir,
            $this->outputDir,
            $this->hmacSecret,
            $this->language,
        );

        $indexer->processChunk($this->items, $this->chunkIdx, $this->totalPages);
    }
}
