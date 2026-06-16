<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Services;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\BuildCoordinator;
use Tag1\Scolta\Index\BuildIntent;
use Tag1\Scolta\Index\MemoryBudget;
use Tag1\ScoltaLaravel\Jobs\FinalizeIndex;
use Tag1\ScoltaLaravel\Jobs\ProcessIndexChunk;

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

    public function __construct(private readonly ContentSource $source) {}

    /**
     * Gather published content and dispatch the chunked rebuild chain.
     *
     * Streams items through ContentSource (publish filters applied) and the
     * exporter's minimum-content filter, writes each chunk to a JSON file in
     * {state_dir}/queue-payload/, then dispatches ProcessIndexChunk jobs
     * (one per chunk file) followed by FinalizeIndex.
     *
     * @param  MemoryBudget  $budget  Memory budget driving both dispatcher
     *                                chunking and job offset computation.
     * @param  bool  $force  Skip the fingerprint check and always rebuild.
     * @param  bool  $prepareBuildState  Initialise the build manifest before
     *                                   dispatching so the worker jobs can
     *                                   record chunk progress and FinalizeIndex
     *                                   can find the committed chunks. See the
     *                                   note below — leave false on the
     *                                   observer path.
     * @return array{status: string, items: int, chunks: int}
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    public function dispatch(MemoryBudget $budget, bool $force = false, bool $prepareBuildState = false): array
    {
        $stateDir = config('scolta.state_dir', storage_path('app/scolta'));
        $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));
        $hmacSecret = config('app.key');
        $language = config('scolta.ai_languages.0', 'en');

        $payloadDir = $stateDir.'/queue-payload';
        // Clear leftovers from previous dispatches. The debounce flag and the
        // coordinator's build lock prevent concurrent chains under normal
        // operation; files from a crashed chain must not accumulate forever.
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
        // chunks_written counter when a manifest already exists. FinalizeIndex
        // then reads that counter via BuildCoordinator::chunkFiles(). With no
        // manifest, recordChunk() silently skips the update, chunkFiles()
        // returns empty, and FinalizeIndex fails with "No chunk files found" —
        // so the chain dispatches but never produces an index.
        //
        // prepare() writes the manifest (and clears stale top-level state from
        // a prior build — the queue-payload/ subdirectory is untouched);
        // releaseLockOnly() then drops the process-scoped lock while leaving
        // the manifest in place for the worker jobs.
        //
        // This is opt-in (default off) because prepare() on a fresh intent runs
        // cleanup(), which would wipe an in-flight build's chunk files if a
        // second dispatch arrived mid-chain. The observer path (TriggerRebuild)
        // can fire repeatedly across the debounce window with no serialization
        // against a draining chain, so it must not enable this. The CLI
        // `scolta:build --queue` is a deliberate one-shot deploy action with no
        // concurrent dispatch, so it is safe there.
        if ($prepareBuildState) {
            $coordinator = new BuildCoordinator($stateDir, $hmacSecret);
            $coordinator->prepare(BuildIntent::fresh($total, $budget, [
                'language' => $language,
                'fingerprint' => self::fingerprintFromEntries($fingerprintEntries),
            ]));
            $coordinator->releaseLockOnly();
        }

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

        $jobs[] = new FinalizeIndex(
            $stateDir,
            $outputDir,
            $hmacSecret,
            $language,
            $budget->profile(),
        );

        Bus::chain($jobs)->dispatch();

        return ['status' => self::STATUS_DISPATCHED, 'items' => $total, 'chunks' => count($chunkFiles)];
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
