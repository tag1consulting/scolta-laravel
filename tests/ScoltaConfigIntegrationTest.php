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
    /** @var array<string, mixed> */
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
     *
     * @param  array<string, mixed>  $overrides
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

        $this->assertEquals(2.0, $js['TITLE_MATCH_BOOST']);
        $this->assertEquals(1.5, $js['TITLE_ALL_TERMS_MULTIPLIER']);
        $this->assertEquals(0.4, $js['CONTENT_MATCH_BOOST']);
        $this->assertEquals(0.25, $js['RECENCY_BOOST_MAX']);
        $this->assertEquals(365, $js['RECENCY_HALF_LIFE_DAYS']);
        $this->assertEquals(1825, $js['RECENCY_PENALTY_AFTER_DAYS']);
        $this->assertEquals(0.3, $js['RECENCY_MAX_PENALTY']);
        $this->assertEquals(0.5, $js['EXPAND_PRIMARY_WEIGHT']);
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
        $this->assertEquals(10, $js['AI_SUMMARY_TOP_N']);
        $this->assertEquals(4000, $js['AI_SUMMARY_MAX_CHARS']);
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

    // -------------------------------------------------------------------
    // flattenConfig — grouping containers vs map-valued settings
    // -------------------------------------------------------------------

    /**
     * A populated description map must survive flattenConfig() intact.
     *
     * Regression: the flattener hoisted the sub-keys of every associative array,
     * which is right for a grouping container like `scoring` but destroys a
     * map-valued setting. A configured `filter_field_descriptions` had its own
     * field names ('topic', 'era') scattered across the top level as bogus
     * settings, which ScoltaConfig then ignored, and the map itself vanished. So
     * the setting could never take effect: not for the browser's filter labels
     * and not for the AI expansion prompt either.
     *
     * It went unnoticed because both maps default to `[]`, and an empty array is
     * a list rather than an associative array, so it passed through untouched.
     * Only a site that actually configured one was affected.
     */
    public function test_flatten_config_preserves_populated_description_maps(): void
    {
        $flat = ScoltaAiService::flattenConfig([
            'filter_field_descriptions' => ['topic' => 'Subject area', 'era' => 'Period'],
            'sortable_field_descriptions' => ['date' => 'Publish date'],
        ]);

        $this->assertSame(
            ['topic' => 'Subject area', 'era' => 'Period'],
            $flat['filter_field_descriptions'],
            'filter_field_descriptions must survive flattening as a whole map.'
        );
        $this->assertSame(
            ['date' => 'Publish date'],
            $flat['sortable_field_descriptions']
        );
        // And the map's own field names must NOT leak to the top level.
        $this->assertArrayNotHasKey('topic', $flat);
        $this->assertArrayNotHasKey('era', $flat);
        $this->assertArrayNotHasKey('date', $flat);
    }

    /**
     * Grouping containers must still be hoisted, which is what the flattener is
     * for. Pins that the fix above did not over-reach.
     */
    public function test_flatten_config_still_hoists_grouping_containers(): void
    {
        $flat = ScoltaAiService::flattenConfig([
            'scoring' => ['title_match_boost' => 2.0, 'specificity_floor' => 0.15],
            'pagefind' => ['output_dir' => '/tmp/x'],
            'cache_ttl' => 60,
        ]);

        $this->assertSame(2.0, $flat['title_match_boost']);
        $this->assertSame(0.15, $flat['specificity_floor']);
        $this->assertSame('/tmp/x', $flat['output_dir']);
        $this->assertSame(60, $flat['cache_ttl']);
        $this->assertArrayNotHasKey('scoring', $flat);
        $this->assertArrayNotHasKey('pagefind', $flat);
    }

    /**
     * A configured filter-description map must reach the typed property, which
     * is what both the Blade component and the AI expansion prompt read.
     */
    public function test_configured_filter_field_descriptions_reach_the_typed_property(): void
    {
        $raw = $this->rawConfig;
        $raw['filter_field_descriptions'] = ['topic' => 'Subject area'];

        $flat = ScoltaAiService::flattenConfig($raw);
        $flat['ai_api_key'] = 'test-key';
        $config = ScoltaConfig::fromArray($flat);

        $this->assertSame(['topic' => 'Subject area'], $config->filterFieldDescriptions);
    }

    // -------------------------------------------------------------------
    // Facet visibility and specificity ranking
    // -------------------------------------------------------------------

    public function test_hide_empty_facets_defaults_to_true(): void
    {
        $this->assertTrue($this->makeConfig()->hideEmptyFacets);
    }

    /**
     * The opt-out must survive flattenConfig() and reach the typed property,
     * since the Blade component reads it from there to build window.scolta.
     */
    public function test_hide_empty_facets_opt_out_reaches_the_typed_property(): void
    {
        $this->assertFalse($this->makeConfig(['hide_empty_facets' => false])->hideEmptyFacets);
    }

    public function test_specificity_defaults_reach_the_typed_properties(): void
    {
        $config = $this->makeConfig();

        $this->assertTrue($config->specificityWeighting);
        $this->assertEquals(0.15, $config->specificityFloor);
        $this->assertEquals(0.55, $config->specificityStrongMatch);
        $this->assertEquals(0.9, $config->specificityCooccurrence);
        $this->assertEquals(0.45, $config->specificityAgreementGate);
        $this->assertEquals(1.0, $config->specificityAgreementDecay);
    }

    public function test_specificity_overrides_reach_the_typed_properties(): void
    {
        $config = $this->makeConfig([
            'specificity_weighting' => false,
            'specificity_floor' => 0.05,
            'specificity_strong_match' => 0.7,
            'specificity_cooccurrence' => 1.4,
            'specificity_agreement_gate' => 0.3,
            'specificity_agreement_decay' => 0.65,
        ]);

        $this->assertFalse($config->specificityWeighting);
        $this->assertEquals(0.05, $config->specificityFloor);
        $this->assertEquals(0.7, $config->specificityStrongMatch);
        $this->assertEquals(1.4, $config->specificityCooccurrence);
        $this->assertEquals(0.3, $config->specificityAgreementGate);
        $this->assertEquals(0.65, $config->specificityAgreementDecay);
    }

    // -------------------------------------------------------------------
    // Search as you type
    // -------------------------------------------------------------------

    /**
     * The ten keys and their documented defaults.
     *
     * @return array<string, bool|int|string>
     */
    private function saytDefaults(): array
    {
        return [
            'sayt_enabled' => true,
            'sayt_min_chars' => 2,
            'sayt_debounce_ms' => 150,
            'sayt_max_suggestions' => 6,
            'sayt_recent_searches' => true,
            'sayt_max_recent' => 3,
            'sayt_expand' => true,
            'sayt_expand_per_minute' => 6,
            'sayt_expansion_delay_ms' => 500,
            'sayt_suggestion_action' => 'navigate',
        ];
    }

    /**
     * All ten SAYT keys must survive flattenConfig() untouched.
     *
     * They are scalars at the top level, so the flattener's associative-array
     * branch never sees them and MAP_VALUED_KEYS must NOT gain entries for them.
     * That is the whole point of asserting it here rather than only downstream:
     * adding them to MAP_VALUED_KEYS would look harmless and pass every
     * emission test, because passing a scalar through whole and hoisting
     * nothing are the same operation for a scalar — right up until someone
     * groups them under a 'sayt' key and the list silently stops the hoist.
     */
    public function test_flatten_config_passes_every_sayt_key_through_untouched(): void
    {
        $flat = ScoltaAiService::flattenConfig($this->rawConfig);

        foreach ($this->saytDefaults() as $key => $expected) {
            $this->assertArrayHasKey(
                $key,
                $flat,
                "$key must survive flattenConfig() as a top-level key."
            );
            $this->assertSame(
                $expected,
                $flat[$key],
                "$key must reach ScoltaConfig::fromArray() as its documented default."
            );
        }
    }

    /**
     * The map-key passthrough list must stay exactly the two description maps.
     *
     * Every SAYT setting is a scalar, so none of them belongs on it.
     */
    public function test_map_valued_keys_gained_no_sayt_entries(): void
    {
        $reflected = new \ReflectionClassConstant(ScoltaAiService::class, 'MAP_VALUED_KEYS');

        $this->assertSame(
            ['filter_field_descriptions', 'sortable_field_descriptions'],
            $reflected->getValue(),
            'MAP_VALUED_KEYS must stay the two description maps. Every SAYT setting is a '
            .'scalar and the flattener already passes scalars through.'
        );
    }

    public function test_sayt_defaults_reach_the_typed_properties(): void
    {
        $config = $this->makeConfig();

        $this->assertTrue($config->saytEnabled);
        $this->assertSame(2, $config->saytMinChars);
        $this->assertSame(150, $config->saytDebounceMs);
        $this->assertSame(6, $config->saytMaxSuggestions);
        $this->assertTrue($config->saytRecentSearches);
        $this->assertSame(3, $config->saytMaxRecent);
        $this->assertTrue($config->saytExpand);
        $this->assertSame(6, $config->saytExpandPerMinute);
        $this->assertSame(500, $config->saytExpansionDelayMs);
        $this->assertSame('navigate', $config->saytSuggestionAction);
    }

    public function test_sayt_overrides_reach_the_typed_properties(): void
    {
        $config = $this->makeConfig([
            'sayt_enabled' => false,
            'sayt_min_chars' => 1,
            'sayt_debounce_ms' => 400,
            'sayt_max_suggestions' => 10,
            'sayt_recent_searches' => false,
            'sayt_max_recent' => 5,
            'sayt_expand' => false,
            'sayt_expand_per_minute' => 2,
            'sayt_expansion_delay_ms' => 800,
            'sayt_suggestion_action' => 'search',
        ]);

        $this->assertFalse($config->saytEnabled);
        $this->assertSame(1, $config->saytMinChars);
        $this->assertSame(400, $config->saytDebounceMs);
        $this->assertSame(10, $config->saytMaxSuggestions);
        $this->assertFalse($config->saytRecentSearches);
        $this->assertSame(5, $config->saytMaxRecent);
        $this->assertFalse($config->saytExpand);
        $this->assertSame(2, $config->saytExpandPerMinute);
        $this->assertSame(800, $config->saytExpansionDelayMs);
        $this->assertSame('search', $config->saytSuggestionAction);
    }

    /**
     * An env-supplied value arrives as a string, and every SAYT key is typed.
     * fromArray() casts to the declared property type, so the flattened config
     * has to survive that path too.
     */
    public function test_string_valued_sayt_settings_are_cast_to_their_property_types(): void
    {
        $config = $this->makeConfig([
            'sayt_enabled' => '0',
            'sayt_min_chars' => '3',
            'sayt_debounce_ms' => '250',
        ]);

        $this->assertFalse($config->saytEnabled);
        $this->assertSame(3, $config->saytMinChars);
        $this->assertSame(250, $config->saytDebounceMs);
    }

    /**
     * An unrecognized suggestion action must not reach the browser as itself.
     */
    public function test_an_unknown_suggestion_action_normalizes_to_navigate(): void
    {
        $config = $this->makeConfig(['sayt_suggestion_action' => 'teleport']);

        $this->assertSame('navigate', $config->normalizedSaytSuggestionAction());
        $this->assertSame('navigate', $config->toBrowserConfig()['saytSuggestionAction']);
    }
}
