<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Services;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\BuildCoordinator;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\Scolta\Index\PageTableLedger;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\ScoltaLaravel\Jobs\FinalizeIndex;
use Tag1\ScoltaLaravel\Jobs\ProcessIndexChunk;
use Tag1\ScoltaLaravel\Support\HmacSecret;

/**
 * Apply the tracked changes, or stream the corpus into chunk files and dispatch
 * the rebuild chain.
 *
 * This is the single queue-dispatch path, shared by the observer-driven
 * TriggerRebuild job and `artisan scolta:build --queue`.
 *
 * The content-edit path asks for `$incremental` and gets
 * {@see IncrementalIndexUpdate} first: a save costs an in-place update of the
 * pages that changed, not a stream of the whole corpus. The full chain below is
 * what that update falls back to, and what `scolta:build --queue` always runs.
 * The split is scolta-drupal's — its queue worker tries an incremental update
 * and `drush scolta:build` is always full — and it is the right way round: the
 * operation that fires on every content edit is the cheap one.
 *
 * The full path exists to fix three defects that lived in two diverging copies
 * of this logic:
 *
 *  - Content was gathered via Model::all(), bypassing the documented
 *    scopeSearchable()/shouldBeSearchable() publish filters. All gathering
 *    now flows through ContentSource::getPublishedContent().
 *  - Full bodyHtml content was embedded in ProcessIndexChunk job payloads —
 *    a RAM blowup on dispatch and a queue-driver payload-cap hazard (SQS
 *    caps payloads at 256 KB). Chunks are now streamed to files under the
 *    state directory; jobs carry file references.
 *  - The observer path hardcoded chunk size 50 and dropped the configured
 *    memory budget, so jobs recomputed offsets from the default profile.
 *    Chunking and job parameters now derive from the same MemoryBudget.
 *
 * @since 1.0.4
 *
 * @stability experimental
 */
class QueueRebuildDispatcher
{
    public const STATUS_EMPTY = 'empty';

    public const STATUS_UNCHANGED = 'unchanged';

    public const STATUS_DISPATCHED = 'dispatched';

    /**
     * Returned when the pending changes were applied to the published index in
     * place and no rebuild chain was dispatched — the ordinary outcome of a
     * content edit. See {@see IncrementalIndexUpdate}.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    public const STATUS_UPDATED = 'updated';

    /**
     * Returned when another rebuild chain already holds the cross-process
     * build lock — this dispatch no-ops rather than clobber the in-flight
     * build's chunk state.
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    public const STATUS_IN_PROGRESS = 'in_progress';

    /**
     * Cross-process lock name guarding the whole rebuild chain.
     *
     * Acquired in dispatch() before any build state is touched and held —
     * across separate worker processes — until FinalizeIndex releases it via
     * Cache::restoreLock(). This is the lock that lets every queue entry point
     * (first-run auto-build, the content-edit observer, and CLI --queue) safely
     * initialise the build manifest: BuildState's own flock is process-scoped
     * and gone before the next chained worker job runs, so it cannot serialise
     * a chain that spans processes. Distinct from RebuildNowController's
     * 'scolta_build' HTTP dispatch-guard, which only spans a single request.
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    public const BUILD_LOCK = 'scolta_build_chain';

    /**
     * Build-lock TTL in seconds. Bounds self-healing if a chain dies without
     * releasing the lock (worker OOM, killed process): the lock auto-expires
     * and the next rebuild can proceed. Mirrors BuildState's 1h stale-lock
     * window so the two recovery horizons agree.
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    public const BUILD_LOCK_TTL = 3600;

    public function __construct(private readonly ContentSource $source) {}

    /**
     * Apply the tracked changes, or dispatch the chunked rebuild chain.
     *
     * With `$incremental`, {@see IncrementalIndexUpdate} runs first and returns
     * STATUS_UPDATED when it applied the change set in place — no chain, no
     * corpus stream. Everything it declines falls through to the full path, and
     * the reason is logged.
     *
     * The full path streams items through ContentSource (publish filters
     * applied) and the exporter's minimum-content filter, writes each chunk to a
     * JSON file in {state_dir}/queue-payload/, then dispatches ProcessIndexChunk
     * jobs (one per chunk file) followed by FinalizeIndex — which drains the
     * tracker rows the gather covered.
     *
     * The whole dispatch is serialised by a cross-process build lock
     * (self::BUILD_LOCK). It is acquired before any build state is touched —
     * including the payload-file writes — and held, across separate worker
     * processes, until FinalizeIndex releases it. A second overlapping dispatch
     * (observer debounce race, first-run + a manual scolta:build --queue,
     * concurrent edits) finds the lock held and returns STATUS_IN_PROGRESS
     * without clobbering the in-flight chain's chunk state.
     *
     * @param  MemoryBudget  $budget  Memory budget driving both dispatcher
     *                                chunking and job offset computation.
     * @param  bool  $force  Skip the fingerprint check and always rebuild.
     * @param  bool  $incremental  Try to apply the tracked changes to the
     *                             published index first, and stream the corpus
     *                             only if that update declines. True on the
     *                             content-edit path (`TriggerRebuild` without
     *                             --force); false for `scolta:build --queue`,
     *                             which is always a full build.
     * @param  bool  $resetLedger  Discard the page-table ledger first,
     *                             renumbering from zero (`--reset-ledger` and
     *                             `--restart` under `--queue`).
     * @return array{status: string, items: int, chunks: int}
     *
     * @since 1.0.4 (incremental-first in 1.4.0; $resetLedger in 1.4.0)
     *
     * @stability experimental
     */
    public function dispatch(MemoryBudget $budget, bool $force = false, bool $incremental = false, bool $resetLedger = false): array
    {
        // Acquire the cross-process build lock first, before touching any build
        // state. A non-blocking failure means another chain is already running:
        // no-op rather than race it. The lock is released here on every path
        // that does NOT dispatch a chain (empty / unchanged / a throw before
        // dispatch); once a chain is dispatched, FinalizeIndex owns the release
        // (inline on the sync connection, or in a later worker on an async one).
        $lock = Cache::lock(self::BUILD_LOCK, self::BUILD_LOCK_TTL);
        if (! $lock->get()) {
            return ['status' => self::STATUS_IN_PROGRESS, 'items' => 0, 'chunks' => 0];
        }

        $chainOwnsLock = false;

        try {
            // The cheap path first, and under the same lock: an update rewrites
            // the live index in place, so it must not run beside a chain that is
            // about to swap a new one in. Whatever it declines to do falls
            // through to the full dispatch below, which is why every refusal is
            // logged rather than returned — the operator reading the log needs to
            // know a corpus stream happened and why.
            if ($incremental && ! $force) {
                $outcome = (new IncrementalIndexUpdate($this->source))->attempt($budget);

                if ($outcome['applied']) {
                    logger()->info('[scolta] '.$outcome['summary']);

                    return ['status' => self::STATUS_UPDATED, 'items' => 0, 'chunks' => 0];
                }

                logger()->info('[scolta] Incremental update declined, rebuilding: '.$outcome['reason']);
            }

            $stateDir = config('scolta.state_dir', storage_path('app/scolta'));
            $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));
            // Same empty-APP_KEY coercion as BuildCommand: '' would abort the
            // chain inside hash_init() in a worker process. There is no console
            // to warn on here, so the warning goes to the log, which is where an
            // operator debugging a queued rebuild is already looking.
            $hmacSecret = HmacSecret::normalize(config('app.key'));
            if ($hmacSecret === null) {
                logger()->warning('[scolta] '.HmacSecret::emptyAppKeyWarning());
            }
            $language = config('scolta.ai_languages.0', 'en');

