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
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\BuildCoordinator;
use Tag1\Scolta\Index\InvertedIndexBuilder;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\PageTableLedger;
use Tag1\Scolta\Index\PageWordCache;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Index\Stemmer;
use Tag1\Scolta\Index\Tokenizer;
use Tag1\Scolta\Storage\FilesystemDriver;
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
 * ## Ordinals
 *
 * This job assigns page ordinals from the durable page-table ledger, exactly
 * as `IndexBuildOrchestrator::makeChunkEntry()` does in a single-process
 * build. It used to number them `chunkIdx * chunkSize + i` instead and never
 * open the ledger at all, which had two consequences. The numbering was a
 * function of the gather order, so it renumbered the whole corpus whenever a
 * page was inserted near the front and invalidated every fragment filename and
 * posting list with it; and the ledger the chain left behind belonged to some
 * other build, which made every queued rebuild following a deletion fail
 * `FinalizeIndex`'s integrity check. The dispatcher worked around the second by
 * discarding the ledger before dispatching, at the price of the incremental
 * update having nothing to update against afterwards.
 *
 * ## Why allocation here does not race
 *
 * The ledger is shared state on disk and `allocate()` is a read-modify-write of
 * an in-memory next-ordinal that the constructor loaded from the snapshot and
 * the journal. Two processes allocating at once therefore hand the same ordinal
 * to two different pages, and `IndexMerger` resolves that by last-write-wins —
 * it drops one page's postings rather than failing. So the design does not
 * allocate concurrently at all:
 *
 *  - `QueueRebuildDispatcher` dispatches these jobs with `Bus::chain()`, which
 *    is strictly sequential: the next job is dispatched by
 *    `Queueable::dispatchNextJobInChain()` only after this one's `handle()`
 *    has returned. Two chunks of one build never overlap.
 *  - `QueueRebuildDispatcher::BUILD_LOCK` is held across the whole chain — from
 *    before the first payload file is written until `FinalizeIndex` releases it
 *    — so no second chain and no `IncrementalIndexUpdate` touches the ledger
 *    while this runs. (`BuildCoordinator::releaseLockOnly()` before dispatch
 *    drops only `BuildState`'s process-scoped flock, which cannot span the
 *    chain; the cross-process cache lock is untouched.)
 *  - {@see self::LEDGER_GUARD_FILE} makes that an enforced invariant rather
 *    than an inherited one: the whole ledger-touching section runs under a
 *    non-blocking `flock()`, so converting the chain to a batch fails loudly
 *    here instead of silently writing an index with colliding ordinals.
 *
 * Beyond that, `PageTableLedger::checkpoint()` takes its own exclusive flock on
 * the journal, so the append is atomic, and `FinalizeIndex` re-reads the
 * journal and re-checks the result against the index before the swap.
 *
 * @since 0.2.0 (rewritten 0.3.0 to use BuildCoordinator; file-based
 *   payloads since 1.0.4; ledger-allocated ordinals since 1.4.0)
 *
 * @stability experimental
 */
class ProcessIndexChunk implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Lock file, in the state directory, serialising ledger allocation.
     *
     * Held for the whole of the ledger-touching section of {@see self::handle()}.
     * An advisory `flock()` rather than a `Cache::lock()`: it needs no
     * lock-capable cache store, it has no TTL to outlive a long chunk, and the
     * kernel releases it when a killed worker's file handle closes.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public const LEDGER_GUARD_FILE = 'queue-chunk-ledger.lock';

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
        $storage = new FilesystemDriver;

        $builder = new InvertedIndexBuilder(new Tokenizer, new Stemmer($this->language));

        $guard = $this->acquireLedgerGuard();

        try {
            $ledger = new PageTableLedger($this->stateDir, $storage);
            // Sized from the same budget the single-process build sizes it
            // from, so the two write interchangeable cache chunks.
            $cache = new PageWordCache(
                $this->stateDir,
                $storage,
                chunkSize: $budget->chunkSize(),
                maxWriteBufferBytes: $budget->tokenCacheChunkBytes(),
                maxManifestEntries: $budget->tokenCacheManifestEntries(),
            );

            /** @var list<array{item: ContentItem, tokenData: array<string, mixed>, ordinal: int}> $entries */
            $entries = [];

            foreach ($items as $item) {
                $hash = PhpIndexer::contentHash($item);

                // The token cache is not an optimisation here, it is what makes
                // the next content edit incremental: IncrementalIndexUpdater
                // locates a changed page's stale postings through the previous
                // token data under the hash the ledger recorded, and refuses
                // outright when it is missing. A chain that indexed without
                // populating it would leave a ledger the updater could read and
                // a cache it could not.
                $tokenData = $cache->get($hash);
                if ($tokenData === null) {
                    $tokenData = $builder->tokenizeItem($item);
                    if ($tokenData !== null) {
                        $cache->put($hash, $tokenData);
                    }
                }

                if ($tokenData === null) {
                    // No indexable text after HTML cleaning. Allocating for it
                    // would leave a live ledger row with no fragment behind it,
                    // which is exactly the disagreement FinalizeIndex's
                    // integrity check refuses to publish — so, as in the
                    // single-process build, the ordinal is assigned after
                    // tokenization and never for a page that is not written.
                    continue;
                }

                $entries[] = [
                    'item' => $item,
                    'tokenData' => $tokenData,
                    'ordinal' => $ledger->allocate(
                        $item->id,
                        $item->url,
                        InvertedIndexBuilder::effectiveFilters($item),
                        InvertedIndexBuilder::effectiveSortable($item),
                        $hash,
                    ),
                ];
            }

            $partial = $builder->buildFromTokenDataWithOrdinals($entries);

            // Ordinals reach disk before the chunk that references them, never
            // after: a chunk file naming ordinals no later process can see gets
            // those same numbers handed to different pages, and the merge keeps
            // one page per ordinal.
            $ledger->checkpoint();

            $coordinator->commitChunk($this->chunkIdx, $partial);

            // saveWithoutPruning(), never pruneAndSave(): this job looked up one
            // chunk of the corpus, so "not looked up here" means "in another
            // chunk", and pruning would delete the token data of every page but
            // this one's.
            $cache->saveWithoutPruning();
        } finally {
            self::releaseLedgerGuard($guard);
        }

        File::delete($this->itemsFile);
    }

    /**
     * Take the exclusive ledger guard for this state directory.
     *
     * Non-blocking, and a refusal is fatal: with `tries = 1` a throw fails the
     * chain and leaves the previously published index serving, where waiting
     * would only postpone the collision.
     *
     * @return resource
     *
     * @throws \RuntimeException When another process is already allocating.
     */
    private function acquireLedgerGuard()
    {
        File::ensureDirectoryExists($this->stateDir);

        $path = $this->stateDir.'/'.self::LEDGER_GUARD_FILE;
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new \RuntimeException(
                "Failed to open the chunk ledger guard at {$path}. Refusing to allocate page ordinals "
                .'without it: two chunk jobs allocating at once hand one ordinal to two pages, and the '
                .'merge resolves that by dropping one of them.'
            );
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new \RuntimeException(
                'Another Scolta chunk job is already allocating page ordinals in '.$this->stateDir.'. '
                .'Chunk jobs share the page-table ledger and must run one at a time — dispatch them as a '
                .'Bus::chain(), which is sequential, not as a batch. Nothing was written and the '
                .'published index is untouched.'
            );
        }

        return $handle;
    }

    /**
     * @param  resource  $handle
     */
    private static function releaseLedgerGuard($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
