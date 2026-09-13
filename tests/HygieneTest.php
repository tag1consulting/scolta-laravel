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
}
