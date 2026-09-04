<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tag1\Scolta\Export\ContentExporter;

/**
 * Shared runner for the Pagefind CLI binary.
 *
 * BuildCommand and RebuildIndexCommand previously duplicated this logic
 * with inconsistent escaping (one interpolated the binary unescaped).
 * This runner is the single place that builds the command line (binary
 * escaped via escapeshellcmd, paths via escapeshellarg), validates the
 * build directory, runs with a timeout, and bumps the AI cache generation
 * on success.
 *
 * @since 1.0.4
 *
 * @stability experimental
 */
class PagefindRunner
{
    /**
     * Run the Pagefind CLI against an exported build directory.
     *
     * @param  string  $binary  Resolved path to the Pagefind binary.
     * @param  string  $buildDir  Directory containing exported HTML.
     * @param  string  $outputDir  Index output root ({outputDir}/pagefind).
     * @param  callable(string): void|null  $info  Optional progress-line sink.
     * @return array{success: bool, htmlCount?: int, fragmentCount?: int, error?: string, output?: string}
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    public function run(string $binary, string $buildDir, string $outputDir, ?callable $info = null): array
    {
        if (! is_dir($buildDir)) {
            return ['success' => false, 'error' => "Build directory does not exist: {$buildDir}"];
        }

        $htmlCount = ContentExporter::countHtmlFiles($buildDir);
        if ($htmlCount === 0) {
            return ['success' => false, 'error' => "No HTML files in {$buildDir}. Export content first."];
        }

        if (! is_dir($outputDir)) {
            File::ensureDirectoryExists($outputDir, 0755);
        }

        $pagefindOutputDir = $outputDir.'/pagefind';
        $cmd = escapeshellcmd($binary)
            .' --site '.escapeshellarg($buildDir)
            .' --output-path '.escapeshellarg($pagefindOutputDir);

        if ($info !== null) {
            $info("  Running: {$cmd}");
        }

        $result = Process::timeout(300)->run($cmd);

        if ($result->successful() && file_exists($pagefindOutputDir.'/pagefind.js')) {
            // Read the count Pagefind already wrote into pagefind-entry.json
            // rather than listing one fragment file per indexed page.
            $locator = new IndexLocator;
            $location = $locator->locate($outputDir);
            $fragmentCount = $location !== null ? $locator->indexedPageCount($location) : 0;
            // Content was re-indexed — invalidate all cached AI responses.
            Cache::increment('scolta_expand_generation');

            return ['success' => true, 'htmlCount' => $htmlCount, 'fragmentCount' => $fragmentCount];
        }

        return [
            'success' => false,
            'error' => 'Pagefind build failed.',
            'output' => $result->errorOutput() ?: $result->output(),
        ];
    }
}
