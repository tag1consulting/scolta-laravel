<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Laravel config file structure.
 */
class ConfigTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        // Set up a minimal Laravel app container so storage_path() and
        // public_path() work when the config file is loaded.
        $app = new \Illuminate\Foundation\Application(dirname(__DIR__));
        \Illuminate\Container\Container::setInstance($app);

        $this->config = require dirname(__DIR__) . '/config/scolta.php';
    }

    protected function tearDown(): void
    {
        \Illuminate\Container\Container::setInstance(null);
    }

    public function testConfigIsArray(): void
    {
        $this->assertIsArray($this->config);
    }

    // -------------------------------------------------------------------
    // AI provider section
    // -------------------------------------------------------------------

    public function testAiProviderDefaults(): void
    {
        $this->assertArrayHasKey('ai_provider', $this->config);
        $this->assertArrayHasKey('ai_api_key', $this->config);
        $this->assertArrayHasKey('ai_model', $this->config);
        $this->assertArrayHasKey('ai_base_url', $this->config);
    }

    public function testAiFeatureToggles(): void
    {
        $this->assertArrayHasKey('ai_expand_query', $this->config);
        $this->assertArrayHasKey('ai_summarize', $this->config);
        $this->assertArrayHasKey('max_follow_ups', $this->config);
    }

    // -------------------------------------------------------------------
    // Site identity
    // -------------------------------------------------------------------

    public function testSiteIdentity(): void
    {
        $this->assertArrayHasKey('site_name', $this->config);
        $this->assertArrayHasKey('site_description', $this->config);
        $this->assertEquals('website', $this->config['site_description']);
    }

    // -------------------------------------------------------------------
    // Searchable models
    // -------------------------------------------------------------------

    public function testModelsIsArray(): void
    {
        $this->assertArrayHasKey('models', $this->config);
        $this->assertIsArray($this->config['models']);
    }

    // -------------------------------------------------------------------
    // Pagefind nested config
    // -------------------------------------------------------------------

    public function testPagefindSection(): void
    {
        $this->assertArrayHasKey('pagefind', $this->config);
        $pf = $this->config['pagefind'];
        $this->assertArrayHasKey('binary', $pf);
        $this->assertArrayHasKey('build_dir', $pf);
        $this->assertArrayHasKey('output_dir', $pf);
        $this->assertArrayHasKey('auto_rebuild', $pf);
    }

    // -------------------------------------------------------------------
    // Scoring nested config
    // -------------------------------------------------------------------

    public function testScoringSection(): void
    {
        $this->assertArrayHasKey('scoring', $this->config);
        $scoring = $this->config['scoring'];

        $expectedKeys = [
            'title_match_boost', 'title_all_terms_multiplier',
            'content_match_boost', 'recency_boost_max',
            'recency_half_life_days', 'recency_penalty_after_days',
            'recency_max_penalty', 'expand_primary_weight',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $scoring, "Missing scoring key: {$key}");
        }
    }

    public function testScoringDefaults(): void
    {
        $scoring = $this->config['scoring'];
        $this->assertEquals(1.0, $scoring['title_match_boost']);
        $this->assertEquals(1.5, $scoring['title_all_terms_multiplier']);
        $this->assertEquals(0.4, $scoring['content_match_boost']);
        $this->assertEquals(0.5, $scoring['recency_boost_max']);
        $this->assertEquals(365, $scoring['recency_half_life_days']);
        $this->assertEquals(1825, $scoring['recency_penalty_after_days']);
        $this->assertEquals(0.3, $scoring['recency_max_penalty']);
        $this->assertEquals(0.7, $scoring['expand_primary_weight']);
    }

    // -------------------------------------------------------------------
    // Display
    // -------------------------------------------------------------------

    public function testDisplayDefaults(): void
    {
        $this->assertEquals(300, $this->config['excerpt_length']);
        $this->assertEquals(10, $this->config['results_per_page']);
        $this->assertEquals(50, $this->config['max_pagefind_results']);
        $this->assertEquals(5, $this->config['ai_summary_top_n']);
        $this->assertEquals(2000, $this->config['ai_summary_max_chars']);
    }

    // -------------------------------------------------------------------
    // Caching and rate limiting
    // -------------------------------------------------------------------

    public function testCacheDefault(): void
    {
        $this->assertArrayHasKey('cache_ttl', $this->config);
        $this->assertEquals(2592000, $this->config['cache_ttl']);
    }

    public function testRateLimitDefault(): void
    {
        $this->assertArrayHasKey('rate_limit', $this->config);
        $this->assertEquals(30, $this->config['rate_limit']);
    }

    // -------------------------------------------------------------------
    // Routes and middleware
    // -------------------------------------------------------------------

    public function testRoutePrefix(): void
    {
        $this->assertEquals('api/scolta/v1', $this->config['route_prefix']);
    }

    public function testMiddleware(): void
    {
        $this->assertArrayHasKey('middleware', $this->config);
        $this->assertContains('api', $this->config['middleware']);
    }

    // -------------------------------------------------------------------
    // Prompt overrides
    // -------------------------------------------------------------------

    public function testPromptOverridesDefaultEmpty(): void
    {
        $this->assertEquals('', $this->config['prompt_expand_query']);
        $this->assertEquals('', $this->config['prompt_summarize']);
        $this->assertEquals('', $this->config['prompt_follow_up']);
    }
}
