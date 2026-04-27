<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Source-parse hygiene checks that prevent reintroduction of known bad patterns.
 */
class HygieneTest extends TestCase
{
    public function testTriggerRebuildDoesNotUseSerialize(): void
    {
        $source = file_get_contents(__DIR__ . '/../src/Jobs/TriggerRebuild.php');
        $this->assertDoesNotMatchRegularExpression(
            '/\bserialize\s*\(/',
            $source,
            'TriggerRebuild should use json_encode for fingerprinting, not serialize.'
        );
    }

    public function testFilePutContentsAlwaysChecked(): void
    {
        $source = file_get_contents(__DIR__ . '/../src/Commands/DownloadPagefindCommand.php');

        // Assert the file still contains a file_put_contents call so this test remains meaningful.
        $this->assertMatchesRegularExpression(
            '/\bfile_put_contents\s*\(/',
            $source,
            'DownloadPagefindCommand must still contain a file_put_contents call for this test to be meaningful.'
        );

        // All bare (unwrapped) file_put_contents calls must not exist.
        preg_match_all('/^\s*file_put_contents\s*\(/m', $source, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$match, $offset]) {
            $preceding = substr($source, max(0, $offset - 100), 100);
            $this->assertMatchesRegularExpression(
                '/(?:if\s*\(|return\s)/',
                $preceding,
                'DownloadPagefindCommand: file_put_contents at offset ' . $offset . ' must be wrapped in an error check.'
            );
        }
    }
}
