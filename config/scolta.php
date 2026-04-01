<?php

/**
 * Scolta AI Search configuration.
 *
 * Laravel's config system is one of its superpowers: typed, cached,
 * environment-aware, and publishable. Users run `artisan vendor:publish`
 * to get this file in their app's config/ directory, then customize.
 *
 * Every value reads from .env first, with sensible defaults. This is
 * the Laravel way — twelve-factor app, env vars for deployment-specific
 * values, config files for structure and defaults.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | AI Provider
    |--------------------------------------------------------------------------
    |
    | Laravel 12+ users: set to 'laravel' to use the Laravel AI SDK
    | (laravel/ai). The SDK handles provider selection and API keys
    | through its own config/ai.php.
    |
    | Laravel 11 users: set to 'anthropic' or 'openai' and provide
    | the API key below. Scolta's built-in AiClient handles the rest.
    |
    */

    'ai_provider' => env('SCOLTA_AI_PROVIDER', 'anthropic'),
    'ai_api_key' => env('SCOLTA_AI_API_KEY', ''),
    'ai_model' => env('SCOLTA_AI_MODEL', 'claude-sonnet-4-5-20250929'),
    'ai_base_url' => env('SCOLTA_AI_BASE_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | AI Feature Toggles
    |--------------------------------------------------------------------------
    */

    'ai_expand_query' => env('SCOLTA_AI_EXPAND', true),
    'ai_summarize' => env('SCOLTA_AI_SUMMARIZE', true),
    'max_follow_ups' => env('SCOLTA_MAX_FOLLOWUPS', 3),

    /*
    |--------------------------------------------------------------------------
    | Site Identity
    |--------------------------------------------------------------------------
    |
    | Used in AI prompts to give the LLM context about your site.
    |
    */

    'site_name' => env('SCOLTA_SITE_NAME', env('APP_NAME', 'Laravel')),
    'site_description' => env('SCOLTA_SITE_DESCRIPTION', 'website'),

    /*
    |--------------------------------------------------------------------------
    | Searchable Models
    |--------------------------------------------------------------------------
    |
    | List the Eloquent model classes whose content should be indexed.
    | Each model must use the Scolta\Searchable trait, which provides
    | the toSearchableContent() method and registers the observer.
    |
    | This is the Laravel equivalent of WordPress's post_types or
    | Drupal's Search API datasource — but using Eloquent models,
    | because that's how Laravel developers think about content.
    |
    */

    'models' => [
        // App\Models\Post::class,
        // App\Models\Page::class,
        // App\Models\Article::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagefind
    |--------------------------------------------------------------------------
    |
    | Pagefind is the client-side search engine that powers the actual
    | search. Content is exported as HTML, Pagefind builds a WASM-powered
    | index, and the browser does the searching. No server involved.
    |
    */

    'pagefind' => [
        'binary' => env('SCOLTA_PAGEFIND_BINARY', 'pagefind'),
        'build_dir' => env('SCOLTA_BUILD_DIR', storage_path('scolta/build')),
        'output_dir' => env('SCOLTA_OUTPUT_DIR', public_path('scolta-pagefind')),
        'auto_rebuild' => env('SCOLTA_AUTO_REBUILD', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring
    |--------------------------------------------------------------------------
    |
    | Fine-tune how search results are ranked. These values are passed
    | to the JavaScript scoring algorithm via the Blade component.
    |
    */

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

    /*
    |--------------------------------------------------------------------------
    | Display
    |--------------------------------------------------------------------------
    */

    'excerpt_length' => 300,
    'results_per_page' => 10,
    'max_pagefind_results' => 50,
    'ai_summary_top_n' => 5,
    'ai_summary_max_chars' => 2000,

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Query expansion results are cached to reduce API calls. Uses
    | Laravel's cache system — whatever driver you've configured
    | (Redis, Memcached, file, database) works automatically.
    |
    */

    'cache_ttl' => env('SCOLTA_CACHE_TTL', 2592000), // 30 days

    /*
    |--------------------------------------------------------------------------
    | Route Prefix
    |--------------------------------------------------------------------------
    |
    | API routes are registered under this prefix. The default gives you
    | endpoints like /api/scolta/v1/expand-query — matching the same
    | paths that Drupal and WordPress use, so scolta.js works identically.
    |
    */

    'route_prefix' => 'api/scolta/v1',

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied to the Scolta API routes. By default, AI endpoints
    | are public (matching Drupal and WordPress behavior). Add 'auth:sanctum'
    | or your own middleware to restrict access.
    |
    */

    'middleware' => ['api'],

    /*
    |--------------------------------------------------------------------------
    | Prompt Overrides
    |--------------------------------------------------------------------------
    |
    | Leave empty to use Scolta's built-in prompts. Set a string to
    | override with your own system prompt for that feature.
    |
    */

    'prompt_expand_query' => '',
    'prompt_summarize' => '',
    'prompt_follow_up' => '',
];
