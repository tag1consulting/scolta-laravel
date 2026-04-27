<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Http\AiEndpointHandler;
use Tag1\ScoltaLaravel\Cache\LaravelCacheDriver;

/**
 * Tests that LaravelCacheDriver correctly mediates between
 * AiEndpointHandler and Laravel's Cache facade.
 */
class ScoltaCacheBehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        $app = new Application(dirname(__DIR__));
        Container::setInstance($app);
        // Swap the Cache facade to an in-memory array store so tests run
        // without a real database or Redis connection.
        Cache::swap(new Repository(new ArrayStore));
    }

    protected function tearDown(): void
    {
        Cache::clearResolvedInstances();
        Container::setInstance(null);
    }

    // -------------------------------------------------------------------
    // Driver contract — get/set/miss
    // -------------------------------------------------------------------

    public function test_get_returns_null_on_miss(): void
    {
        $driver = new LaravelCacheDriver;
        $this->assertNull($driver->get('nonexistent_key'));
    }

    public function test_set_then_get_returns_value(): void
    {
        $driver = new LaravelCacheDriver;
        $driver->set('test_key', 'hello world', 3600);
        $this->assertEquals('hello world', $driver->get('test_key'));
    }

    public function test_different_keys_are_independent(): void
    {
        $driver = new LaravelCacheDriver;
        $driver->set('key_a', 'value_a', 3600);
        $driver->set('key_b', 'value_b', 3600);
        $this->assertEquals('value_a', $driver->get('key_a'));
        $this->assertEquals('value_b', $driver->get('key_b'));
        $this->assertNull($driver->get('key_c'));
    }

    public function test_set_stores_array_value(): void
    {
        $driver = new LaravelCacheDriver;
        $driver->set('arr_key', ['term1', 'term2', 'term3'], 3600);
        $this->assertEquals(['term1', 'term2', 'term3'], $driver->get('arr_key'));
    }

    // -------------------------------------------------------------------
    // Handler integration — caching hits and misses
    // -------------------------------------------------------------------

    public function test_second_expand_call_uses_cache(): void
    {
        $ai = new LaravelTestMockAiService('["term1","term2","term3"]');
        $driver = new LaravelCacheDriver;
        $handler = new AiEndpointHandler(
            aiService: $ai,
            cache: $driver,
            generation: 1,
            cacheTtl: 3600,
            maxFollowUps: 3,
        );

        $handler->handleExpandQuery('cache test query');
        $handler->handleExpandQuery('cache test query');

        $this->assertEquals(1, $ai->callCount, 'AI service should be called only once — second call serves from cache');
    }

    public function test_cache_ttl_zero_calls_ai_every_time(): void
    {
        $ai = new LaravelTestMockAiService('["term1","term2","term3"]');
        $driver = new LaravelCacheDriver;
        $handler = new AiEndpointHandler(
            aiService: $ai,
            cache: $driver,
            generation: 1,
            cacheTtl: 0,
            maxFollowUps: 3,
        );

        $handler->handleExpandQuery('no cache query');
        $handler->handleExpandQuery('no cache query');

        $this->assertEquals(2, $ai->callCount, 'AI service should be called every time when cacheTtl=0');
    }

    public function test_second_summarize_call_uses_cache(): void
    {
        $ai = new LaravelTestMockAiService('A helpful summary.');
        $driver = new LaravelCacheDriver;
        $handler = new AiEndpointHandler(
            aiService: $ai,
            cache: $driver,
            generation: 1,
            cacheTtl: 3600,
            maxFollowUps: 3,
        );

        $handler->handleSummarize('search query', 'some context text');
        $handler->handleSummarize('search query', 'some context text');

        $this->assertEquals(1, $ai->callCount, 'AI service should be called only once — second summarize call serves from cache');
    }
}

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

class LaravelTestMockAiService
{
    public int $callCount = 0;

    public function __construct(
        private readonly string $response = '',
    ) {}

    public function getExpandPrompt(): string
    {
        return 'Expand the following search query.';
    }

    public function getSummarizePrompt(): string
    {
        return 'Summarize the following search results.';
    }

    public function getFollowUpPrompt(): string
    {
        return 'Continue the conversation.';
    }

    public function message(string $systemPrompt, string $userMessage, int $maxTokens): string
    {
        $this->callCount++;

        return $this->response;
    }

    public function conversation(string $systemPrompt, array $messages, int $maxTokens): string
    {
        $this->callCount++;

        return $this->response;
    }
}
