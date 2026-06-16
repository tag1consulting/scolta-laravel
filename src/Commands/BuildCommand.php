<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tag1\Scolta\Binary\PagefindBinary;
use Tag1\Scolta\Config\MemoryBudgetConfig;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Index\BuildIntentFactory;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\ScoltaLaravel\Jobs\FinalizeIndex;
use Tag1\ScoltaLaravel\Models\ScoltaTracker;
use Tag1\ScoltaLaravel\Progress\ArtisanProgressReporter;
use Tag1\ScoltaLaravel\Services\AssetStatus;
use Tag1\ScoltaLaravel\Services\ContentSource;
use Tag1\ScoltaLaravel\Services\PagefindRunner;
use Tag1\ScoltaLaravel\Services\QueueRebuildDispatcher;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * Build or rebuild the Scolta search index.
 *
 * This is the Artisan equivalent of `wp scolta build` (WordPress) and
 * `drush scolta:index` (Drupal). Same three-step pipeline:
 *   1. Mark content for indexing
 *   2. Export as HTML with Pagefind attributes
 *   3. Run Pagefind CLI to build the static index
 *
 * When the indexer is set to 'php' (or 'auto' without a binary), the
 * command bypasses the HTML export / Pagefind CLI pipeline and instead
 * gathers content directly from Eloquent models, feeds it through the
 * pure-PHP PhpIndexer, and writes a Pagefind-compatible index.
 *
 * Laravel's command system is beautifully expressive. The $signature
 * string declares options with types and defaults — the framework
 * handles parsing, validation, and help text generation. Compare this
 * to WordPress's WP-CLI where you manually extract flags from $assoc_args.
 *
 * The Process facade (built on Symfony Process) provides a clean API
 * for running the Pagefind binary. Much cleaner than shell_exec().
 *
 * @since 0.2.0
 *
 * @stability experimental
 */
