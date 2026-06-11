<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Structural integrity and rename validation for scolta-laravel.
 */
class StructuralIntegrityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    // -------------------------------------------------------------------
    // Required files exist
    // -------------------------------------------------------------------

    #[DataProvider('requiredFileProvider')]
    public function test_required_file_exists(string $relativePath): void
    {
        $this->assertFileExists($this->root.'/'.$relativePath);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function requiredFileProvider(): array
    {
        return [
            'composer.json' => ['composer.json'],
            'config' => ['config/scolta.php'],
            'routes' => ['routes/api.php'],
            'migration' => ['database/migrations/2026_04_01_000001_create_scolta_tracker_table.php'],
            'blade component' => ['resources/views/components/search.blade.php'],
            'ServiceProvider' => ['src/ScoltaServiceProvider.php'],
            'Searchable trait' => ['src/Searchable.php'],
            'ScoltaTracker model' => ['src/Models/ScoltaTracker.php'],
            'ScoltaAiService' => ['src/Services/ScoltaAiService.php'],
            'ContentSource' => ['src/Services/ContentSource.php'],
            'ScoltaObserver' => ['src/Observers/ScoltaObserver.php'],
            'ExpandQueryController' => ['src/Http/Controllers/ExpandQueryController.php'],
            'SummarizeController' => ['src/Http/Controllers/SummarizeController.php'],
            'FollowUpController' => ['src/Http/Controllers/FollowUpController.php'],
            'HealthController' => ['src/Http/Controllers/HealthController.php'],
            'BuildCommand' => ['src/Commands/BuildCommand.php'],
            'StatusCommand' => ['src/Commands/StatusCommand.php'],
            'DownloadPagefindCommand' => ['src/Commands/DownloadPagefindCommand.php'],
        ];
    }

    // -------------------------------------------------------------------
    // Composer package
    // -------------------------------------------------------------------

    public function test_composer_package_name(): void
    {
        $composer = json_decode(file_get_contents($this->root.'/composer.json'), true);
        $this->assertEquals('tag1/scolta-laravel', $composer['name']);
    }

    public function test_composer_requires_scolta_php(): void
    {
        $composer = json_decode(file_get_contents($this->root.'/composer.json'), true);
        $require = $composer['require'] ?? [];
        $this->assertTrue(
            isset($require['tag1/scolta-php']) || isset($require['tag1/scolta']),
            'composer.json should require tag1/scolta-php'
        );
    }

    public function test_illuminate_constraints_include_laravel_13(): void
    {
        // CI pins packages to a single version before running tests; read the committed
        // composer.json from git HEAD so the assertion always checks the authored constraint.
        $gitJson = trim((string) @shell_exec('git show HEAD:composer.json 2>/dev/null'));
        $source = ($gitJson !== '' && str_starts_with($gitJson, '{'))
            ? $gitJson
            : file_get_contents($this->root.'/composer.json');

        $composer = json_decode($source, true);
        $require = $composer['require'] ?? [];

        foreach (['illuminate/support', 'illuminate/console', 'illuminate/database', 'illuminate/routing'] as $pkg) {
            $constraint = $require[$pkg] ?? '';
            $this->assertStringContainsString(
                '^13',
                $constraint,
                "{$pkg} constraint must include ^13.0 to support Laravel 13 (got: {$constraint})"
            );
        }
    }

    public function test_composer_auto_discovery(): void
    {
        $composer = json_decode(file_get_contents($this->root.'/composer.json'), true);
        $providers = $composer['extra']['laravel']['providers'] ?? [];
        $this->assertContains(
            'Tag1\\ScoltaLaravel\\ScoltaServiceProvider',
            $providers
        );
    }

    // -------------------------------------------------------------------
    // Rename integrity
    // -------------------------------------------------------------------

    public function test_no_scolta_core_wasm_references(): void
    {
        $stale = $this->grepSourceFiles('/scolta[-_]core[-_]wasm/i');
        $this->assertEmpty($stale,
            "Files still reference scolta-core-wasm:\n".implode("\n", $stale));
    }

    public function test_no_old_package_name(): void
    {
        $stale = $this->grepSourceFiles('/"tag1\/scolta"/');
        $this->assertEmpty($stale,
            "Files reference old package name \"tag1/scolta\":\n".implode("\n", $stale));
    }

    public function test_no_old_vendor_paths(): void
    {
        $stale = $this->grepSourceFiles('/vendor\/tag1\/scolta\//');
        $this->assertEmpty($stale,
            "Files reference old vendor path:\n".implode("\n", $stale));
    }

    // -------------------------------------------------------------------
    // scolta-php imports resolve
    // -------------------------------------------------------------------

    public function test_scolta_php_imports_exist(): void
    {
        $scoltaPhpSrc = null;
        foreach ([$this->root.'/../scolta-php/src/', $this->root.'/vendor/tag1/scolta-php/src/'] as $candidate) {
            if (is_dir($candidate)) {
                $scoltaPhpSrc = $candidate;
                break;
            }
        }
        if ($scoltaPhpSrc === null) {
            $this->markTestSkipped('scolta-php not available at sibling or vendor path');
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root.'/src', \FilesystemIterator::SKIP_DOTS)
        );

        $missing = [];
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            preg_match_all('/^use\s+(Tag1\\\\Scolta\\\\[^;]+);/m', $contents, $matches);

            foreach ($matches[1] as $fqcn) {
                $relative = str_replace('\\', '/', str_replace('Tag1\\Scolta\\', '', $fqcn));
                $expected = $scoltaPhpSrc.$relative.'.php';
                if (! file_exists($expected)) {
                    $missing[] = "{$fqcn} (from ".$file->getBasename().')';
                }
            }
        }

        $this->assertEmpty($missing,
            "Missing scolta-php classes:\n".implode("\n", $missing));
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    private function grepSourceFiles(string $pattern): array
    {
        $hits = [];
        $dirs = [$this->root.'/src', $this->root.'/config', $this->root.'/routes'];
        $exclude = ['vendor', '.git', 'tests', '.phpunit.cache'];

        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                $path = $file->getPathname();
                foreach ($exclude as $ex) {
                    if (str_contains($path, '/'.$ex.'/')) {
                        continue 2;
                    }
                }
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                if (preg_match($pattern, file_get_contents($path))) {
                    $hits[] = str_replace($this->root.'/', '', $path);
                }
            }
        }

        return $hits;
    }

    // -------------------------------------------------------------------
    // Pagefind output subdirectory path consistency
    //
    // The PHP indexer (IndexBuildOrchestrator::atomicSwap) writes the index
    // into $outputDir/pagefind/.  Binary invocations must use the same
    // structure (--output-path $outputDir/pagefind) so every code path that
    // reads the index looks in one place.
    // -------------------------------------------------------------------

    public function test_pagefind_runner_output_path_uses_pagefind_subdir(): void
    {
        // Binary invocation lives in the shared PagefindRunner since the
        // BuildCommand/RebuildIndexCommand dedup.
        $src = file_get_contents($this->root.'/src/Services/PagefindRunner.php');
        $this->assertStringContainsString(
            "'/pagefind'",
            $src,
            'PagefindRunner must append /pagefind to outputDir when invoking the binary.'
        );
        $this->assertStringNotContainsString(
            "--output-path '.escapeshellarg(\$outputDir)",
            $src,
            'PagefindRunner must not pass $outputDir directly to --output-path.'
        );
    }

    public function test_status_command_checks_pagefind_subdir(): void
    {
        $src = file_get_contents($this->root.'/src/Commands/StatusCommand.php');
        $this->assertStringContainsString(
            "'/pagefind/pagefind.js'",
            $src,
            'StatusCommand must check for pagefind.js in the pagefind/ subdirectory.'
        );
        $this->assertStringNotContainsString(
            "'/pagefind.js'",
            $src,
            'StatusCommand must not check for pagefind.js at the flat (non-subdir) path.'
        );
    }

    public function test_health_controller_checks_pagefind_subdir(): void
    {
        $src = file_get_contents($this->root.'/src/Http/Controllers/HealthController.php');
        $this->assertStringContainsString(
            "'/pagefind/pagefind.js'",
            $src,
            'HealthController must check for pagefind.js in the pagefind/ subdirectory.'
        );
        $this->assertStringNotContainsString(
            "'/pagefind.js'",
            $src,
            'HealthController must not check pagefind.js at the flat path.'
        );
    }

    public function test_cleanup_command_checks_pagefind_subdir(): void
    {
        $src = file_get_contents($this->root.'/src/Commands/CleanupCommand.php');
        $this->assertStringContainsString(
            "'/pagefind/pagefind.js'",
            $src,
            'CleanupCommand must check for pagefind.js in the pagefind/ subdirectory.'
        );
        $this->assertStringNotContainsString(
            "'/pagefind.js'",
            $src,
            'CleanupCommand must not check pagefind.js at the flat path.'
        );
    }

    // -------------------------------------------------------------------
    // isExecutable() guard
    // -------------------------------------------------------------------

    public function test_build_command_does_not_call_is_executable(): void
    {
        $source = file_get_contents($this->root.'/src/Commands/BuildCommand.php');
        $this->assertStringNotContainsString(
            'isExecutable()',
            $source,
            'Build command must not call private isExecutable(); use resolve() + status() instead'
        );
    }

    // -------------------------------------------------------------------
    // Code quality infrastructure files
    // -------------------------------------------------------------------

    public function test_gitattributes_exists(): void
    {
        $this->assertFileExists($this->root.'/.gitattributes');
        $content = file_get_contents($this->root.'/.gitattributes');
        $this->assertStringContainsString('tests/', $content);
        $this->assertStringContainsString('export-ignore', $content);
    }

    public function test_phpstan_config_exists(): void
    {
        $this->assertFileExists($this->root.'/phpstan.neon');
        $content = file_get_contents($this->root.'/phpstan.neon');
        $this->assertStringContainsString('larastan', $content);
    }

    public function test_ci_workflow_includes_analyse_job(): void
    {
        $this->assertFileExists($this->root.'/.github/workflows/ci.yml');
        $content = file_get_contents($this->root.'/.github/workflows/ci.yml');
        $this->assertStringContainsString('analyse', $content,
            'CI workflow should include a PHPStan analyse job');
    }

    public function test_release_workflow_has_lock_guard(): void
    {
        $workflow = file_get_contents($this->root.'/.github/workflows/release.yml');
        $this->assertStringContainsString(
            'LOCK GUARD FAILED',
            $workflow,
            'Release workflow must include the scolta-php lock-source guard'
        );
    }

    /**
     * The release is notes-only: Composer resolves this library from Packagist's
     * source zipball, so a vendor-bundled release asset has no consumer. Guard
     * against silently re-adding a custom build artifact or validate-zip job.
     */
    public function test_release_workflow_uploads_no_build_artifact(): void
    {
        $workflow = file_get_contents($this->root.'/.github/workflows/release.yml');
        $this->assertStringNotContainsString(
            'scolta-laravel-',
            $workflow,
            'Release workflow must not build or upload a scolta-laravel-*.zip asset'
        );
        $this->assertStringNotContainsString(
            'validate-zip',
            $workflow,
            'Release workflow must not include a validate-zip job (no release asset to validate)'
        );
        $this->assertStringNotContainsString(
            'files:',
            $workflow,
            'Release workflow must be notes-only (no files: upload to the release)'
        );
    }
}
