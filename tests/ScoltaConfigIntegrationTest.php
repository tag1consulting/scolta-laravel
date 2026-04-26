<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * Integration tests: Laravel config → ScoltaConfig → JS/client output.
 *
 * ConfigTest only verifies the config file structure (keys + defaults).
 * These tests call ScoltaConfig::fromArray() and verify toJsScoringConfig()
 * and toAiClientConfig() output — catching broken mappings that a
 * config-file-only test would miss.
 */
class ScoltaConfigIntegrationTest extends TestCase
{
    private array $rawConfig;

    protected function setUp(): void
    {
        $app = new Application(dirname(__DIR__));
        Container::setInstance($app);
        $this->rawConfig = require dirname(__DIR__).'/config/scolta.php';
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    /**
     * Build a ScoltaConfig by loading the real config file, flattening it
     * the same way ScoltaAiService does, then applying any overrides.
     */
    private function makeConfig(array $overrides = []): ScoltaConfig
    {
        $flat = ScoltaAiService::flattenConfig($this->rawConfig);
        $flat['ai_api_key'] = 'test-key';

        return ScoltaConfig::fromArray(array_merge($flat, $overrides));
    }

    // -------------------------------------------------------------------
    // Scoring — 8 core fields + language + recency_strategy + recency_curve
    // -------------------------------------------------------------------

    public function test_scoring_defaults_reach_js_output(): void
    {
        $js = $this->makeConfig()->toJsScoringConfig();

        $this->assertEquals(1.0, $js['TITLE_MATCH_BOOST']);
        $this->assertEquals(1.5, $js['TITLE_ALL_TERMS_MULTIPLIER']);
        $this->assertEquals(0.4, $js['CONTENT_MATCH_BOOST']);
        $this->assertEquals(0.5, $js['RECENCY_BOOST_MAX']);
        $this->assertEquals(365, $js['RECENCY_HALF_LIFE_DAYS']);
        $this->assertEquals(1825, $js['RECENCY_PENALTY_AFTER_DAYS']);
        $this->assertEquals(0.3, $js['RECENCY_MAX_PENALTY']);
        $this->assertEquals(0.7, $js['EXPAND_PRIMARY_WEIGHT']);
        $this->assertEquals('en', $js['LANGUAGE']);
        $this->assertEquals('exponential', $js['RECENCY_STRATEGY']);
        $this->assertIsArray($js['RECENCY_CURVE']);
    }

    public function test_scoring_overrides_propagate_to_js_output(): void
    {
        $js = $this->makeConfig([
            'title_match_boost' => 5.0,
            'title_all_terms_multiplier' => 3.0,
            'content_match_boost' => 0.9,
            'recency_boost_max' => 0.8,
            'recency_half_life_days' => 180,
            'recency_penalty_after_days' => 900,
            'recency_max_penalty' => 0.5,
            'expand_primary_weight' => 0.6,
            'language' => 'fr',
            'recency_strategy' => 'linear',
        ])->toJsScoringConfig();

        $this->assertEquals(5.0, $js['TITLE_MATCH_BOOST']);
        $this->assertEquals(3.0, $js['TITLE_ALL_TERMS_MULTIPLIER']);
        $this->assertEquals(0.9, $js['CONTENT_MATCH_BOOST']);
        $this->assertEquals(0.8, $js['RECENCY_BOOST_MAX']);
        $this->assertEquals(180, $js['RECENCY_HALF_LIFE_DAYS']);
        $this->assertEquals(900, $js['RECENCY_PENALTY_AFTER_DAYS']);
        $this->assertEquals(0.5, $js['RECENCY_MAX_PENALTY']);
        $this->assertEquals(0.6, $js['EXPAND_PRIMARY_WEIGHT']);
        $this->assertEquals('fr', $js['LANGUAGE']);
        $this->assertEquals('linear', $js['RECENCY_STRATEGY']);
    }

    // -------------------------------------------------------------------
    // Display — 5 fields
    // -------------------------------------------------------------------

    public function test_display_defaults_reach_js_output(): void
    {
        $js = $this->makeConfig()->toJsScoringConfig();

        $this->assertEquals(300, $js['EXCERPT_LENGTH']);
        $this->assertEquals(10, $js['RESULTS_PER_PAGE']);
        $this->assertEquals(50, $js['MAX_PAGEFIND_RESULTS']);
        $this->assertEquals(5, $js['AI_SUMMARY_TOP_N']);
        $this->assertEquals(2000, $js['AI_SUMMARY_MAX_CHARS']);
    }

    public function test_display_overrides_propagate_to_js_output(): void
    {
        $js = $this->makeConfig([
            'excerpt_length' => 500,
            'results_per_page' => 25,
            'max_pagefind_results' => 100,
            'ai_summary_top_n' => 10,
            'ai_summary_max_chars' => 5000,
        ])->toJsScoringConfig();

        $this->assertEquals(500, $js['EXCERPT_LENGTH']);
        $this->assertEquals(25, $js['RESULTS_PER_PAGE']);
        $this->assertEquals(100, $js['MAX_PAGEFIND_RESULTS']);
        $this->assertEquals(10, $js['AI_SUMMARY_TOP_N']);
        $this->assertEquals(5000, $js['AI_SUMMARY_MAX_CHARS']);
    }

    // -------------------------------------------------------------------
    // Feature toggles and max follow-ups
    // -------------------------------------------------------------------

    public function test_ai_feature_toggle_defaults_in_js_output(): void
    {
        $js = $this->makeConfig()->toJsScoringConfig();

        $this->assertTrue($js['AI_EXPAND_QUERY']);
        $this->assertTrue($js['AI_SUMMARIZE']);
        $this->assertEquals(3, $js['AI_MAX_FOLLOWUPS']);
    }

    public function test_disabling_ai_features_propagates_to_js(): void
    {
        $js = $this->makeConfig([
            'ai_expand_query' => false,
            'ai_summarize' => false,
            'max_follow_ups' => 0,
        ])->toJsScoringConfig();

        $this->assertFalse($js['AI_EXPAND_QUERY']);
        $this->assertFalse($js['AI_SUMMARIZE']);
        $this->assertEquals(0, $js['AI_MAX_FOLLOWUPS']);
    }

    // -------------------------------------------------------------------
    // AI client config — provider, model, base_url
    // -------------------------------------------------------------------

    public function test_ai_client_config_defaults(): void
    {
        $client = $this->makeConfig()->toAiClientConfig();

        $this->assertEquals('anthropic', $client['provider']);
        $this->assertArrayHasKey('model', $client);
        $this->assertArrayHasKey('api_key', $client);
    }

    public function test_ai_client_config_overrides(): void
    {
        $client = $this->makeConfig([
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o',
            'ai_base_url' => 'https://proxy.example.com/v1',
        ])->toAiClientConfig();

        $this->assertEquals('openai', $client['provider']);
        $this->assertEquals('gpt-4o', $client['model']);
        $this->assertEquals('https://proxy.example.com/v1', $client['base_url']);
    }

    public function test_empty_ai_base_url_omitted_from_client_config(): void
    {
        $client = $this->makeConfig(['ai_base_url' => ''])->toAiClientConfig();

        $this->assertArrayNotHasKey('base_url', $client);
    }

    // -------------------------------------------------------------------
    // AI languages
    // -------------------------------------------------------------------

    public function test_ai_languages_default_in_js_output(): void
    {
        $js = $this->makeConfig()->toJsScoringConfig();

        $this->assertIsArray($js['AI_LANGUAGES']);
        $this->assertContains('en', $js['AI_LANGUAGES']);
    }

    public function test_ai_languages_override_propagates(): void
    {
        $js = $this->makeConfig([
            'ai_languages' => ['en', 'fr', 'de'],
        ])->toJsScoringConfig();

        $this->assertEquals(['en', 'fr', 'de'], $js['AI_LANGUAGES']);
    }

    // -------------------------------------------------------------------
    // custom_stop_words (in config/scolta.php scoring section)
    // -------------------------------------------------------------------

    public function test_custom_stop_words_default_is_empty(): void
    {
        $js = $this->makeConfig()->toJsScoringConfig();

        $this->assertIsArray($js['CUSTOM_STOP_WORDS']);
        $this->assertEmpty($js['CUSTOM_STOP_WORDS']);
    }

    public function test_custom_stop_words_override_propagates(): void
    {
        $js = $this->makeConfig([
            'custom_stop_words' => ['the', 'a', 'an'],
        ])->toJsScoringConfig();

        $this->assertEquals(['the', 'a', 'an'], $js['CUSTOM_STOP_WORDS']);
    }

    // -------------------------------------------------------------------
    // Phrase proximity (ScoltaConfig-level — not yet in config/scolta.php)
    // -------------------------------------------------------------------

    public function test_phrase_proximity_defaults_in_js_output(): void
    {
        $js = $this->makeConfig()->toJsScoringConfig();

        $this->assertEquals(2.5, $js['PHRASE_ADJACENT_MULTIPLIER']);
        $this->assertEquals(1.5, $js['PHRASE_NEAR_MULTIPLIER']);
        $this->assertEquals(5, $js['PHRASE_NEAR_WINDOW']);
        $this->assertEquals(15, $js['PHRASE_WINDOW']);
    }

    public function test_phrase_proximity_overrides_propagate(): void
    {
        $js = $this->makeConfig([
            'phrase_adjacent_multiplier' => 4.0,
            'phrase_near_multiplier' => 2.5,
            'phrase_near_window' => 10,
            'phrase_window' => 25,
        ])->toJsScoringConfig();

        $this->assertEquals(4.0, $js['PHRASE_ADJACENT_MULTIPLIER']);
        $this->assertEquals(2.5, $js['PHRASE_NEAR_MULTIPLIER']);
        $this->assertEquals(10, $js['PHRASE_NEAR_WINDOW']);
        $this->assertEquals(25, $js['PHRASE_WINDOW']);
    }

    // -------------------------------------------------------------------
    // Cache TTL
    // -------------------------------------------------------------------

    public function test_cache_ttl_default(): void
    {
        $this->assertEquals(2592000, $this->makeConfig()->cacheTtl);
    }

    public function test_cache_ttl_zero_disables_caching(): void
    {
        $this->assertEquals(0, $this->makeConfig(['cache_ttl' => 0])->cacheTtl);
    }

    // -------------------------------------------------------------------
    // Prompt overrides — stored raw (placeholders not substituted)
    // -------------------------------------------------------------------

    public function test_prompt_overrides_default_to_empty(): void
    {
        $config = $this->makeConfig();

        $this->assertEquals('', $config->promptExpandQuery);
        $this->assertEquals('', $config->promptSummarize);
        $this->assertEquals('', $config->promptFollowUp);
    }

    public function test_prompt_overrides_stored_raw(): void
    {
        $config = $this->makeConfig([
            'prompt_expand_query' => 'Custom expand for {SITE_NAME}',
            'prompt_summarize' => 'Custom summarize for {SITE_NAME}',
            'prompt_follow_up' => 'Custom follow-up for {SITE_NAME}',
        ]);

        $this->assertEquals('Custom expand for {SITE_NAME}', $config->promptExpandQuery);
        $this->assertEquals('Custom summarize for {SITE_NAME}', $config->promptSummarize);
        $this->assertEquals('Custom follow-up for {SITE_NAME}', $config->promptFollowUp);
    }
}
