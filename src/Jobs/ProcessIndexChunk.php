<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Log\Logger;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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
use Tag1\ScoltaLaravel\Services\QueueRebuildDispatcher;

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
 * Pages are numbered from the page-table ledger, as
 * `IndexBuildOrchestrator::makeChunkEntry()` numbers them in a single-process
 * build, so ordinals survive a rebuild and `FinalizeIndex` can tombstone
 * deletions. `allocate()` is a read-modify-write of an in-memory counter, so
 * two writers at once hand one ordinal to two pages, and the merge keeps one.
 * Allocation is single-writer because:
 *
 *  - `Bus::chain()` runs the jobs strictly sequentially.
 *  - `QueueRebuildDispatcher::BUILD_LOCK` is held from dispatch until
 *    `FinalizeIndex` releases it; `scolta:build` takes the same lock. It
 *    expires after `BUILD_LOCK_TTL`, so each job checks the dispatcher still
 *    owns it before allocating.
 *  - {@see self::LEDGER_GUARD_FILE} is a non-blocking `flock()` around the
 *    ledger section, so two chunk jobs running at once fail loudly.
 *
 * @since 0.2.0 (rewritten 0.3.0 to use BuildCoordinator; file-based
 *   payloads since 1.0.4; ledger-allocated ordinals since 1.4.0)
 *
 * @stability experimental
 */
class ProcessIndexChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Lock file, in the state directory, serialising ledger allocation. An
     * advisory `flock()` rather than `Cache::lock()`: no TTL to outlive a long
     * chunk, and the kernel releases it when a killed worker exits.
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
        public readonly ?string $lockOwner = null,
    ) {}

    public function handle(): void
    {
        $this->assertBuildLockHeld();

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
            // The logger carries put()'s manifest-full notice.
            $cache = new PageWordCache(
                $this->stateDir,
                $storage,
                chunkSize: $budget->chunkSize(),
                logger: new Logger(app('log')->driver(), app('events')),
                maxWriteBufferBytes: $budget->tokenCacheChunkBytes(),
                maxManifestEntries: $budget->tokenCacheManifestEntries(),
            );

            /** @var list<array{item: ContentItem, tokenData: array<string, mixed>, ordinal: int}> $entries */
            $entries = [];

            foreach ($items as $item) {
                $hash = PhpIndexer::contentHash($item);

                // Populating the cache is what makes the next edit incremental:
                // IncrementalIndexUpdater refuses without the previous token data.
                $tokenData = $cache->get($hash);
                if ($tokenData === null) {
                    $tokenData = $builder->tokenizeItem($item);
                    if ($tokenData !== null) {
                        $cache->put($hash, $tokenData);
                    }
                }

                if ($tokenData === null) {
                    // No indexable text: never allocate for a page that is not
                    // written, or the integrity check fails.
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

            // Ordinals reach disk before the chunk file that names them.
            // commitIncremental() compacts the journal past 8 MB, bounding
            // what each job replays on construction.
            $ledger->commitIncremental();

            $coordinator->commitChunk($this->chunkIdx, $partial);

            // Never pruneAndSave(): this job saw one chunk of the corpus.
            $cache->saveWithoutPruning();
        } finally {
            self::releaseLedgerGuard($guard);
        }

        File::delete($this->itemsFile);
    }

    /**
     * Take the exclusive ledger guard. Non-blocking: with `tries = 1` a refusal
     * fails the chain and leaves the published index serving.
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
            throw new \RuntimeException("Failed to open the chunk ledger guard at {$path}; refusing to allocate page ordinals without it.");
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new \RuntimeException(
                'Another Scolta chunk job is already allocating page ordinals in '.$this->stateDir.'. '
                .'Chunk jobs share the page-table ledger and must run one at a time. Nothing was written.'
            );
        }

        return $handle;
    }

    /**
     * Refuse to run once the chain has outlived the build lock, which expires
     * after `BUILD_LOCK_TTL`; another dispatch may hold the ledger by then. A
     * null owner means a direct call with no lock to check.
     *
     * @throws \RuntimeException When the dispatcher's owner token no longer holds the lock.
     */
    private function assertBuildLockHeld(): void
    {
        if ($this->lockOwner === null) {
            return;
        }

        // isOwnedByCurrentProcess() is on the concrete Lock, not the contract.
        $lock = Cache::restoreLock(QueueRebuildDispatcher::BUILD_LOCK, $this->lockOwner);
        if (! $lock instanceof Lock || $lock->isOwnedByCurrentProcess()) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'The build lock this chain was dispatched under is no longer held (it expires after %d seconds), '
            .'so another rebuild may be writing the page-table ledger. Refusing to allocate beside it; the '
            .'published index is untouched. Dispatch the rebuild again.',
            QueueRebuildDispatcher::BUILD_LOCK_TTL,
        ));
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
