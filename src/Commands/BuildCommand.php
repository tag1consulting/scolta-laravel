<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tag1\Scolta\Binary\PagefindBinary;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\ScoltaLaravel\Models\ScoltaTracker;
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
        {--force : Skip fingerprint check and force a full rebuild}';

    protected $description = 'Build or rebuild the Scolta search index';

    public function handle(ContentSource $source): int
    {
        $config = ScoltaConfig::fromArray(ScoltaAiService::flattenConfig(config('scolta', [])));
        $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));

        // Determine which indexer to use: CLI option overrides config.
        $indexer = $this->resolveIndexer($config);

        if ($indexer === 'php') {
            return $this->buildWithPhpIndexer($outputDir);
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
                $this->info('Pagefind binary not found; using PHP indexer.');

                return 'php';
            }

            return 'binary';
        }

        return $indexer;
    }

    /**
     * Build the search index using the pure-PHP PhpIndexer.
     *
     * Gathers content from Eloquent models, creates ContentItems,
     * checks the fingerprint (unless --force), chunks the items,
     * and runs them through PhpIndexer::processChunk() / finalize().
     *
     * @since 0.2.0
     *
     * @stability experimental
     */
    private function buildWithPhpIndexer(string $outputDir): int
    {
        $this->info('Building index with PHP indexer...');

        // Step 1: Gather content from Eloquent models.
        $this->info('Step 1: Gathering content from models...');
        $items = $this->gatherContentItems();

        if (count($items) === 0) {
            $this->warn('No searchable content found. Check scolta.models config.');

            return self::SUCCESS;
        }

        $this->info('  Found '.count($items).' content items.');

        // Step 2: Fingerprint check (unless --force).
        $stateDir = storage_path('scolta/state');
        $hmacSecret = config('app.key');
        $language = config('scolta.ai_languages.0', 'en');

        $indexer = new PhpIndexer($stateDir, $outputDir, $hmacSecret, $language);

        if (! $this->option('force')) {
            $fingerprint = $indexer->shouldBuild($items);
            if ($fingerprint === null) {
                $this->info('Content unchanged. Index is up to date (use --force to rebuild).');

                return self::SUCCESS;
            }
            $this->info('  Content fingerprint changed, rebuilding...');
        } else {
            $this->info('  Forced rebuild (--force), skipping fingerprint check.');
        }

        // Step 3: Chunk and process.
        $chunkSize = 50;
        $chunks = array_chunk($items, $chunkSize);
        $totalPages = count($items);

        $this->info('Step 2: Indexing content ('.count($chunks).' chunks)...');

        $bar = $this->output->createProgressBar(count($chunks));
        $bar->start();

        $totalProcessed = 0;
        foreach ($chunks as $i => $chunk) {
            $processed = $indexer->processChunk($chunk, $i, $totalPages);
            $totalProcessed += $processed;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        // Step 4: Finalize — merge partials and write Pagefind format.
        $this->info('Step 3: Finalizing index...');
        $result = $indexer->finalize();

        if (! $result->success) {
            $this->error('Index build failed: '.($result->error ?? $result->message));

            return self::FAILURE;
        }

        // Write fingerprint state file for next run's shouldBuild() check.
        $fingerprint = PhpIndexer::computeFingerprint($items);
        $stateFile = $outputDir.'/.scolta-state';
        if (! is_dir(dirname($stateFile))) {
            mkdir(dirname($stateFile), 0755, true);
        }
        file_put_contents($stateFile, $fingerprint);

        Cache::increment('scolta_expand_generation');

        $this->info($result->message);
        $this->info(sprintf(
            '  %d pages, %d files in %.3fs',
            $result->pageCount,
            $result->fileCount,
            $result->elapsedSeconds,
        ));

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
     * Gather content items from all configured Eloquent models.
     *
     * Queries each model class listed in config('scolta.models'),
     * calling toSearchableContent() on each instance to produce
     * ContentItem objects for the PHP indexer.
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
