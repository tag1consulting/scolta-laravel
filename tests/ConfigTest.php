<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * Tests for the Laravel config file structure.
 */
class ConfigTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        // Set up a minimal Laravel app container so storage_path() and
        // public_path() work when the config file is loaded.
        $app = new Application(dirname(__DIR__));
        Container::setInstance($app);

        $this->config = require dirname(__DIR__).'/config/scolta.php';
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    /**
     * Resolve the published config into a ScoltaConfig the same way the runtime
     * does (flatten + fromArray), optionally with a different preset and
     * explicit scoring overrides — so tests can assert the *resolved* defaults
     * now that the config literals are null and fall through to the preset.
     *
     * @param  array<string, mixed>  $scoringOverrides
     */
    private function resolve(?string $preset = null, array $scoringOverrides = []): ScoltaConfig
    {
        $config = $this->config;
        if ($preset !== null) {
            $config['preset'] = $preset;
        }
        $config['scoring'] = array_replace($config['scoring'], $scoringOverrides);

        return ScoltaConfig::fromArray(ScoltaAiService::flattenConfig($config));
    }

    public function test_config_is_not_empty(): void
    {
        $this->assertNotEmpty($this->config);
    }

    // -------------------------------------------------------------------
    // AI provider section
    // -------------------------------------------------------------------

    public function test_ai_provider_defaults(): void
    {
        $this->assertArrayHasKey('ai_provider', $this->config);
        $this->assertArrayHasKey('ai_api_key', $this->config);
        $this->assertArrayHasKey('ai_model', $this->config);
        $this->assertArrayHasKey('ai_base_url', $this->config);
    }

    public function test_ai_feature_toggles(): void
    {
        $this->assertArrayHasKey('ai_expand_query', $this->config);
        $this->assertArrayHasKey('ai_summarize', $this->config);
        $this->assertArrayHasKey('max_follow_ups', $this->config);
    }

    public function test_ai_feature_toggle_defaults(): void
    {
        $this->assertTrue($this->config['ai_expand_query']);
        $this->assertTrue($this->config['ai_summarize']);
        $this->assertEquals(3, $this->config['max_follow_ups']);
    }

    // -------------------------------------------------------------------
    // Site identity
    // -------------------------------------------------------------------

    public function test_site_identity(): void
    {
        $this->assertArrayHasKey('site_name', $this->config);
        $this->assertArrayHasKey('site_description', $this->config);
        $this->assertEquals('website', $this->config['site_description']);
    }

    // -------------------------------------------------------------------
    // Searchable models
    // -------------------------------------------------------------------

    public function test_models_is_array(): void
    {
        $this->assertArrayHasKey('models', $this->config);
        $this->assertIsArray($this->config['models']);
    }

    // -------------------------------------------------------------------
    // Pagefind nested config
    // -------------------------------------------------------------------

    public function test_pagefind_section(): void
    {
        $this->assertArrayHasKey('pagefind', $this->config);
        $pf = $this->config['pagefind'];
        $this->assertArrayHasKey('binary', $pf);
        $this->assertArrayHasKey('build_dir', $pf);
        $this->assertArrayHasKey('output_dir', $pf);
        // auto_rebuild is a top-level key, not nested under pagefind
        $this->assertArrayNotHasKey('auto_rebuild', $pf);
    }

    // -------------------------------------------------------------------
    // Scoring nested config
    // -------------------------------------------------------------------

    public function test_scoring_section(): void
    {
        $this->assertArrayHasKey('scoring', $this->config);
        $scoring = $this->config['scoring'];

        $expectedKeys = [
            'title_match_boost', 'title_all_terms_multiplier',
            'content_match_boost', 'recency_boost_max',
            'recency_half_life_days', 'recency_penalty_after_days',
            'recency_max_penalty', 'expand_primary_weight',
            'language', 'recency_strategy', 'recency_curve',
            'expand_subword_deny_list',
            'expansion_combine_mode',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $scoring, "Missing scoring key: {$key}");
        }
    }

    public function test_expand_subword_deny_list_defaults_empty(): void
    {
        $this->assertIsArray($this->config['scoring']['expand_subword_deny_list']);
        $this->assertSame([], $this->config['scoring']['expand_subword_deny_list']);
    }

    public function test_expansion_combine_mode_defaults_to_relevance_union(): void
    {
        // The config literal is now null (env-driven, no fallback) so it falls
        // through to the preset. With no preset selected (preset='none'), the
        // resolved default is the scolta-php base default.
        $this->assertNull($this->config['scoring']['expansion_combine_mode']);
        $this->assertSame('relevance_union', $this->resolve()->expansionCombineMode);
    }

    /**
     * The Site Type preset now actually drives the resolved config — previously
     * the concrete config defaults always overrode it, leaving the whole preset
     * inert (scolta-laravel#82). This is the bug-fix assertion.
     */
    public function test_preset_drives_resolved_config(): void
    {
        // content_catalog's tuning reaches the resolved config.
        $cc = $this->resolve('content_catalog');
        $this->assertSame('round_robin', $cc->expansionCombineMode);
        $this->assertSame(0.0, $cc->recencyBoostMax);
        $this->assertSame(0.10, $cc->expandSubwordMaxFrequency);
        $this->assertSame(15, $cc->aiSummaryTopN);

        // reference's tuning reaches the resolved config.
        $ref = $this->resolve('reference');
        $this->assertSame('relevance_union', $ref->expansionCombineMode);
        $this->assertSame(0.0, $ref->recencyBoostMax);

        // An explicit value (e.g. SCOLTA_EXPANSION_COMBINE_MODE) still wins over
        // the preset: explicit > preset > base.
        $explicit = $this->resolve('reference', ['expansion_combine_mode' => 'round_robin']);
        $this->assertSame('round_robin', $explicit->expansionCombineMode);
    }

    public function test_expansion_per_term_top_k_is_not_a_config_key(): void
    {
        // K is locked at 3 inside scolta-php and is no longer a configurable
        // setting, so it must not appear in the published Laravel config.
        $this->assertArrayNotHasKey('expansion_per_term_top_k', $this->config['scoring']);
    }

    public function test_scoring_defaults(): void
    {
        $scoring = $this->config['scoring'];

        // Preset-overridable fields default to null in the config literal so
        // they fall through to the selected Site Type preset.
        $this->assertNull($scoring['title_match_boost']);
        $this->assertNull($scoring['title_all_terms_multiplier']);
        $this->assertNull($scoring['content_match_boost']);
        $this->assertNull($scoring['recency_boost_max']);
        $this->assertNull($scoring['recency_half_life_days']);
        $this->assertNull($scoring['expand_primary_weight']);
        $this->assertNull($scoring['expand_subword_max_frequency']);
        $this->assertNull($scoring['recency_strategy']);

        // Non-preset-overridable fields keep their concrete defaults.
        $this->assertEquals(1825, $scoring['recency_penalty_after_days']);
        $this->assertEquals(0.3, $scoring['recency_max_penalty']);
        $this->assertEquals(0.05, $scoring['cross_list_bonus']);
        $this->assertEquals('en', $scoring['language']);
        $this->assertIsArray($scoring['recency_curve']);

        // With no preset (preset='none'), the nulled fields resolve to the
        // scolta-php base defaults.
        $resolved = $this->resolve();
        $this->assertEquals(2.0, $resolved->titleMatchBoost);
        $this->assertEquals(1.5, $resolved->titleAllTermsMultiplier);
        $this->assertEquals(0.4, $resolved->contentMatchBoost);
        $this->assertEquals(0.25, $resolved->recencyBoostMax);
        $this->assertEquals(365, $resolved->recencyHalfLifeDays);
        $this->assertEquals(0.5, $resolved->expandPrimaryWeight);
        $this->assertEquals('exponential', $resolved->recencyStrategy);
        // 'none' broadens sub-word recall to 0.10 (was hard-coded 0.05).
        $this->assertEquals(0.10, $resolved->expandSubwordMaxFrequency);
    }

    public function test_ai_languages_default(): void
    {
        $this->assertArrayHasKey('ai_languages', $this->config);
        $this->assertIsArray($this->config['ai_languages']);
        $this->assertContains('en', $this->config['ai_languages']);
    }

    // -------------------------------------------------------------------
    // Auto rebuild
    // -------------------------------------------------------------------

    public function test_auto_rebuild_defaults_to_true(): void
    {
        $this->assertArrayHasKey('auto_rebuild', $this->config);
        $this->assertTrue($this->config['auto_rebuild']);
    }

    // -------------------------------------------------------------------
    // Display
    // -------------------------------------------------------------------

    public function test_display_defaults(): void
    {
        // Preset-overridable display fields default to null (fall through to
        // the preset); ai_summary_max_chars is not preset-overridable.
        $this->assertNull($this->config['excerpt_length']);
        $this->assertNull($this->config['results_per_page']);
        $this->assertNull($this->config['max_pagefind_results']);
        $this->assertNull($this->config['ai_summary_top_n']);
        $this->assertEquals(4000, $this->config['ai_summary_max_chars']);

        // Resolved defaults with no preset come from the scolta-php base.
        $resolved = $this->resolve();
        $this->assertEquals(300, $resolved->excerptLength);
        $this->assertEquals(10, $resolved->resultsPerPage);
        $this->assertEquals(50, $resolved->maxPagefindResults);
        $this->assertEquals(10, $resolved->aiSummaryTopN);
    }

    public function test_show_attribution_key_exists(): void
    {
        $this->assertArrayHasKey('show_attribution', $this->config,
            'show_attribution key must be present in the config array.');
    }

    public function test_show_attribution_defaults_to_false(): void
    {
        $this->assertFalse($this->config['show_attribution'],
            'show_attribution must default to false — attribution is opt-in.');
    }

    public function test_hide_empty_facets_key_exists(): void
    {
        $this->assertArrayHasKey('hide_empty_facets', $this->config,
            'hide_empty_facets key must be present in the config array.');
    }

    public function test_hide_empty_facets_defaults_to_true(): void
    {
        $this->assertTrue($this->config['hide_empty_facets'],
            'hide_empty_facets must default to true — hiding zero-count values is the '
            .'mainstream faceted-search behavior and the browser default.');
    }

    /**
     * hide_empty_facets must stay top-level, not nested under scoring.
     *
     * The service provider merges published config with a shallow array_merge,
     * so a top-level key still picks up the package default on a published
     * config that predates it. Nested inside scoring it would not.
     */
    public function test_hide_empty_facets_is_top_level_not_scoring(): void
    {
        $this->assertArrayNotHasKey('hide_empty_facets', $this->config['scoring'],
            'hide_empty_facets must be a top-level config key, not a scoring one.');
    }

    // -------------------------------------------------------------------
    // Specificity and co-occurrence ranking
    // -------------------------------------------------------------------

    /**
     * All six specificity defaults must be byte-equal to the browser's own
     * fallbacks in scolta.js, or a Laravel site silently ranks differently
     * from an unconfigured one.
     */
    public function test_specificity_defaults_match_the_browser_fallbacks(): void
    {
        $scoring = $this->config['scoring'];

        $this->assertTrue($scoring['specificity_weighting']);
        $this->assertEquals(0.15, $scoring['specificity_floor']);
        $this->assertEquals(0.55, $scoring['specificity_strong_match']);
        $this->assertEquals(0.9, $scoring['specificity_cooccurrence']);
        $this->assertEquals(0.45, $scoring['specificity_agreement_gate']);
        $this->assertEquals(1.0, $scoring['specificity_agreement_decay']);
    }

    /**
     * None of the six appears in any ScoltaConfig preset, so per this config
     * file's own convention they must carry concrete defaults rather than the
     * bare null that means "fall through to the preset". A null here would
     * reach ScoltaConfig as null and lose the documented default.
     */
    public function test_specificity_keys_are_not_preset_deferred_nulls(): void
    {
        $keys = [
            'specificity_weighting',
            'specificity_floor',
            'specificity_strong_match',
            'specificity_cooccurrence',
            'specificity_agreement_gate',
            'specificity_agreement_decay',
        ];

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $this->config['scoring'],
                "scoring.$key must be present in the config array.");
            $this->assertNotNull($this->config['scoring'][$key],
                "scoring.$key must carry a concrete default: it appears in no preset, "
                .'so a null would not fall through to anything.');
        }
    }

    // -------------------------------------------------------------------
    // Caching and rate limiting
    // -------------------------------------------------------------------

    public function test_cache_default(): void
    {
        $this->assertArrayHasKey('cache_ttl', $this->config);
        $this->assertEquals(2592000, $this->config['cache_ttl']);
    }

    public function test_rate_limit_default(): void
    {
        $this->assertArrayHasKey('rate_limit', $this->config);
        $this->assertEquals(30, $this->config['rate_limit']);
    }

    // -------------------------------------------------------------------
    // Routes and middleware
    // -------------------------------------------------------------------

    public function test_route_prefix(): void
    {
        $this->assertEquals('api/scolta/v1', $this->config['route_prefix']);
    }

    public function test_middleware(): void
    {
        $this->assertArrayHasKey('middleware', $this->config);
        $this->assertContains('api', $this->config['middleware']);
    }

    // -------------------------------------------------------------------
    // Prompt overrides
    // -------------------------------------------------------------------

    public function test_prompt_overrides_default_empty(): void
    {
        $this->assertEquals('', $this->config['prompt_expand_query']);
        $this->assertEquals('', $this->config['prompt_summarize']);
        $this->assertEquals('', $this->config['prompt_follow_up']);
    }
}
