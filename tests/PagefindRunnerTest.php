<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Services\PagefindRunner;

/**
 * Shared Pagefind CLI runner: guard paths and single-implementation checks.
 *
 * BuildCommand and RebuildIndexCommand previously duplicated the runner
 * logic with inconsistent escaping — RebuildIndexCommand interpolated the
 * binary path unescaped. Both must now delegate to PagefindRunner, which
 * escapes the binary via escapeshellcmd.
 */
class PagefindRunnerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    public function test_missing_build_dir_returns_error(): void
    {
        $result = (new PagefindRunner)->run('pagefind', storage_path('framework/testing/nope'), storage_path('framework/testing/out'));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Build directory does not exist', $result['error']);
    }

    public function test_empty_build_dir_returns_error(): void
    {
        $buildDir = storage_path('framework/testing/empty-build');
        File::ensureDirectoryExists($buildDir);

        try {
            $result = (new PagefindRunner)->run('pagefind', $buildDir, storage_path('framework/testing/out'));

            $this->assertFalse($result['success']);
            $this->assertStringContainsString('No HTML files', $result['error']);
        } finally {
            File::deleteDirectory($buildDir);
        }
    }

    // -------------------------------------------------------------------
    // Single implementation: both commands delegate, only the runner
    // builds the command line (escaped).
    // -------------------------------------------------------------------

    public function test_runner_escapes_the_binary(): void
    {
        $source = file_get_contents(dirname(__DIR__).'/src/Services/PagefindRunner.php');

        $this->assertStringContainsString('escapeshellcmd($binary)', $source,
            'PagefindRunner must escape the binary path with escapeshellcmd().');
    }

    public function test_commands_delegate_to_the_runner(): void
    {
        foreach (['Commands/BuildCommand.php', 'Commands/RebuildIndexCommand.php'] as $file) {
            $source = file_get_contents(dirname(__DIR__).'/src/'.$file);

            $this->assertStringContainsString('PagefindRunner', $source,
                "{$file} must delegate to PagefindRunner.");
            $this->assertStringNotContainsString("'--site", $source,
                "{$file} must not build its own Pagefind command line.");
            $this->assertStringNotContainsString('$cmd = $binary', $source,
                "{$file} must not interpolate the binary unescaped.");
        }
    }
}
