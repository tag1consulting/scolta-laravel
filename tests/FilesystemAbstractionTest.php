<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verify src/ uses File facade instead of raw PHP filesystem functions.
 */
class FilesystemAbstractionTest extends TestCase
{
    /** @var array<string> */
    private array $srcFiles = [];

    protected function setUp(): void
    {
        $srcDir = dirname(__DIR__).'/src/';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $this->srcFiles[] = $file->getPathname();
            }
        }
    }

    public function test_no_raw_file_get_contents(): void
    {
        $this->assertNoRawCall('file_get_contents(');
    }

    public function test_no_raw_file_put_contents(): void
    {
        $this->assertNoRawCall('file_put_contents(');
    }

    public function test_no_raw_unlink(): void
    {
        $this->assertNoRawCall('unlink(');
    }

    public function test_no_suppressed_unlink(): void
    {
        $this->assertNoRawCall('@unlink(');
    }

    public function test_no_raw_mkdir(): void
    {
        $this->assertNoRawCall('mkdir(');
    }

    public function test_no_raw_chmod(): void
    {
        // Match bare chmod( but not File::chmod( or ->chmod(
        $violations = [];
        foreach ($this->srcFiles as $path) {
            $lines = file($path);
            foreach ($lines as $num => $line) {
                if (preg_match('/(?<![:\->])chmod\s*\(/', $line) && ! str_contains($line, 'phpcs:ignore')) {
                    $violations[] = basename($path).':'.($num + 1).' — '.trim($line);
                }
            }
        }
        $this->assertEmpty($violations,
            "Raw chmod() calls found in src/:\n".implode("\n", $violations));
    }

    public function test_no_suppressed_filemtime(): void
    {
        $this->assertNoRawCall('@filemtime(');
    }

    public function test_no_bare_glob(): void
    {
        $violations = [];
        foreach ($this->srcFiles as $path) {
            $lines = file($path);
            foreach ($lines as $num => $line) {
                // Match bare glob( but not File::glob( or ->glob(
                if (preg_match('/(?<![:\->])glob\s*\(/', $line) && ! str_contains($line, 'phpcs:ignore')) {
                    $violations[] = basename($path).':'.($num + 1).' — '.trim($line);
                }
            }
        }

        $this->assertEmpty($violations, "Raw glob() calls in src/:\n".implode("\n", $violations));
    }

    private function assertNoRawCall(string $func): void
    {
        // Build a pattern that matches the func but not File::func or ->func
        $funcName = rtrim($func, '(');
        $violations = [];
        foreach ($this->srcFiles as $path) {
            $lines = file($path);
            foreach ($lines as $num => $line) {
                // Match bare calls but not facade or method calls (File::func or ->func)
                if (preg_match('/(?<![:\->])'.preg_quote($funcName, '/').'\s*\(/', $line)
                    && ! str_contains($line, 'phpcs:ignore')) {
                    $violations[] = basename($path).':'.($num + 1).' — '.trim($line);
                }
            }
        }

        $this->assertEmpty($violations,
            "Raw {$func} calls found in src/:\n".implode("\n", $violations));
    }
}
