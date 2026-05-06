<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Tag1\Scolta\Binary\PagefindBinary;
use Tag1\Scolta\Config\MemoryBudgetConfig;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\BuildIntentFactory;
use Tag1\Scolta\Index\IndexBuildOrchestrator;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\ScoltaLaravel\Jobs\FinalizeIndex;
use Tag1\ScoltaLaravel\Jobs\ProcessIndexChunk;
use Tag1\ScoltaLaravel\Models\ScoltaTracker;
use Tag1\ScoltaLaravel\Progress\ArtisanProgressReporter;
use Tag1\ScoltaLaravel\Services\ContentSource;
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
        {--sync : Run synchronously instead of dispatching to queue}
        {--memory-budget= : Memory profile or byte value (conservative, 256M, 1G…). Default: from config.}
        {--chunk-size= : Pages per chunk. Overrides profile default and config setting.}
        {--resume : Resume a previously interrupted PHP index build}
        {--restart : Discard interrupted state and restart the PHP index build}';

    protected $description = 'Build or rebuild the Scolta search index';

    public function handle(ContentSource $source): int
    {
        $config = ScoltaConfig::fromArray(ScoltaAiService::flattenConfig(config('scolta', [])));
        $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));

        // Determine which indexer to use: CLI option overrides config.
        $indexer = $this->resolveIndexer($config);

        if ($indexer === 'php') {
            if ($this->option('sync')) {
                return $this->buildWithPhpIndexer($outputDir);
            }

            return $this->dispatchToQueue($outputDir);
        }

        return $this->buildWithBinary($source, $outputDir);
    }

    /**
     * Resolve the effective indexer backend.
     *
     * Priority: --indexer CLI option > config('scolta.indexer') > 'auto'.
     * When 'auto', prefer the binary if available, fall back to PHP.
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
            $resolver = new PagefindBinary(
                configuredPath: config('scolta.pagefind.binary'),
                projectDir: base_path(),
            );
            $binary = $resolver->resolve();

            if ($binary === null) {
                $message = 'Pagefind binary not found. Using PHP indexer (slower, 14 Snowball languages vs 33+). '
                    .'For faster indexing: npm install -g pagefind';
                $this->warn($message);
                Log::info('[scolta] '.$message);

                return 'php';
            }

            return 'binary';
        }

        return $indexer;
    }

    /**
     * Build the search index using the pure-PHP indexer via IndexBuildOrchestrator.
     *
     * @since 0.2.0 (rewritten 0.3.0)
     *
     * @stability experimental
     */
    private function buildWithPhpIndexer(string $outputDir): int
    {
        $stateDir = storage_path('scolta/state');
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

        $totalCount = $this->gatherItemCount();
        if ($totalCount === 0) {
            $this->warn('No searchable content found. Check scolta.models config.');

            return self::SUCCESS;
        }

        // Stream content one model at a time — no full pre-load into RAM.
        $exporter = new ContentExporter($outputDir);
        $items = $exporter->filterItems($this->streamContentItems());

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

        return self::SUCCESS;
    }

    /**
     * Dispatch index build to the queue.
     *
     * Gathers content from Eloquent models, checks the fingerprint,
     * chunks the items, and dispatches ProcessIndexChunk + FinalizeIndex
     * jobs via Bus::chain(). Each chunk runs as an independent queue job,
     * enabling parallel processing across workers.
     *
     * @since 0.2.0
     *
     * @stability experimental
     */
    private function dispatchToQueue(string $outputDir): int
    {
        $this->info('Dispatching index build to queue...');

        $items = $this->gatherContentItems();

        if (count($items) === 0) {
            $this->warn('No searchable content found. Check scolta.models config.');

            return self::SUCCESS;
        }

        $this->info('  Found '.count($items).' content items.');

        $stateDir = storage_path('scolta/state');
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

        if (! $this->option('force')) {
            $indexer = new PhpIndexer($stateDir, $outputDir, $hmacSecret, $language);
            if ($indexer->shouldBuild($items) === null) {
                $this->info('Content unchanged. Index is up to date (use --force to rebuild).');

                return self::SUCCESS;
            }
        }

        $effectiveChunkSize = $budget->chunkSize();
        $chunks = array_chunk($items, $effectiveChunkSize);
        $jobs = [];

        foreach ($chunks as $idx => $chunk) {
            $jobs[] = new ProcessIndexChunk(
                $idx,
                $chunk,
                count($items),
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

        $this->info('Rebuild dispatched to queue ('.count($chunks).' chunk(s) + finalize).');

        return self::SUCCESS;
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
            $filepath = rtrim($buildDir, '/').'/'.$id.'.html';
            if (file_exists($filepath)) {
                unlink($filepath);
            }
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
     * Count content items across all configured Eloquent models without loading bodies.
     *
     * Uses Model::count() (a single SELECT COUNT query per model) so that
     * gatherItemCount() is O(1) in memory regardless of corpus size.
     *
     * @return int Total count across all configured model classes.
     *
     * @since 0.3.2
     *
     * @stability experimental
     */
    private function gatherItemCount(): int
    {
        $models = config('scolta.models', []);
        $total = 0;

        foreach ($models as $modelClass) {
            if (class_exists($modelClass)) {
                $total += (int) $modelClass::count();
            }
        }

        return $total;
    }

    /**
     * Stream content items from all configured Eloquent models as a generator.
     *
     * Uses Model::cursor() which returns a lazy collection backed by a PDO
     * cursor, hydrating one model at a time. Peak RSS stays bounded because
     * only one model's fields are resident in memory at any given moment.
     *
     * Callers must NOT convert this generator to an array — that restores
     * the pre-0.3.2 eager-load behaviour. Pass it directly to
     * ContentExporter::filterItems() or IndexBuildOrchestrator::build().
     *
     * @return \Generator<ContentItem>
     *
     * @since 0.3.2
     *
     * @stability experimental
     */
    private function streamContentItems(): \Generator
    {
        $models = config('scolta.models', []);
        $siteName = config('scolta.site_name', config('app.name'));

        foreach ($models as $modelClass) {
            if (! class_exists($modelClass)) {
                $this->warn("  Model class not found: {$modelClass}");

                continue;
            }

            foreach ($modelClass::cursor() as $model) {
                if (! method_exists($model, 'toSearchableContent')) {
                    continue;
                }

                $content = $model->toSearchableContent();

                if ($content instanceof ContentItem) {
                    yield $content;
                } else {
                    // Fallback for models returning an array.
                    yield new ContentItem(
                        id: $modelClass.'-'.$model->getKey(),
                        title: $content['title'] ?? '',
                        bodyHtml: $content['body'] ?? '',
                        url: $content['url'] ?? '',
                        date: $model->updated_at?->format('Y-m-d') ?? '',
                        siteName: $siteName,
                    );
                }
            }
        }
    }

    /**
     * Gather content items from all configured Eloquent models.
     *
     * @deprecated 0.3.2 Use streamContentItems() for bounded-memory streaming.
     *   This method is retained for the queue dispatch path which splits items
     *   into discrete jobs and requires an array to chunk.
     *
     * @return ContentItem[]
     *
     * @since 0.2.0
     *
     * @stability experimental
     */
    private function gatherContentItems(): array
    {
        $models = config('scolta.models', []);
        $items = [];
        $siteName = config('scolta.site_name', config('app.name'));

        foreach ($models as $modelClass) {
            if (! class_exists($modelClass)) {
                $this->warn("  Model class not found: {$modelClass}");

                continue;
            }

            foreach ($modelClass::all() as $model) {
                if (method_exists($model, 'toSearchableContent')) {
                    $content = $model->toSearchableContent();

                    // The Searchable trait returns a ContentItem directly.
                    if ($content instanceof ContentItem) {
                        $items[] = $content;
                    } else {
                        // Fallback for models returning an array.
                        $items[] = new ContentItem(
                            id: $modelClass.'-'.$model->getKey(),
                            title: $content['title'] ?? '',
                            bodyHtml: $content['body'] ?? '',
                            url: $content['url'] ?? '',
                            date: $model->updated_at?->format('Y-m-d') ?? '',
                            siteName: $siteName,
                        );
                    }
                }
            }
        }

        return $items;
    }

    /**
     * Run the Pagefind CLI.
     *
     * Uses Laravel's Process facade — cleaner than shell_exec(), with
     * proper timeout handling, output streaming, and exit code checking.
     */
    private function runPagefind(string $binary, string $buildDir, string $outputDir): int
    {
        if (! is_dir($buildDir)) {
            $this->error("Build directory does not exist: {$buildDir}");

            return self::FAILURE;
        }

        $htmlCount = count(glob($buildDir.'/*.html') ?: []);
        if ($htmlCount === 0) {
            $this->error("No HTML files in {$buildDir}. Export content first.");

            return self::FAILURE;
        }

        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $cmd = escapeshellcmd($binary)
            .' --site '.escapeshellarg($buildDir)
            .' --output-path '.escapeshellarg($outputDir);

        $this->line("  Running: {$cmd}");

        $result = Process::timeout(300)->run($cmd);

        if ($result->successful() && file_exists($outputDir.'/pagefind.js')) {
            $fragmentCount = count(glob($outputDir.'/fragment/*') ?: []);
            $this->info("Pagefind index built: {$htmlCount} files, {$fragmentCount} fragments.");
            Cache::increment('scolta_expand_generation');

            return self::SUCCESS;
        }

        $this->error('Pagefind build failed.');
        $this->line($result->errorOutput() ?: $result->output());

        return self::FAILURE;
    }
}
