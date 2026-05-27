<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Cache\NullCacheDriver;
use Tag1\Scolta\Http\AiEndpointHandler;

/**
 * Tests controller validation and response logic via AiEndpointHandler.
 *
 * These exercise the same code paths that the Laravel controllers invoke
 * (validation, rate limiting, cache keys, response parsing) without
 * requiring the HTTP kernel.
 */
class ControllerValidationTest extends TestCase
{
    private function makeHandler(int $maxFollowUps = 3, bool $expandEnabled = true, bool $summarizeEnabled = true): AiEndpointHandler
    {
        $stub = new class {
            public function getExpandPrompt(): string { return 'expand'; }
            public function getSummarizePrompt(): string { return 'summarize'; }
            public function getFollowUpPrompt(): string { return 'followup'; }
            public function messageForOperation(string $op, string $sys, string $user, int $max): string { return '["term1", "term2"]'; }
            public function message(string $sys, string $user, int $max): string { return 'Summary text'; }
            public function conversation(string $sys, array $msgs, int $max): string { return 'Follow-up response'; }
        };

        return new AiEndpointHandler(
            aiService: $stub,
            cache: new NullCacheDriver(),
            generation: 0,
            cacheTtl: 0,
            maxFollowUps: $maxFollowUps,
            aiExpandQuery: $expandEnabled,
            aiSummarize: $summarizeEnabled,
        );
    }

    // -------------------------------------------------------------------
    // Expand query validation (min:1, max:500)
    // -------------------------------------------------------------------

    public function test_expand_valid_query(): void
    {
        $result = $this->makeHandler()->handleExpandQuery('product pricing');
        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('terms', $result['data']);
    }

