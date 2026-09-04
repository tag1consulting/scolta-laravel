<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Tag1\Scolta\Index\BuildState;
use Tag1\Scolta\Index\RetiredIndexTrash;
use Tag1\Scolta\Storage\FilesystemDriver;
use Tag1\ScoltaLaravel\Support\CommandLogger;

/**
 * Remove stale index artifacts, orphaned build state, and retired indexes.
 *
 * Scans the state directory and Pagefind output directory for files that
 * are no longer referenced by the current build manifest. Stale lock files
 * older than one hour are removed. Deletes by default; pass --dry-run to
 * preview what would be removed without deleting anything.
 *
 * Since scolta-php 1.5.0 a build renames the outgoing index to a
 * `.scolta-trash-*` sibling rather than unlinking it inline during the atomic
 * swap, and the orchestrator sweeps right after publishing. This command is
 * the backstop for a build that died before its own sweep; it also retires a
 * stale `.scolta-old` corpse from an interrupted swap. `.scolta-new` and
 * `.scolta-building` are left alone — a build may be using them right now.
 *
 * `--retired-only` runs that sweep on its own, skipping the state-directory
 * passes. ScoltaServiceProvider schedules the command that way; see
 * sweepStaleFiles() for why an unattended run must not do the rest.
 *
 * @since 0.2.0 (retired-index sweeping since 1.4.0)
 *
 * @stability experimental
 */
class CleanupCommand extends Command
{
    protected $signature = 'scolta:cleanup
        {--dry-run : Show what would be removed without deleting}
        {--retired-only : Sweep retired index directories only; skip the stale-lock and orphaned-file passes}
        {--max-seconds= : Wall-clock budget for deleting retired index directories; 0, or omitted, removes the limit}';

    protected $description = 'Remove stale index artifacts, orphaned build state files, and retired indexes';

