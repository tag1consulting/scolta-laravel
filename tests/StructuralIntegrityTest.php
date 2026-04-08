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
    // PHP syntax
    // -------------------------------------------------------------------

    #[DataProvider('phpFileProvider')]
    public function test_php_syntax(string $file): void
    {
        $output = [];
        exec('php -l '.escapeshellarg($file).' 2>&1', $output, $exitCode);
        $this->assertEquals(0, $exitCode,
            'Syntax error in '.basename($file).': '.implode("\n", $output));
    }

    public static function phpFileProvider(): \Generator
    {
        $root = dirname(__DIR__);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                yield $file->getBasename() => [$file->getPathname()];
            }
        }
        // Also check config and routes.
        foreach (glob($root.'/config/*.php') as $f) {
            yield basename($f) => [$f];
        }
        foreach (glob($root.'/routes/*.php') as $f) {
            yield basename($f) => [$f];
        }
    }

    // -------------------------------------------------------------------
    // Namespace consistency
    // -------------------------------------------------------------------

    #[DataProvider('srcFileProvider')]
    public function test_namespace_matches_path(string $file): void
    {
        $contents = file_get_contents($file);
        if (! preg_match('/^namespace\s+(.+);/m', $contents, $m)) {
            $this->markTestSkipped('No namespace in '.basename($file));
        }

        $namespace = $m[1];
        $relative = str_replace($this->root.'/src/', '', $file);
        $dir = dirname($relative);
        $expected = 'Tag1\\ScoltaLaravel';
        if ($dir !== '.') {
            $expected .= '\\'.str_replace('/', '\\', $dir);
        }

        $this->assertEquals($expected, $namespace,
            'Namespace mismatch in '.basename($file));
    }

    public static function srcFileProvider(): \Generator
    {
        $root = dirname(__DIR__);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                yield $file->getBasename() => [$file->getPathname()];
            }
        }
    }

    // -------------------------------------------------------------------
    // Required files exist
    // -------------------------------------------------------------------

    #[DataProvider('requiredFileProvider')]
    public function test_required_file_exists(string $relativePath): void
    {
        $this->assertFileExists($this->root.'/'.$relativePath);
    }

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
        $scoltaPhpSrc = $this->root.'/../scolta-php/src/';
        if (! is_dir($scoltaPhpSrc)) {
            $this->markTestSkipped('scolta-php not available at sibling path');
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
}
