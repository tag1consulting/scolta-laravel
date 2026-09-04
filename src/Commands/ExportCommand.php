<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Tag1\Scolta\Export\ContentExporter;
use Tag1\ScoltaLaravel\Models\ScoltaTracker;
use Tag1\ScoltaLaravel\Services\ContentSource;
use Tag1\ScoltaLaravel\Services\ExportDeletions;

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

    public function handle(ContentSource $source, ExportDeletions $deletions): int
    {
        $buildDir = config('scolta.pagefind.build_dir', storage_path('scolta/build'));
        $exporter = new ContentExporter($buildDir);

        $incremental = (bool) $this->option('incremental');

        if ($incremental) {
            $pendingCount = $source->getPendingCount();
            if ($pendingCount === 0) {
                $this->info('No changes pending. Nothing to export.');

                return self::SUCCESS;
            }

            // Before the mode is settled, because its answer can change it. The
            // sweep runs on incremental runs only: a full export empties the
            // output directory first, so there a deleted item is removed by not
            // being re-exported.
            $incremental = $deletions->sweep($exporter, $this)['unresolved'] === [];
            if ($incremental) {
                $this->info("Processing {$pendingCount} tracked changes...");
            }
        }

        if (! $incremental) {
            $this->info('Marking all published content for export...');
            $count = ScoltaTracker::markAllForReindex();
            $this->info("  Marked {$count} items.");
            $exporter->prepareOutputDir();
        }

        // Export content.
        $items = $incremental
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

        if (! $incremental) {
            // The manifest maps ContentItem::$id → export-relative path and is the
            // only thing that finds the file for a later deletion, since exports
            // mirror the item's URL and deleteById()'s flat "{id}.html" fallback
            // never matches. Full runs only: writeManifest() serialises what this
            // process exported, so after an incremental run it would replace the
            // whole mapping with the handful of items that just changed.
            //
            // scolta-drupal has the other half of this fault and not this one: it
            // never calls writeManifest() at all, so PagefindExporter::deleteItem()
            // reads a manifest that is always empty and falls back to a flat
            // filename its own exportItem() does not write. Filed separately.
            $exporter->writeManifest();
        }

        $this->info("Exported: {$exported}, Skipped: {$skipped}");
        $this->info("Output directory: {$buildDir}");

        $source->clearTracker();

        return self::SUCCESS;
    }
}