    public function test_expand_rejects_empty(): void
    {
        $result = $this->makeHandler()->handleExpandQuery('');
        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['status']);
    }

    public function test_expand_rejects_too_long(): void
    {
        $result = $this->makeHandler()->handleExpandQuery(str_repeat('a', 501));
        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['status']);
    }

    public function test_expand_accepts_max_length(): void
    {
        $result = $this->makeHandler()->handleExpandQuery(str_repeat('a', 500));
        $this->assertTrue($result['ok']);
    }

    // -------------------------------------------------------------------
    // Summarize context validation (max:100000 server-side)
    // -------------------------------------------------------------------

    public function test_summarize_context_valid(): void
    {
        $result = $this->makeHandler()->handleSummarize('query', 'Some context');
        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('summary', $result['data']);
    }

    public function test_summarize_context_rejects_too_long(): void
    {
        $result = $this->makeHandler()->handleSummarize('query', str_repeat('x', 100001));
        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['status']);
    }

    // -------------------------------------------------------------------
    // Follow-up message validation
    // -------------------------------------------------------------------

    public function test_followup_valid_messages(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Q1'],
            ['role' => 'assistant', 'content' => 'A1'],
            ['role' => 'user', 'content' => 'Q2'],
        ];

        $result = $this->makeHandler()->handleFollowUp($messages);
        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('response', $result['data']);
        $this->assertArrayHasKey('remaining', $result['data']);
    }

    public function test_followup_rejects_system_role(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are helpful'],
            ['role' => 'user', 'content' => 'Q1'],
        ];

        $result = $this->makeHandler()->handleFollowUp($messages);
        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['status']);
    }

    public function test_followup_rejects_last_not_user(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Q'],
            ['role' => 'assistant', 'content' => 'A'],
        ];

        $result = $this->makeHandler()->handleFollowUp($messages);
        $this->assertFalse($result['ok']);
        $this->assertEquals(400, $result['status']);
    }

    // -------------------------------------------------------------------
    // Follow-up rate limiting
    // -------------------------------------------------------------------

    public function test_followup_count_calculation(): void
    {
        $handler = $this->makeHandler(maxFollowUps: 3);

        // 2 messages = initial exchange, 0 follow-ups → allowed
        $result = $handler->handleFollowUp([
            ['role' => 'user', 'content' => 'Q1'],
        ]);
        $this->assertTrue($result['ok']);

        // 4 messages = 1 follow-up → allowed, remaining=1
        $result = $handler->handleFollowUp([
            ['role' => 'user', 'content' => 'Q1'],
            ['role' => 'assistant', 'content' => 'A1'],
            ['role' => 'user', 'content' => 'Q2'],
            ['role' => 'assistant', 'content' => 'A2'],
            ['role' => 'user', 'content' => 'Q3'],
        ]);
        $this->assertTrue($result['ok']);
        $this->assertEquals(1, $result['data']['remaining']);
    }

    public function test_followup_remaining_calculation(): void
    {
        $handler = $this->makeHandler(maxFollowUps: 3);

        // First exchange: (1 msg - 2) / 2 = 0 follow-ups, remaining = 3-0-1 = 2
        $result = $handler->handleFollowUp([
            ['role' => 'user', 'content' => 'Q1'],
        ]);
        $this->assertTrue($result['ok']);
        $this->assertEquals(2, $result['data']['remaining']);

        // Second exchange: (3 msgs - 2) / 2 = 0 follow-ups still, remaining = 2
        $result = $handler->handleFollowUp([
            ['role' => 'user', 'content' => 'Q1'],
            ['role' => 'assistant', 'content' => 'A1'],
            ['role' => 'user', 'content' => 'Q2'],
        ]);
        $this->assertTrue($result['ok']);
        $this->assertEquals(2, $result['data']['remaining']);
    }

    public function test_followup_limit_enforcement(): void
    {
        $handler = $this->makeHandler(maxFollowUps: 1);

        // 3 messages = (3-2)/2 = 0 follow-ups → still under limit
        $result = $handler->handleFollowUp([
            ['role' => 'user', 'content' => 'Q1'],
            ['role' => 'assistant', 'content' => 'A1'],
            ['role' => 'user', 'content' => 'Q2'],
        ]);
        $this->assertTrue($result['ok']);
        $this->assertEquals(0, $result['data']['remaining']);

        // 5 messages = (5-2)/2 = 1 follow-up → at limit, rejected
        $result = $handler->handleFollowUp([
            ['role' => 'user', 'content' => 'Q1'],
            ['role' => 'assistant', 'content' => 'A1'],
            ['role' => 'user', 'content' => 'Q2'],
            ['role' => 'assistant', 'content' => 'A2'],
            ['role' => 'user', 'content' => 'Q3'],
        ]);
        $this->assertFalse($result['ok']);
        $this->assertEquals(429, $result['status']);
        $this->assertEquals(1, $result['limit']);
    }

    // -------------------------------------------------------------------
    // Expand response parsing
    // -------------------------------------------------------------------

    public function test_expand_strips_json_fences(): void
    {
        $handler = $this->makeHandler();
        $terms = $handler->parseExpansionResponse("```json\n[\"term1\", \"term2\"]\n```", 'fallback');
        $this->assertEquals(['term1', 'term2'], $terms);
    }

    public function test_expand_strips_bare_code_fences(): void
    {
        $handler = $this->makeHandler();
        $terms = $handler->parseExpansionResponse("```\n[\"a\", \"b\", \"c\"]\n```", 'fallback');
        $this->assertCount(3, $terms);
    }

    public function test_expand_handles_raw_json(): void
    {
        $handler = $this->makeHandler();
        $terms = $handler->parseExpansionResponse('["alpha", "beta"]', 'fallback');
        $this->assertEquals(['alpha', 'beta'], $terms);
    }

    public function test_expand_fallback_on_invalid_json(): void
    {
        $handler = $this->makeHandler();
        $terms = $handler->parseExpansionResponse('not json', 'test query');
        $this->assertEquals(['test query'], $terms);
    }

    // -------------------------------------------------------------------
    // Cache key with generation counter
    // -------------------------------------------------------------------

    public function test_cache_key_includes_generation(): void
    {
        $stub = new class {
            public function getExpandPrompt(): string { return ''; }
            public function getSummarizePrompt(): string { return ''; }
            public function getFollowUpPrompt(): string { return ''; }
            public function messageForOperation(string $op, string $sys, string $user, int $max): string { return '[]'; }
            public function message(string $sys, string $user, int $max): string { return ''; }
            public function conversation(string $sys, array $msgs, int $max): string { return ''; }
        };

        $handler = new AiEndpointHandler(
            aiService: $stub,
            cache: new NullCacheDriver(),
            generation: 5,
            cacheTtl: 0,
            maxFollowUps: 3,
        );

        $key = $handler->cacheKey('expand', 'Product Pricing');
        $this->assertStringStartsWith('scolta_expand_5_', $key);
    }

    public function test_cache_key_is_case_insensitive(): void
    {
        $handler = $this->makeHandler();
        $key1 = $handler->cacheKey('expand', 'HELLO');
        $key2 = $handler->cacheKey('expand', 'hello');
        $this->assertEquals($key1, $key2);
    }

    // -------------------------------------------------------------------
    // Feature toggle behavior
    // -------------------------------------------------------------------

    public function test_expand_returns_404_when_disabled(): void
    {
        $handler = $this->makeHandler(expandEnabled: false);
        $result = $handler->handleExpandQuery('test');
        $this->assertFalse($result['ok']);
        $this->assertEquals(404, $result['status']);
    }

    public function test_summarize_returns_404_when_disabled(): void
    {
        $handler = $this->makeHandler(summarizeEnabled: false);
        $result = $handler->handleSummarize('query', 'context');
        $this->assertFalse($result['ok']);
        $this->assertEquals(404, $result['status']);
    }
}
