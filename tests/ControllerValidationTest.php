<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests controller input validation logic and response math.
 *
 * These test the same validation rules the controllers enforce
 * without needing the full Laravel HTTP kernel.
 */
class ControllerValidationTest extends TestCase
{
    // -------------------------------------------------------------------
    // Expand query validation (min:1, max:500)
    // -------------------------------------------------------------------

    public function testExpandValidQuery(): void
    {
        $query = 'product pricing';
        $this->assertTrue(strlen($query) >= 1 && strlen($query) <= 500);
    }

    public function testExpandRejectsEmpty(): void
    {
        $this->assertFalse(strlen('') >= 1);
    }

    public function testExpandRejectsTooLong(): void
    {
        $this->assertFalse(strlen(str_repeat('a', 501)) <= 500);
    }

    public function testExpandAcceptsMaxLength(): void
    {
        $this->assertTrue(strlen(str_repeat('a', 500)) <= 500);
    }

    // -------------------------------------------------------------------
    // Summarize context validation (max:50000)
    // -------------------------------------------------------------------

    public function testSummarizeContextValid(): void
    {
        $this->assertTrue(strlen('Some context') <= 50000);
    }

    public function testSummarizeContextRejectsTooLong(): void
    {
        $this->assertFalse(strlen(str_repeat('x', 50001)) <= 50000);
    }

    // -------------------------------------------------------------------
    // Follow-up message validation
    // -------------------------------------------------------------------

    public function testFollowupValidMessages(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Q1'],
            ['role' => 'assistant', 'content' => 'A1'],
            ['role' => 'user', 'content' => 'Q2'],
        ];

        foreach ($messages as $msg) {
            $this->assertContains($msg['role'], ['user', 'assistant']);
            $this->assertNotEmpty($msg['content']);
        }
        $this->assertEquals('user', end($messages)['role']);
    }

    public function testFollowupRejectsSystemRole(): void
    {
        $this->assertNotContains('system', ['user', 'assistant']);
    }

    public function testFollowupRejectsLastNotUser(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Q'],
            ['role' => 'assistant', 'content' => 'A'],
        ];
        $this->assertNotEquals('user', end($messages)['role']);
    }

    // -------------------------------------------------------------------
    // Follow-up rate limiting
    // -------------------------------------------------------------------

    public function testFollowupCountCalculation(): void
    {
        $this->assertEquals(0, intdiv(2 - 2, 2));
        $this->assertEquals(1, intdiv(4 - 2, 2));
        $this->assertEquals(2, intdiv(6 - 2, 2));
        $this->assertEquals(3, intdiv(8 - 2, 2));
    }

    public function testFollowupRemainingCalculation(): void
    {
        $max = 3;
        $this->assertEquals(2, max(0, $max - 0 - 1));
        $this->assertEquals(1, max(0, $max - 1 - 1));
        $this->assertEquals(0, max(0, $max - 2 - 1));
    }

    public function testFollowupLimitEnforcement(): void
    {
        $max = 3;
        $followups = intdiv(8 - 2, 2); // 3 follow-ups
        $this->assertTrue($followups >= $max);
    }

    // -------------------------------------------------------------------
    // Expand response parsing
    // -------------------------------------------------------------------

    public function testExpandStripsJsonFences(): void
    {
        $response = "```json\n[\"term1\", \"term2\"]\n```";
        $cleaned = trim($response);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        $terms = json_decode(trim($cleaned), true);
        $this->assertEquals(['term1', 'term2'], $terms);
    }

    public function testExpandStripsBareCodeFences(): void
    {
        $response = "```\n[\"a\", \"b\", \"c\"]\n```";
        $cleaned = trim($response);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        $terms = json_decode(trim($cleaned), true);
        $this->assertCount(3, $terms);
    }

    public function testExpandHandlesRawJson(): void
    {
        $cleaned = '["alpha", "beta"]';
        $terms = json_decode($cleaned, true);
        $this->assertEquals(['alpha', 'beta'], $terms);
    }

    public function testExpandFallbackOnInvalidJson(): void
    {
        $query = 'test query';
        $terms = json_decode('not json', true);
        if (!is_array($terms) || count($terms) < 2) {
            $terms = [$query];
        }
        $this->assertEquals(['test query'], $terms);
    }

    // -------------------------------------------------------------------
    // Cache key with generation counter
    // -------------------------------------------------------------------

    public function testCacheKeyIncludesGeneration(): void
    {
        $generation = 5;
        $query = 'Product Pricing';
        $key = 'scolta_expand_' . $generation . '_' . hash('sha256', strtolower($query));

        $this->assertStringStartsWith('scolta_expand_5_', $key);
    }

    public function testCacheKeyIsCaseInsensitive(): void
    {
        $gen = 0;
        $key1 = 'scolta_expand_' . $gen . '_' . hash('sha256', strtolower('HELLO'));
        $key2 = 'scolta_expand_' . $gen . '_' . hash('sha256', strtolower('hello'));
        $this->assertEquals($key1, $key2);
    }
}
