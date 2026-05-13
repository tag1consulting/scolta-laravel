<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tag1\Scolta\Binary\PagefindBinary;

/**
 * Rebuild the Pagefind index from existing HTML files.
 *
 * Skips the content export step — runs only the Pagefind CLI.
 * Useful after config changes or Pagefind upgrades when the
 * exported HTML hasn't changed.
 */
class RebuildIndexCommand extends Command
{
    protected $signature = 'scolta:rebuild-index';

    protected $description = 'Rebuild the Pagefind search index from existing HTML files';

    public function handle(): int
    {
        $buildDir = config('scolta.pagefind.build_dir', storage_path('scolta/build'));
        $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));

        $resolver = new PagefindBinary(
            configuredPath: config('scolta.pagefind.binary'),
            projectDir: base_path(),
        );

        $binary = $resolver->resolve();
        if ($binary === null) {
            $this->error($resolver->status()['message']);

            return self::FAILURE;
        }

        if (! is_dir($buildDir)) {
            $this->error("Build directory does not exist: {$buildDir}");

            return self::FAILURE;
        }

        $htmlCount = count(File::glob($buildDir.'/*.html') ?: []);
        if ($htmlCount === 0) {
            $this->error("No HTML files in {$buildDir}. Run scolta:export first.");

            return self::FAILURE;
        }

        if (! is_dir($outputDir)) {
            File::ensureDirectoryExists($outputDir, 0755);
        }

        $this->info("Using Pagefind: {$binary} (resolved via {$resolver->resolvedVia()})");

        $pagefindOutputDir = $outputDir.'/pagefind';
        $cmd = $binary
            .' --site '.escapeshellarg($buildDir)
            .' --output-path '.escapeshellarg($pagefindOutputDir);

        $this->line("  Running: {$cmd}");

        $result = Process::timeout(300)->run($cmd);

        if ($result->successful() && file_exists($pagefindOutputDir.'/pagefind.js')) {
            $fragmentCount = count(File::glob($pagefindOutputDir.'/fragment/*') ?: []);
            Cache::increment('scolta_expand_generation');
            $this->info("Pagefind index rebuilt: {$htmlCount} files, {$fragmentCount} fragments.");

            return self::SUCCESS;
        }

        $this->error('Pagefind build failed.');
        $this->line($result->errorOutput() ?: $result->output());

        return self::FAILURE;
    }
}
