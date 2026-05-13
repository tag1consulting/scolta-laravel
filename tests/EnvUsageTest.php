<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Verify no env() calls exist outside config/ directory.
 * env() in runtime code breaks after config:cache.
 */
class EnvUsageTest extends TestCase
{
    public function test_no_env_calls_in_src(): void
    {
        $srcDir = dirname(__DIR__).'/src/';
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $lines = file($file->getPathname());
            foreach ($lines as $num => $line) {
                // Match env() calls but not references in comments or docblocks
                if (preg_match('/\benv\s*\(/', $line) && ! preg_match('/^\s*[\/*]/', $line)) {
                    $violations[] = basename($file->getPathname()).':'.($num + 1).' — '.trim($line);
                }
            }
        }

        $this->assertEmpty($violations,
            "env() calls found in src/ (breaks after config:cache):\n".implode("\n", $violations));
    }
}
