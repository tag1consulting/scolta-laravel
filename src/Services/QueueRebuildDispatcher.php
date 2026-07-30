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
use Tag1\ScoltaLaravel\Jobs\FinalizeIndex;
use Tag1\ScoltaLaravel\Jobs\ProcessIndexChunk;
use Tag1\ScoltaLaravel\Support\HmacSecret;

/**
 * Stream content into state-dir chunk files and dispatch the rebuild chain.
 *
 * This is the single queue-dispatch path, shared by the observer-driven
 * TriggerRebuild job and `artisan scolta:build` (non-sync). It exists to
 * fix three defects that lived in two diverging copies of this logic:
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
     * Gather published content and dispatch the chunked rebuild chain.
     *
     * Streams items through ContentSource (publish filters applied) and the
     * exporter's minimum-content filter, writes each chunk to a JSON file in
     * {state_dir}/queue-payload/, then dispatches ProcessIndexChunk jobs
     * (one per chunk file) followed by FinalizeIndex.
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
     * @return array{status: string, items: int, chunks: int}
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    public function dispatch(MemoryBudget $budget, bool $force = false): array
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
                );
            }

            // Hand the lock owner token to FinalizeIndex so the chain's final
            // job releases the cross-process lock when it ends — on success or
            // failure — regardless of which worker process runs it.
            $jobs[] = new FinalizeIndex(
                $stateDir,
                $outputDir,
                $hmacSecret,
                $language,
                $budget->profile(),
                $lock->owner(),
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
     * Per-item fingerprint entry, matching PhpIndexer::computeFingerprint().
     *
     * Streaming makes it impossible to hold all items for a single
     * computeFingerprint() call, so the per-item entries are accumulated
     * here (a few dozen bytes per item) and combined in
     * fingerprintFromEntries(). Byte-parity with scolta-php is pinned by a
     * regression test.
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    public static function fingerprintEntry(ContentItem $item): string
    {
        return $item->id.':'.hash('sha256', $item->bodyHtml);
    }

    /**
     * Combine per-item entries into the corpus fingerprint, matching
     * PhpIndexer::computeFingerprint().
     *
     * @param  string[]  $entries
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    public static function fingerprintFromEntries(array $entries): string
    {
        sort($entries);

        return hash('sha256', 'php-indexer-v1:'.json_encode($entries));
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
