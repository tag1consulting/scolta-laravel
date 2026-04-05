<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

class AiServiceTest extends TestCase
{
    private function createService(array $overrides = []): ScoltaAiService
    {
        $config = array_merge([
            'ai_provider' => 'anthropic',
            'ai_api_key' => 'test-key',
            'ai_model' => 'claude-sonnet-4-5-20250929',
            'ai_base_url' => '',
            'site_name' => 'Test Site',
            'site_description' => 'test website',
            'max_follow_ups' => 3,
            'ai_expand_query' => true,
            'ai_summarize' => true,
            'cache_ttl' => 2592000,
            'scoring' => [
                'title_match_boost' => 1.0,
                'title_all_terms_multiplier' => 1.5,
                'content_match_boost' => 0.4,
                'recency_boost_max' => 0.5,
                'recency_half_life_days' => 365,
                'recency_penalty_after_days' => 1825,
                'recency_max_penalty' => 0.3,
                'expand_primary_weight' => 0.7,
            ],
            'excerpt_length' => 300,
            'results_per_page' => 10,
            'max_pagefind_results' => 50,
            'prompt_expand_query' => '',
            'prompt_summarize' => '',
            'prompt_follow_up' => '',
        ], $overrides);

        return new ScoltaAiService($config);
    }

    // -------------------------------------------------------------------
    // Config flattening
    // -------------------------------------------------------------------

    public function testFlattenConfigFlattensNestedArrays(): void
    {
        $nested = [
            'ai_provider' => 'anthropic',
            'scoring' => [
                'title_match_boost' => 2.0,
                'content_match_boost' => 0.8,
            ],
            'pagefind' => [
                'binary' => 'pagefind',
                'build_dir' => '/tmp/build',
            ],
        ];

        $flat = ScoltaAiService::flattenConfig($nested);

        $this->assertEquals('anthropic', $flat['ai_provider']);
        $this->assertEquals(2.0, $flat['title_match_boost']);
        $this->assertEquals(0.8, $flat['content_match_boost']);
        $this->assertEquals('pagefind', $flat['binary']);
        $this->assertEquals('/tmp/build', $flat['build_dir']);
        $this->assertArrayNotHasKey('scoring', $flat);
        $this->assertArrayNotHasKey('pagefind', $flat);
    }

    public function testFlattenConfigPreservesListArrays(): void
    {
        $config = [
            'models' => ['App\\Models\\Post', 'App\\Models\\Page'],
            'middleware' => ['api'],
        ];

        $flat = ScoltaAiService::flattenConfig($config);

        $this->assertEquals(['App\\Models\\Post', 'App\\Models\\Page'], $flat['models']);
        $this->assertEquals(['api'], $flat['middleware']);
    }

    public function testFlattenConfigPreservesScalarValues(): void
    {
        $config = [
            'cache_ttl' => 3600,
            'rate_limit' => 30,
            'site_name' => 'Test',
        ];

        $flat = ScoltaAiService::flattenConfig($config);

        $this->assertEquals(3600, $flat['cache_ttl']);
        $this->assertEquals(30, $flat['rate_limit']);
        $this->assertEquals('Test', $flat['site_name']);
    }

    // -------------------------------------------------------------------
    // Config mapping
    // -------------------------------------------------------------------

    public function testGetConfigReturnsScoltaConfig(): void
    {
        $service = $this->createService();
        $this->assertInstanceOf(ScoltaConfig::class, $service->getConfig());
    }

    public function testConfigMapsProvider(): void
    {
        $service = $this->createService(['ai_provider' => 'openai']);
        $this->assertEquals('openai', $service->getConfig()->aiProvider);
    }

    public function testConfigMapsSiteName(): void
    {
        $service = $this->createService(['site_name' => 'My Laravel App']);
        $this->assertEquals('My Laravel App', $service->getConfig()->siteName);
    }

    public function testConfigFlattensNestedScoring(): void
    {
        $service = $this->createService([
            'scoring' => [
                'title_match_boost' => 3.0,
                'recency_half_life_days' => 90,
            ],
        ]);

        $config = $service->getConfig();
        $this->assertEquals(3.0, $config->titleMatchBoost);
        $this->assertEquals(90, $config->recencyHalfLifeDays);
    }

    public function testConfigMapsDisplaySettings(): void
    {
        $service = $this->createService([
            'excerpt_length' => 500,
            'results_per_page' => 25,
        ]);

        $config = $service->getConfig();
        $this->assertEquals(500, $config->excerptLength);
        $this->assertEquals(25, $config->resultsPerPage);
    }

    // -------------------------------------------------------------------
    // Prompt resolution
    // -------------------------------------------------------------------

    public function testCustomPromptOverrideUsed(): void
    {
        $service = $this->createService([
            'prompt_expand_query' => 'Custom expand for {SITE_NAME}',
        ]);

        $this->assertEquals('Custom expand for {SITE_NAME}', $service->getExpandPrompt());
    }

    public function testCustomSummarizePromptUsed(): void
    {
        $service = $this->createService([
            'prompt_summarize' => 'Custom summarize',
        ]);

        $this->assertEquals('Custom summarize', $service->getSummarizePrompt());
    }

    public function testCustomFollowUpPromptUsed(): void
    {
        $service = $this->createService([
            'prompt_follow_up' => 'Custom follow-up',
        ]);

        $this->assertEquals('Custom follow-up', $service->getFollowUpPrompt());
    }

    public function testDefaultPromptDelegatesToWasm(): void
    {
        $service = $this->createService();
        try {
            $prompt = $service->getExpandPrompt();
            $this->assertStringContainsString('Test Site', $prompt);
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('Extism runtime not available');
        }
    }

    // -------------------------------------------------------------------
    // Laravel AI SDK detection
    // -------------------------------------------------------------------

    public function testHasLaravelAiSdkReturnsFalse(): void
    {
        $service = $this->createService();
        $this->assertFalse($service->hasLaravelAiSdk());
    }
}