class BuildCommand extends Command
{
    protected $signature = 'scolta:build
        {--incremental : Only process content that changed since the last build}
        {--skip-pagefind : Export HTML files but don\'t run the Pagefind CLI}
        {--indexer=  : Indexer backend: php, binary, or auto (overrides config)}
        {--force : Skip fingerprint check and force a full rebuild}
        {--queue : Dispatch the build to the queue instead of building inline (the index is not built until a worker drains the chain)}
        {--sync : Deprecated no-op: synchronous building is now the default. Accepted for backward compatibility}
        {--memory-budget= : Memory profile or byte value (conservative, 256M, 1G…). Default: from config.}
        {--chunk-size= : Pages per chunk. Overrides profile default and config setting.}
        {--resume : Resume a previously interrupted PHP index build}
        {--restart : Discard interrupted state and restart the PHP index build}';

    protected $description = 'Build or rebuild the Scolta search index';

    /**
     * Exit code for a build that was dispatched to an asynchronous queue and
     * therefore is NOT yet built on disk.
     *
     * Distinct from SUCCESS (0): the index is only enqueued and will not be
     * published until a worker drains the chain. Distinct from FAILURE (1):
     * nothing went wrong — the operator opted into deferred indexing via
     * --queue. Deploy tooling that requires a live index before routing
     * traffic MUST treat any non-zero exit (including this one) as "not built".
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    public const DEFERRED = 3;

    public function handle(ContentSource $source): int
    {
        $config = ScoltaConfig::fromArray(ScoltaAiService::flattenConfig(config('scolta', [])));
        $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));

        // Determine which indexer to use: CLI option overrides config.
        $indexer = $this->resolveIndexer($config);

        if (! in_array($indexer, ['php', 'binary'], true)) {
            $this->error(sprintf('Invalid indexer "%s". Must be one of: auto, php, binary.', $indexer));

            return self::FAILURE;
        }

        if ($indexer === 'php') {
            // Deploy-safe default: build inline and verify before reporting
            // success, so `scolta:build` exiting 0 always means "the index is
            // built and live". Asynchronous indexing is now opt-in via --queue
            // (the content-edit observer keeps using the queue independently).
            //
            // --sync is the former opt-in for this synchronous path; it is now
            // the default, so it is accepted as a no-op alias. The two options
            // are contradictory, so reject the combination rather than guess.
            if ($this->option('queue') && $this->option('sync')) {
                $this->error('--queue and --sync are mutually exclusive. Synchronous building is the default; pass --queue only to defer to the queue.');

                return self::INVALID;
            }

            if ($this->option('queue')) {
                return $this->dispatchToQueue($source, $outputDir);
            }

            return $this->buildWithPhpIndexer($source, $outputDir);
        }

        return $this->buildWithBinary($source, $outputDir);
    }

    /**
     * Resolve the effective indexer backend.
     *
     * Priority: --indexer CLI option > config('scolta.indexer') > 'auto'.
     * When 'auto', always uses the PHP indexer — it works on all PHP hosting
     * environments without exec() or Node.js, uses less memory, and supports
     * fast incremental re-indexing. Set indexer=binary to use the Pagefind
     * binary explicitly.
     *
     * Any value outside auto/php/binary is returned as-is so handle() can
     * reject it with an explicit error — a typo must not silently select
     * a different pipeline.
     *
     * @since 0.2.0
     *
     * @stability experimental
     */
    private function resolveIndexer(ScoltaConfig $config): string
    {
        $indexer = $this->option('indexer');
        if (empty($indexer)) {
            $indexer = config('scolta.indexer', $config->indexer);
        }

        if ($indexer === 'auto') {
            return 'php';
        }

        return (string) $indexer;
    }

    /**
     * Build the search index using the pure-PHP indexer via IndexBuildOrchestrator.
     *
     * Content is streamed through ContentSource::getPublishedContent() so the
     * documented publish filters (scopeSearchable + shouldBeSearchable) apply,
     * exactly as on the binary and queue paths.
     *
     * @since 0.2.0 (rewritten 0.3.0)
     *
     * @stability experimental
     */
    private function buildWithPhpIndexer(ContentSource $source, string $outputDir): int
    {
        $stateDir = config('scolta.state_dir', storage_path('app/scolta'));
        $hmacSecret = config('app.key');
        $language = config('scolta.ai_languages.0', 'en');
        $budget = MemoryBudgetConfig::fromCliAndConfig(
            $this->option('memory-budget'),
            $this->option('chunk-size'),
            fn () => [
                'profile' => config('scolta.memory_budget.profile', 'conservative'),
                'chunk_size' => config('scolta.memory_budget.chunk_size'),
            ],
        );

        $totalCount = $source->getTotalCount();
        if ($totalCount === 0) {
            $this->warn('No searchable content found. Check scolta.models config.');

            return self::SUCCESS;
        }

        // Stream content one record at a time — no full pre-load into RAM.
        $exporter = new ContentExporter($outputDir);
        $items = $exporter->filterItems($source->getPublishedContent());

        $intent = BuildIntentFactory::fromFlags(
            (bool) $this->option('resume'),
            (bool) $this->option('restart'),
            $totalCount,
            $budget,
        );

        $reporter = new ArtisanProgressReporter($this);
        $logger = new Logger(app('log')->driver(), app('events'));
        $orchestrator = new IndexBuildOrchestrator($stateDir, $outputDir, $hmacSecret, $language);
        $report = $orchestrator->build($intent, $items, $logger, $reporter, force: (bool) $this->option('force'));

        if (! $report->success) {
            if ($report->error === 'memory_abort') {
                if ($report->chunksWritten > 0) {
                    // Voluntary yield: RSS reached 75% of the memory limit mid-build.
                    // Spawn a fresh artisan process to resume; child starts with clean heap.
                    $this->line(sprintf(
                        'Memory pressure after %d chunks (%d pages committed). Spawning resume...',
                        $report->chunksWritten,
                        $report->pagesProcessed,
                    ));

                    return $this->spawnResumeProcess($budget->profile(), $this->option('chunk-size'));
                }

                $this->error('Memory limit hit before any chunks were committed. Reduce --chunk-size or increase memory_limit.');

                return self::FAILURE;
            }

            if ($report->error === 'index_only_complete') {
                // All pages indexed but the merge could not run in this process
                // (heap too fragmented). Finalize must run in a fresh worker
                // with a clean heap.
                $connection = (string) config('queue.default');

                if ($connection === 'sync') {
                    // No async worker exists on the sync connection — dispatching
                    // would merely re-run finalize inline in this same stressed
                    // process. Surface it as a failure with actionable guidance
                    // rather than silently reporting a half-built index.
                    $this->error(sprintf(
                        'All %d pages indexed (%d chunks) but the merge could not complete in this process '
                        .'(insufficient memory) and the queue connection is "sync" (no background worker). '
                        .'Increase the memory budget (--memory-budget) or configure an async queue so finalize '
                        .'can run in a fresh worker.',
                        $report->pagesProcessed,
                        $report->chunksWritten,
                    ));

                    return self::FAILURE;
                }

                // Async connection: hand finalize to a worker with a clean heap.
                // The index is NOT published until that worker runs, so this is
                // a deferred build, not a success.
                $this->line(sprintf(
                    'All %d pages indexed (%d chunks). Dispatching finalize to the "%s" queue...',
                    $report->pagesProcessed,
                    $report->chunksWritten,
                    $connection,
                ));
                FinalizeIndex::dispatch($stateDir, $outputDir, $hmacSecret, $language, $budget->profile());

                $this->warn(sprintf(
                    'Index NOT yet published: the finalize job is queued on "%s" and must be drained by a worker '
                    .'(php artisan queue:work) before search reflects this build.',
                    $connection,
                ));

                $this->publishAssets();

                return self::DEFERRED;
            }

            $this->error('Index build failed: '.($report->error ?? 'Unknown error'));

            return self::FAILURE;
        }

        Cache::increment('scolta_expand_generation');
        $this->info(sprintf(
            'Index built: %d pages in %.3fs (%s peak RAM)',
            $report->pagesProcessed,
            $report->durationSeconds,
            $report->peakMemoryMb(),
        ));

        $this->publishAssets();

        return self::SUCCESS;
    }

    /**
     * Spawn a fresh artisan process to resume a memory-aborted build.
     *
     * Starts `php artisan scolta:build --sync --resume` in the background so
     * the child begins with a clean heap. The parent returns immediately after
     * launching the child — the child continues the build independently.
     *
     * @since 1.0.0
     *
     * @stability experimental
     */
    private function spawnResumeProcess(?string $memoryBudget, ?string $chunkSize): int
    {
        $artisan = base_path('artisan');
        if (! file_exists($artisan)) {
            $this->warn('Cannot auto-resume: artisan not found. Run: php artisan scolta:build --sync --resume');

            return self::FAILURE;
        }

        $cmd = PHP_BINARY.' '.escapeshellarg($artisan).' scolta:build --sync --resume';

        if (! empty($memoryBudget)) {
            $cmd .= ' --memory-budget='.escapeshellarg($memoryBudget);
        }
        if (! empty($chunkSize)) {
            $cmd .= ' --chunk-size='.escapeshellarg($chunkSize);
        }

        $logFile = storage_path('logs/scolta-resume.log');

        // Start the process without waiting — orphaned processes survive on Linux/macOS.
        Process::start($cmd.' >> '.escapeshellarg($logFile).' 2>&1');

        $this->line('Resume log: '.$logFile);

        return self::SUCCESS;
    }

    /**
     * Dispatch index build to the queue (opt-in via --queue).
     *
     * Delegates to QueueRebuildDispatcher — the same path the observer-driven
     * TriggerRebuild job uses — which streams content through ContentSource,
     * writes chunk payload files, checks the fingerprint, and dispatches
     * ProcessIndexChunk + FinalizeIndex jobs via Bus::chain().
     *
     * Honesty about whether the index is actually built depends on the
     * effective queue connection:
     *
     *  - On the `sync` connection the chain executes inline during dispatch(),
     *    so the index is built by the time this returns — verify it and report
     *    SUCCESS (or FAILURE if the inline merge failed).
     *  - On any asynchronous connection the jobs are only enqueued; the index
     *    is NOT built until a worker drains the chain. Returning SUCCESS here
     *    is the deploy-time false-success defect, so report DEFERRED with loud
     *    guidance instead.
     *
     * @since 0.2.0 (made connection-aware in 1.0.4)
     *
     * @stability experimental
     */
    private function dispatchToQueue(ContentSource $source, string $outputDir): int
    {
        $connection = (string) config('queue.default');
        $ranInline = $connection === 'sync';

        $this->info('Dispatching index build to queue...');

        $budget = MemoryBudgetConfig::fromCliAndConfig(
            $this->option('memory-budget'),
            $this->option('chunk-size'),
            fn () => [
                'profile' => config('scolta.memory_budget.profile', 'conservative'),
                'chunk_size' => config('scolta.memory_budget.chunk_size'),
            ],
        );

        try {
            // prepareBuildState: true initialises the build manifest so a
            // worker that drains the chain actually produces an index. Safe
            // here because the CLI build is a one-shot action (no concurrent
            // dispatch); the observer path deliberately leaves it false.
            $result = (new QueueRebuildDispatcher($source))->dispatch($budget, (bool) $this->option('force'), prepareBuildState: true);
        } catch (\Throwable $e) {
            // On the sync connection the chain runs inline during dispatch(); a
            // chunk/merge/finalize failure surfaces here. Treat it as a hard
            // build failure rather than letting it bubble out uncaught.
            $this->error('Index build failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($result['status'] === QueueRebuildDispatcher::STATUS_EMPTY) {
            $this->warn('No searchable content found. Check scolta.models config.');

            return self::SUCCESS;
        }

        $this->info('  Found '.$result['items'].' content items.');

        if ($result['status'] === QueueRebuildDispatcher::STATUS_UNCHANGED) {
            $this->info('Content unchanged. Index is up to date (use --force to rebuild).');

            return self::SUCCESS;
        }

        if ($ranInline) {
            // The chain executed inline on the sync connection: the index is
            // built. Verify it before claiming success.
            try {
                IndexBuildOrchestrator::verifyIndexComplete($outputDir);
            } catch (\Throwable $e) {
                $this->error('Index build failed: '.$e->getMessage());

                return self::FAILURE;
            }

            // FinalizeIndex (which just ran inline) already bumped the expand
            // generation counter, so no increment is needed here.
            $this->info(sprintf(
                'Index built inline on the sync queue: %d item(s) (%d chunk(s)).',
                $result['items'],
                $result['chunks'],
            ));

            $this->publishAssets();

            return self::SUCCESS;
        }

        // Asynchronous connection: the jobs are only enqueued. The index is NOT
        // built yet, so this is NOT a success — return DEFERRED so deploy
        // pipelines never mistake "enqueued" for "built and live".
        $this->warn(sprintf(
            'Index NOT yet built: %d chunk(s) + finalize enqueued on the "%s" queue. '
            .'A worker (php artisan queue:work) must drain these jobs before search reflects this build. '
            .'For deploys that need a live index immediately, run `scolta:build` without --queue (synchronous, the default).',
            $result['chunks'],
            $connection,
        ));

        // Publish the front-end assets regardless — they are independent of the
        // index and the runtime endpoints need them whenever the index lands.
        $this->publishAssets();

        return self::DEFERRED;
    }

    /**
     * Build the search index using the Pagefind binary (original pipeline).
     *
     * Three-step pipeline: mark content, export HTML, run Pagefind CLI.
     *
     * @since 0.1.0
     *
     * @stability experimental
     */
    private function buildWithBinary(ContentSource $source, string $outputDir): int
    {
        $buildDir = config('scolta.pagefind.build_dir', storage_path('scolta/build'));
        $resolver = new PagefindBinary(
            configuredPath: config('scolta.pagefind.binary'),
            projectDir: base_path(),
        );

        $exporter = new ContentExporter($buildDir);

        // Step 1: Determine what to index.
        if ($this->option('incremental')) {
            $pendingCount = $source->getPendingCount();
            if ($pendingCount === 0) {
                $this->info('No changes pending. Index is up to date.');

                return self::SUCCESS;
            }
            $this->info("Step 1: Processing {$pendingCount} tracked changes...");
        } else {
            $this->info('Step 1: Marking all published content for reindex...');
            $count = ScoltaTracker::markAllForReindex();
            $this->info("  Marked {$count} items.");

            // Full rebuild: clean the build directory.
            $exporter->prepareOutputDir();
        }

        // Step 2: Export content to HTML.
        $this->info('Step 2: Exporting content to HTML...');

        // Handle deletions first.
        $deletedIds = $source->getDeletedIds();
        foreach ($deletedIds as $id) {
            $exporter->deleteById($id);
        }
        if (count($deletedIds) > 0) {
            $this->info('  Removed '.count($deletedIds).' deleted items.');
        }

        // Export new/changed content.
        $items = $this->option('incremental')
            ? $source->getChangedContent()
            : $source->getPublishedContent();

        $exported = 0;
        $skipped = 0;

        // Laravel's command output helpers make progress reporting clean.
        if (! $this->option('incremental')) {
            $total = $source->getTotalCount();
            $bar = $this->output->createProgressBar($total);
            $bar->start();

            foreach ($items as $item) {
                if ($exporter->export($item)) {
                    $exported++;
                } else {
                    $skipped++;
                }
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
        } else {
            foreach ($items as $item) {
                if ($exporter->export($item)) {
                    $exported++;
                } else {
                    $skipped++;
                }
            }
        }

        $exporter->writeManifest();

        $this->info("  Exported: {$exported}, Skipped (insufficient content): {$skipped}");

        // Clear the tracker after successful export.
        $source->clearTracker();

        // Step 3: Build Pagefind index.
        if ($this->option('skip-pagefind')) {
            $this->info('Export complete. Skipped Pagefind build (--skip-pagefind).');

            return self::SUCCESS;
        }

        $this->info('Step 3: Building Pagefind index...');
        $binary = $resolver->resolve();
        if ($binary === null) {
            $status = $resolver->status();
            $this->error($status['message']);

            return self::FAILURE;
        }
        $this->info("Using Pagefind: {$binary} (resolved via {$resolver->resolvedVia()})");

        return $this->runPagefind($binary, $buildDir, $outputDir);
    }

    /**
     * Run the Pagefind CLI via the shared PagefindRunner.
     */
    private function runPagefind(string $binary, string $buildDir, string $outputDir): int
    {
        $result = (new PagefindRunner)->run($binary, $buildDir, $outputDir, fn (string $line) => $this->line($line));

        if ($result['success']) {
            $this->info("Pagefind index built: {$result['htmlCount']} files, {$result['fragmentCount']} fragments.");

            $this->publishAssets();

            return self::SUCCESS;
        }

        $this->error($result['error']);
        if (! empty($result['output'])) {
            $this->line($result['output']);
        }

        return self::FAILURE;
    }

    private function publishAssets(): void
    {
        // Skip the publish when the published JS already matches the
        // package checksum — no point force-rewriting identical assets
        // (and bumping their mtime-based cache busters) on every build.
        if ((new AssetStatus)->areCurrent() === true) {
            $this->info('Scolta assets already current; skipping publish.');

            return;
        }

        $this->info('Publishing scolta assets...');
        $exitCode = Artisan::call('vendor:publish', [
            '--tag' => 'scolta-assets',
            '--force' => true,
        ]);
        if ($exitCode === 0) {
            $this->info('Assets published successfully.');
        } else {
            $this->warn('Asset publishing returned exit code '.$exitCode);
        }
    }
}
