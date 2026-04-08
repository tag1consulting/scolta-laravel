<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\ScoltaLaravel\Models\ScoltaTracker;
use Tag1\ScoltaLaravel\Services\ContentSource;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * Export content as HTML files for Pagefind indexing.
 *
 * Runs only the content export step — does not build the Pagefind index.
 * Useful for inspecting exported HTML or when separating export from indexing.
 */
class ExportCommand extends Command
{
    protected $signature = 'scolta:export
        {--incremental : Only process content that changed since the last build}';

    protected $description = 'Export content as HTML files for Pagefind indexing';

    public function handle(ContentSource $source): int
    {
        $buildDir = config('scolta.pagefind.build_dir', storage_path('scolta/build'));
        $exporter = new ContentExporter($buildDir);

        if ($this->option('incremental')) {
            $pendingCount = $source->getPendingCount();
            if ($pendingCount === 0) {
                $this->info('No changes pending. Nothing to export.');
                return self::SUCCESS;
            }
            $this->info("Processing {$pendingCount} tracked changes...");
        } else {
            $this->info('Marking all published content for export...');
            $count = ScoltaTracker::markAllForReindex();
            $this->info("  Marked {$count} items.");
            $exporter->prepareOutputDir();
        }

        // Handle deletions.
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

        // Export content.
        $items = $this->option('incremental')
            ? $source->getChangedContent()
            : $source->getPublishedContent();

        $exported = 0;
        $skipped = 0;

        foreach ($items as $item) {
            if ($exporter->export($item)) {
                $exported++;
            } else {
                $skipped++;
            }
        }

        $this->info("Exported: {$exported}, Skipped: {$skipped}");
        $this->info("Output directory: {$buildDir}");

        $source->clearTracker();

        return self::SUCCESS;
    }
}