            $payloadDir = $stateDir.'/queue-payload';
            // Clear leftovers from previous dispatches. The build lock above
            // prevents concurrent chains, so a payload dir present here is from
            // a crashed chain and must not accumulate forever.
            File::deleteDirectory($payloadDir);
            File::ensureDirectoryExists($payloadDir);

            $exporter = new ContentExporter($outputDir);
            $chunkSize = $budget->chunkSize();

            $chunkFiles = [];
            $fingerprintEntries = [];
            $buffer = [];
            $total = 0;

            // Read before the corpus is gathered and carried to FinalizeIndex,
            // which drains the tracker when the chain lands. A full build covers
            // every change recorded up to this point by definition; a row written
            // while the chain runs carries a later stamp and is left pending, so
            // the edit it describes is not thrown away. Without this the observer
            // path never drained at all and pending_index grew forever.
            $watermark = $this->source->pendingWatermark();

            foreach ($exporter->filterItems($this->source->getPublishedContent()) as $item) {
                $buffer[] = $item;
                $fingerprintEntries[] = self::fingerprintEntry($item);
                $total++;

                if (count($buffer) >= $chunkSize) {
                    $chunkFiles[] = $this->writeChunk($payloadDir, count($chunkFiles), $buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                $chunkFiles[] = $this->writeChunk($payloadDir, count($chunkFiles), $buffer);
                $buffer = [];
            }

            if ($total === 0) {
                File::deleteDirectory($payloadDir);

                return ['status' => self::STATUS_EMPTY, 'items' => 0, 'chunks' => 0];
            }

            if (! $force && ! $this->contentChanged($outputDir, $fingerprintEntries)) {
                File::deleteDirectory($payloadDir);

                // The published index already has this exact corpus in it, so the
                // rows that asked for this rebuild are satisfied — they describe
                // saves that changed nothing the index holds. Leaving them is how
                // a site accumulates a pending_index backlog that no rebuild can
                // drain, since every subsequent rebuild reaches this same branch.
                $this->source->clearTracker($watermark);

                return ['status' => self::STATUS_UNCHANGED, 'items' => $total, 'chunks' => 0];
            }

            // Initialise the build manifest before dispatching the chain.
            //
            // ProcessIndexChunk::handle() commits each chunk via
            // BuildState::recordChunk(), which only bumps the manifest's
            // chunks_written counter when a manifest already exists.
            // FinalizeIndex then reads that counter via
            // BuildCoordinator::chunkFiles(). With no manifest, recordChunk()
            // silently skips the update, chunkFiles() returns empty, and
            // FinalizeIndex fails with "No chunk files found" — so the chain
            // dispatches but never produces an index.
            //
            // prepare() writes the manifest (and clears stale top-level state
            // from a prior build — the queue-payload/ subdirectory is
            // untouched); releaseLockOnly() then drops the process-scoped flock
            // while leaving the manifest 'building' so the worker jobs (each a
            // separate process) can record chunks and FinalizeIndex can find
            // them. This is the resumable-manifest contract BuildState already
            // exposes; the process-scoped flock cannot span the chain, which is
            // why the cross-process Cache lock above does that job instead.
            //
            // prepare() on a fresh intent runs cleanup() — which would wipe an
            // in-flight build's chunk files if a second dispatch arrived
            // mid-chain — but the BUILD_LOCK above makes that impossible: a
            // concurrent dispatch never reaches this point. So it is safe to
            // prepare unconditionally on every entry point (first-run
            // auto-build, the content-edit observer, and CLI --queue).
            $coordinator = new BuildCoordinator($stateDir, $hmacSecret);
            $coordinator->prepare(BuildIntent::fresh($total, $budget, [
                'language' => $language,
                'fingerprint' => self::fingerprintFromEntries($fingerprintEntries),
            ]));

            // Open the build against the ledger here, once: beginBuild(true)
            // takes the generation every chunk allocates under, so a row left
            // on the previous one is a deletion for releaseStaleRows(). A job
            // calling it would take a generation per chunk.
            $ledger = new PageTableLedger($stateDir, new FilesystemDriver);
            if ($resetLedger) {
                $ledger->reset();
            }
            $ledger->beginBuild(true);
            $ledger->checkpoint();

            $coordinator->releaseLockOnly();

            $jobs = [];
            foreach ($chunkFiles as $idx => $chunkFile) {
                $jobs[] = new ProcessIndexChunk(
                    $idx,
                    $chunkFile,
                    $total,
                    $stateDir,
                    $outputDir,
                    $hmacSecret,
                    $language,
                    $budget->profile(),
                    $budget->chunkSize(),
                    $lock->owner(),
                );
            }

            // The owner token lets FinalizeIndex release the cross-process lock
            // from another worker; the chunk jobs use it to check the lock has
            // not expired under them.
            $jobs[] = new FinalizeIndex(
                $stateDir,
                $outputDir,
                $hmacSecret,
                $language,
                $budget->profile(),
                $lock->owner(),
                $watermark,
            );

            Bus::chain($jobs)->dispatch();
            $chainOwnsLock = true;

            return ['status' => self::STATUS_DISPATCHED, 'items' => $total, 'chunks' => count($chunkFiles)];
        } finally {
            // Release the lock here only when no chain took ownership of it.
            // When a chain WAS dispatched, FinalizeIndex releases it (already
            // ran inline on the sync connection by the time this executes;
            // runs later on an async one). release() on an already-released
            // lock is a harmless no-op, so the guard is belt-and-suspenders.
            if (! $chainOwnsLock) {
                $lock->release();
            }
        }
    }

