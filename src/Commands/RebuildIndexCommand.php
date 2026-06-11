<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Commands;

use Illuminate\Console\Command;
use Tag1\Scolta\Binary\PagefindBinary;
use Tag1\ScoltaLaravel\Services\PagefindRunner;

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

        $this->info("Using Pagefind: {$binary} (resolved via {$resolver->resolvedVia()})");

        $result = (new PagefindRunner)->run($binary, $buildDir, $outputDir, fn (string $line) => $this->line($line));

        if ($result['success']) {
            $this->info("Pagefind index rebuilt: {$result['htmlCount']} files, {$result['fragmentCount']} fragments.");

            return self::SUCCESS;
        }

        $this->error($result['error']);
        if (! empty($result['output'])) {
            $this->line($result['output']);
        }

        return self::FAILURE;
    }
}
