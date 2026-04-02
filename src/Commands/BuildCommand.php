<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Export\ContentExporter;
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
 * Laravel's command system is beautifully expressive. The $signature
 * string declares options with types and defaults — the framework
 * handles parsing, validation, and help text generation. Compare this
 * to WordPress's WP-CLI where you manually extract flags from $assoc_args.
 *
 * The Process facade (built on Symfony Process) provides a clean API
 * for running the Pagefind binary. Much cleaner than shell_exec().
 */
class BuildCommand extends Command
{
    protected $signature = 'scolta:build
        {--incremental : Only process content that changed since the last build}
        {--skip-pagefind : Export HTML files but don\'t run the Pagefind CLI}';

    protected $description = 'Build or rebuild the Scolta search index';

    public function handle(ContentSource $source): int
    {
        $config = ScoltaConfig::fromArray(ScoltaAiService::flattenConfig(config('scolta', [])));
        $buildDir = config('scolta.pagefind.build_dir', storage_path('scolta/build'));
        $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));
        $binary = config('scolta.pagefind.binary', 'pagefind');

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
            $filepath = rtrim($buildDir, '/') . '/' . $id . '.html';
            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }
        if (count($deletedIds) > 0) {
            $this->info("  Removed " . count($deletedIds) . " deleted items.");
        }

        // Export new/changed content.
        $items = $this->option('incremental')
            ? $source->getChangedContent()
            : $source->getPublishedContent();

        $exported = 0;
        $skipped = 0;

        // Laravel's command output helpers make progress reporting clean.
        if (!$this->option('incremental')) {
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
        return $this->runPagefind($binary, $buildDir, $outputDir);
    }

    /**
     * Run the Pagefind CLI.
     *
     * Uses Laravel's Process facade — cleaner than shell_exec(), with
     * proper timeout handling, output streaming, and exit code checking.
     */
    private function runPagefind(string $binary, string $buildDir, string $outputDir): int
    {
        if (!is_dir($buildDir)) {
            $this->error("Build directory does not exist: {$buildDir}");
            return self::FAILURE;
        }

        $htmlCount = count(glob($buildDir . '/*.html') ?: []);
        if ($htmlCount === 0) {
            $this->error("No HTML files in {$buildDir}. Export content first.");
            return self::FAILURE;
        }

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $cmd = escapeshellcmd($binary)
            . ' --site ' . escapeshellarg($buildDir)
            . ' --output-path ' . escapeshellarg($outputDir);

        $this->line("  Running: {$cmd}");

        $result = Process::timeout(300)->run($cmd);

        if ($result->successful() && file_exists($outputDir . '/pagefind.js')) {
            $fragmentCount = count(glob($outputDir . '/fragment/*') ?: []);
            $this->info("Pagefind index built: {$htmlCount} files, {$fragmentCount} fragments.");
            Cache::increment('scolta_expand_generation');
            return self::SUCCESS;
        }

        $this->error("Pagefind build failed.");
        $this->line($result->errorOutput() ?: $result->output());
        return self::FAILURE;
    }
}
