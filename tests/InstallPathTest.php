<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests the install → configure path on Laravel.
 *
 * Verifies that Scolta requires no FFI, Extism, or native PHP extensions
 * beyond standard PHP — the core managed hosting compatibility requirement.
 */
class InstallPathTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    // -------------------------------------------------------------------
    // Default paths use storage_path / public_path helpers.
    // -------------------------------------------------------------------

    public function test_default_paths_use_storage_and_public_path(): void
    {
        $config = file_get_contents($this->root.'/config/scolta.php');

        $this->assertStringContainsString(
            'storage_path(',
            $config,
            'build_dir must default to storage_path()'
        );
        $this->assertStringContainsString(
            'public_path(',
            $config,
            'output_dir must default to public_path()'
        );
    }

    // -------------------------------------------------------------------
    // No FFI/Extism dependencies anywhere in package source.
    // -------------------------------------------------------------------

    public function test_source_has_no_ffi_references(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root.'/src', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            $rel = str_replace($this->root.'/', '', $file->getPathname());

            foreach (['ext-ffi', 'Extism', 'extism', 'extension_loaded(\'ffi\')'] as $term) {
                $this->assertStringNotContainsString(
                    $term,
                    $content,
                    "File $rel must not reference removed component \"$term\""
                );
            }
        }
    }

    // -------------------------------------------------------------------
    // All expected Artisan commands exist.
    // -------------------------------------------------------------------

    public function test_artisan_commands_registered(): void
    {
        $commands = [
            'BuildCommand',
            'CheckSetupCommand',
            'ClearCacheCommand',
            'DownloadPagefindCommand',
            'ExportCommand',
            'RebuildIndexCommand',
            'StatusCommand',
        ];

        foreach ($commands as $cmd) {
            $this->assertFileExists(
                $this->root."/src/Commands/$cmd.php",
                "Artisan command $cmd must exist"
            );
        }
    }

    // -------------------------------------------------------------------
    // Config uses env-based defaults (no hardcoded paths).
    // -------------------------------------------------------------------

    public function test_config_uses_env_helpers(): void
    {
        $config = file_get_contents($this->root.'/config/scolta.php');

        // AI key must come from env, not be hardcoded.
        $this->assertStringContainsString(
            "env('SCOLTA_API_KEY'",
            $config,
            'API key must come from SCOLTA_API_KEY env var'
        );

        // Build dir and output dir must be configurable via env.
        $this->assertStringContainsString(
            "env('SCOLTA_BUILD_DIR'",
            $config
        );
        $this->assertStringContainsString(
            "env('SCOLTA_OUTPUT_DIR'",
            $config
        );
    }
}
