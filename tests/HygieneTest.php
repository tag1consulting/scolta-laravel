<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Source-parse hygiene checks that prevent reintroduction of known bad patterns.
 */
class HygieneTest extends TestCase
{
    public function test_trigger_rebuild_does_not_use_serialize(): void
    {
        $source = file_get_contents(__DIR__.'/../src/Jobs/TriggerRebuild.php');
        $this->assertDoesNotMatchRegularExpression(
            '/\bserialize\s*\(/',
            $source,
            'TriggerRebuild should use json_encode for fingerprinting, not serialize.'
        );
    }

    public function test_file_put_always_checked(): void
    {
        $source = file_get_contents(__DIR__.'/../src/Commands/DownloadPagefindCommand.php');

        // DownloadPagefindCommand writes the .env file — verify it uses File::put
        // and checks the return value.
        $this->assertStringContainsString(
            'File::put(',
            $source,
            'DownloadPagefindCommand must use File::put() for writing .env'
        );

        // File::put return value must be checked (not called as a bare statement).
        preg_match_all('/^\s*File::put\s*\(/m', $source, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$match, $offset]) {
            $preceding = substr($source, max(0, $offset - 100), 100);
            $this->assertMatchesRegularExpression(
                '/(?:if\s*\(|return\s)/',
                $preceding,
                'DownloadPagefindCommand: File::put() at offset '.$offset.' must be wrapped in an error check.'
            );
        }
    }
}