    /**
     * Per-item fingerprint entry.
     *
     * Streaming makes it impossible to hold all items for a single
     * computeFingerprint() call, so the per-item entries are accumulated
     * here (a few dozen bytes per item) and combined in
     * fingerprintFromEntries(). This used to mirror scolta-php's formula
     * byte for byte — and drifted from it, silently skipping attachment-only
     * edits — so scolta-php 1.5 exposes the formula itself and this
     * delegates. The parity test remains as a regression pin.
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    public static function fingerprintEntry(ContentItem $item): string
    {
        return PhpIndexer::fingerprintEntry($item);
    }

    /**
     * Combine per-item entries into the corpus fingerprint, the same value
     * PhpIndexer::computeFingerprint() produces for the full item array.
     *
     * @param  string[]  $entries
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    public static function fingerprintFromEntries(array $entries): string
    {
        return PhpIndexer::combineFingerprintEntries($entries);
    }

    /**
     * Compare the streamed fingerprint against the stored index state.
     *
     * Mirrors PhpIndexer::shouldBuild(), which requires the full item array
     * and therefore cannot be used on a streaming path.
     *
     * @param  string[]  $fingerprintEntries
     */
    private function contentChanged(string $outputDir, array $fingerprintEntries): bool
    {
        $stateFile = $outputDir.'/.scolta-state';
        if (! File::exists($stateFile)) {
            return true;
        }

        return trim(File::get($stateFile)) !== self::fingerprintFromEntries($fingerprintEntries);
    }

    /**
     * Write one chunk of items to a payload file and return its path.
     *
     * @param  ContentItem[]  $items
     */
    private function writeChunk(string $payloadDir, int $idx, array $items): string
    {
        $path = sprintf('%s/chunk-%04d.json', $payloadDir, $idx);
        File::put($path, ContentItemCodec::encode($items));

        return $path;
    }
}
