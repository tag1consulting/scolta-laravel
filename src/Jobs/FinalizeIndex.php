<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Tag1\Scolta\Index\PhpIndexer;

/**
 * Finalize the search index after all chunks have been processed.
 *
 * This job runs as the last step in the chain: all ProcessIndexChunk
 * jobs write partial indexes to disk, then FinalizeIndex merges them
 * into the final Pagefind-compatible format and performs an atomic swap.
 *
 * After a successful finalize, the fingerprint state file is written
 * and the cache generation counter is incremented (so cached query
 * expansions are invalidated).
 *
 * @since 0.2.0
 *
 * @stability experimental
 */
class FinalizeIndex implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue;

    /**
     * Create a new finalize job.
     *
     * @param  string  $stateDir  Path to the indexer state directory.
     * @param  string  $outputDir  Path to the index output directory.
     * @param  string|null  $hmacSecret  HMAC secret for chunk integrity.
     * @param  string  $language  Language code for stemming.
     * @param  string|null  $fingerprint  Content fingerprint to write on success.
     *
     * @since 0.2.0
     *
     * @stability experimental
     */
    public function __construct(
        public readonly string $stateDir,
        public readonly string $outputDir,
        public readonly ?string $hmacSecret = null,
        public readonly string $language = 'en',
        public readonly ?string $fingerprint = null,
    ) {}

    /**
     * Merge partial indexes and write the final Pagefind format.
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

        $result = $indexer->finalize();

        if ($result->success) {
            // Write fingerprint state file for next run's shouldBuild() check.
            if ($this->fingerprint !== null) {
                $stateFile = $this->outputDir.'/.scolta-state';
                if (! is_dir(dirname($stateFile))) {
                    mkdir(dirname($stateFile), 0755, true);
                }
                file_put_contents($stateFile, $this->fingerprint);
            }

            Cache::increment('scolta_expand_generation');
        }
    }
}
