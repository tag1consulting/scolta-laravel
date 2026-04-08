<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
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

        $htmlCount = count(glob($buildDir.'/*.html') ?: []);
        if ($htmlCount === 0) {
            $this->error("No HTML files in {$buildDir}. Run scolta:export first.");

            return self::FAILURE;
        }

        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $this->info("Using Pagefind: {$binary} (resolved via {$resolver->resolvedVia()})");

        $cmd = $binary
            .' --site '.escapeshellarg($buildDir)
            .' --output-path '.escapeshellarg($outputDir);

        $this->line("  Running: {$cmd}");

        $result = Process::timeout(300)->run($cmd);

        if ($result->successful() && file_exists($outputDir.'/pagefind.js')) {
            $fragmentCount = count(glob($outputDir.'/fragment/*') ?: []);
            Cache::increment('scolta_expand_generation');
            $this->info("Pagefind index rebuilt: {$htmlCount} files, {$fragmentCount} fragments.");

            return self::SUCCESS;
        }

        $this->error('Pagefind build failed.');
        $this->line($result->errorOutput() ?: $result->output());

        return self::FAILURE;
    }
}
