<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Tag1\Scolta\Index\BuildState;

/**
 * Remove stale index artifacts and orphaned build state files.
 *
 * Scans the state directory and Pagefind output directory for files that
 * are no longer referenced by the current build manifest. Stale lock files
 * older than one hour are always removed. Runs in dry-run mode by default
 * with --dry-run to show what would be removed without deleting.
 *
 * @since 0.2.0
 *
 * @stability experimental
 */
class CleanupCommand extends Command
{
    protected $signature = 'scolta:cleanup
        {--dry-run : Show what would be removed without deleting}';

    protected $description = 'Remove stale index artifacts and orphaned build state files';

    /**
     * @since 0.2.0
     *
     * @stability experimental
     */
    public function handle(): int
    {
        $stateDir = config('scolta.state_dir', storage_path('app/scolta'));
        $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));
        $dryRun = (bool) $this->option('dry-run');

        $removed = 0;

        // --- 1. Stale lock file (older than 1 hour) ---
        $lockFile = $stateDir.'/lock';
        if (file_exists($lockFile)) {
            $age = time() - (int) @filemtime($lockFile);
            if ($age > 3600) {
                if ($dryRun) {
                    $this->line("[dry-run] Would remove stale lock: {$lockFile} (age: {$age}s)");
                } else {
                    @unlink($lockFile);
                    $this->line("Removed stale lock: {$lockFile}");
                }
                $removed++;
            }
        }

        // --- 2. Orphaned chunk files not referenced in current manifest ---
        if (is_dir($stateDir)) {
            $state = new BuildState($stateDir);

            // getChunkFiles() returns the chunks the manifest knows about.
            $knownChunks = array_flip($state->getChunkFiles());

            $allChunks = glob($stateDir.'/chunk-*.dat') ?: [];
            foreach ($allChunks as $chunkFile) {
                if (! array_key_exists($chunkFile, $knownChunks)) {
                    if ($dryRun) {
                        $this->line("[dry-run] Would remove orphaned chunk: {$chunkFile}");
                    } else {
                        @unlink($chunkFile);
                        $this->line("Removed orphaned chunk: {$chunkFile}");
                    }
                    $removed++;
                }
            }
        }

        // --- 3. Orphaned fragment files in output directory ---
        // A fragment is orphaned when the output directory exists but the
        // pagefind entry file is gone — the index was partially built.
        if (is_dir($outputDir)) {
            $entryFile = $outputDir.'/pagefind.js';
            if (! file_exists($entryFile)) {
                $orphans = array_merge(
                    glob($outputDir.'/fragment/*.pf_fragment') ?: [],
                    glob($outputDir.'/index/*.pf_index') ?: [],
                );

                foreach ($orphans as $orphan) {
                    if ($dryRun) {
                        $this->line("[dry-run] Would remove orphaned index file: {$orphan}");
                    } else {
                        @unlink($orphan);
                        $this->line("Removed orphaned index file: {$orphan}");
                    }
                    $removed++;
                }
            }
        }

        if ($dryRun) {
            $this->info("Dry run: would remove {$removed} stale file(s).");
        } else {
            $this->info("Cleaned {$removed} stale file(s).");
        }

        return Command::SUCCESS;
    }
}