    /**
     * @since 0.2.0
     *
     * @stability experimental
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $maxSeconds = $this->resolveBudget();
        if ($maxSeconds === false) {
            return Command::INVALID;
        }

        // Resolved before anything is touched so the state-dir passes below
        // still run when the output directory is misconfigured.
        $outputDir = $this->resolveOutputDir();

        if (! $this->option('retired-only')) {
            $this->sweepStaleFiles($outputDir, $dryRun);
        }

        if ($outputDir === null) {
            return Command::FAILURE;
        }

        // --- 4/5. Retired index directories ---
        return $this->sweepRetiredIndexes($outputDir, $dryRun, $maxSeconds);
    }

    /**
     * Delete the stale lock file, orphaned chunks, and orphaned index fragments.
     *
     * Skipped under `--retired-only`, which is how the scheduled sweep runs it:
     * an unattended process must not touch the build state directory. The stale
     * lock pass in particular unlinks `<state_dir>/lock`, and scolta-php's
     * BuildState keeps that file at a stable path on purpose — unlinking it
     * lets a second process flock a fresh inode at the same path while the
     * first still holds the old one. The heartbeat it ages against is only
     * written per committed chunk, so a long merge/finalize leaves the file
     * untouched for over an hour while the build is very much alive.
     *
     * @param  string|null  $outputDir  Null when the setting resolves to no path.
     */
    private function sweepStaleFiles(?string $outputDir, bool $dryRun): void
    {
        $stateDir = config('scolta.state_dir', storage_path('app/scolta'));
        $removed = 0;

        // --- 1. Stale lock file (older than 1 hour) ---
        $lockFile = $stateDir.'/lock';
        if (file_exists($lockFile)) {
            $age = time() - (int) rescue(fn () => File::lastModified($lockFile), 0);
            if ($age > 3600) {
                if ($dryRun) {
                    $this->line("[dry-run] Would remove stale lock: {$lockFile} (age: {$age}s)");
                } else {
                    File::delete($lockFile);
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

            $allChunks = File::glob($stateDir.'/chunk-*.dat') ?: [];
            foreach ($allChunks as $chunkFile) {
                if (! array_key_exists($chunkFile, $knownChunks)) {
                    if ($dryRun) {
                        $this->line("[dry-run] Would remove orphaned chunk: {$chunkFile}");
                    } else {
                        File::delete($chunkFile);
                        $this->line("Removed orphaned chunk: {$chunkFile}");
                    }
                    $removed++;
                }
            }
        }

        // --- 3. Orphaned fragment files in output directory ---
        // A fragment is orphaned when the output directory exists but the
        // pagefind entry file is gone — the index was partially built.
        if ($outputDir !== null && is_dir($outputDir)) {
            $entryFile = $outputDir.'/pagefind/pagefind.js';
            if (! file_exists($entryFile)) {
                $orphans = array_merge(
                    File::glob($outputDir.'/pagefind/fragment/*.pf_fragment') ?: [],
                    File::glob($outputDir.'/pagefind/index/*.pf_index') ?: [],
                );

                foreach ($orphans as $orphan) {
                    if ($dryRun) {
                        $this->line("[dry-run] Would remove orphaned index file: {$orphan}");
                    } else {
                        File::delete($orphan);
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
    }

    /**
     * Retire a `.scolta-old` corpse and delete every `.scolta-trash-*` sibling.
     *
     * Safe at any time: the live `pagefind/` directory is never touched, and a
     * directory that cannot be deleted, or that the budget did not reach, is
     * left for the next run.
     *
     * @param  float|null  $maxSeconds  Wall-clock budget, or null for no limit.
     */
    private function sweepRetiredIndexes(string $outputDir, bool $dryRun, ?float $maxSeconds): int
    {
        if (! is_dir($outputDir)) {
            // Not an error — a site that has never built an index has no
            // output directory — but a scheduled run's stdout goes nowhere, so
            // this needs to land somewhere durable.
            logger()->info('[scolta] scolta:cleanup found no Pagefind output directory at {dir}; there is nothing to sweep. Build an index, or correct scolta.pagefind.output_dir.', [
                'dir' => $outputDir,
            ]);
            $this->line("No Pagefind output directory at {$outputDir}; nothing to sweep.");

            return Command::SUCCESS;
        }

        $trash = new RetiredIndexTrash(new FilesystemDriver, $outputDir);

        // What an interrupted swap leaves behind: the previously published
        // index, already replaced. Retiring is a rename, so it costs nothing
        // on a huge directory and hands the deletion to the sweep below.
        $oldDir = $outputDir.'/.scolta-old';
        if (is_dir($oldDir)) {
            if ($dryRun) {
                $this->line("[dry-run] Would retire and delete stale directory: {$oldDir}");
            } elseif ($trash->retire($oldDir)) {
                $this->line("Retired stale directory: {$oldDir}");
            } else {
                $this->warn("Could not retire stale directory: {$oldDir}");
            }
        }

        $dirs = $trash->trashDirs();
        if ($dirs === []) {
            $this->info('No retired index directories to delete.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->info(sprintf('Dry run: would delete %d retired index director%s:', count($dirs), count($dirs) === 1 ? 'y' : 'ies'));
            foreach ($dirs as $dir) {
                $this->line("  {$dir}");
            }

            return Command::SUCCESS;
        }

        // sweep() announces the deletion through the logger before it starts,
        // so a multi-minute NFS unlink does not read as a hang; CommandLogger
        // puts that on the terminal as well as in the log.
        $trash->sweep(new CommandLogger($this, logger()), $maxSeconds);

        $remaining = count($trash->trashDirs());
        $deleted = count($dirs) - $remaining;
        $this->info(sprintf('Deleted %d retired index director%s.', $deleted, $deleted === 1 ? 'y' : 'ies'));

        if ($remaining > 0) {
            $this->warn(sprintf(
                '%d retired index director%s still on disk; run scolta:cleanup again to resume.',
                $remaining,
                $remaining === 1 ? 'y' : 'ies',
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * The directory holding the published `pagefind/` directory, or null.
     *
     * Mirrors IndexBuildOrchestrator's own normalization: it appends
     * `/pagefind` internally, so a configured value that already carries the
     * suffix names the same place, and trash sits beside the published index.
     * Returns null when the setting resolves to no path at all, and logs
     * rather than only printing — a scheduled task's stdout goes nowhere, so a
     * misconfiguration that disables cleanup would otherwise leave no trail.
     */
    private function resolveOutputDir(): ?string
    {
        $configured = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));

        if (! is_string($configured) || trim($configured) === '') {
            logger()->warning('[scolta] scolta:cleanup could not resolve scolta.pagefind.output_dir to a path; retired-index cleanup is skipped. Set SCOLTA_OUTPUT_DIR or pagefind.output_dir in config/scolta.php.');
            $this->error('Could not resolve scolta.pagefind.output_dir; retired-index cleanup skipped.');

            return null;
        }

        $outputDir = rtrim(trim($configured), '/');
        if (str_ends_with($outputDir, '/pagefind')) {
            $outputDir = substr($outputDir, 0, -strlen('/pagefind'));
        }

        return $outputDir === '' ? '/' : $outputDir;
    }

    /**
     * The sweep's wall-clock budget: null for no limit, false when invalid.
     *
     * Unbounded when the option is omitted, matching `drush scolta:cleanup`: an
     * operator who runs this by hand wants the job finished. The budget exists
     * for the unattended run, which passes `scolta.cleanup.cron_seconds` here
     * explicitly — an unbounded sweep of a full-corpus index on network storage
     * can hold the scheduler open for as long as the deletion takes.
     */
    private function resolveBudget(): float|null|false
    {
        $option = $this->option('max-seconds');

        if ($option === null) {
            return null;
        }

        if (! is_numeric($option)) {
            $this->error('--max-seconds must be a number of seconds (0 for no limit).');

            return false;
        }

        $seconds = (float) $option;
        if ($seconds < 0) {
            $this->error('--max-seconds cannot be negative (0 for no limit).');

            return false;
        }

        return $seconds > 0 ? $seconds : null;
    }
}
