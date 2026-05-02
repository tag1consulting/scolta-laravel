<?php

declare(strict_types=1);

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

    /*
    |--------------------------------------------------------------------------
    | Site Type Preset — Start Here
    |--------------------------------------------------------------------------
    |
    | Pick the closest match for your site. This gives you a good set of
    | scoring defaults. Presets adjust how Scolta ranks results — how much
    | weight goes to titles vs. page content, whether newer content ranks
    | higher, and how broadly Scolta interprets what you searched for.
    |
    | The preset is a starting point, not a constraint. You can optionally
    | override any individual value in the 'scoring' section below.
    |
    | Available presets:
    |   'content_catalog'  - Recipe sites, wikis, content collections
    |   'reference'        - Documentation, knowledge bases, encyclopedias
    |   'ecommerce'        - Online stores, product catalogs
    |   'blog'             - Blogs, news, editorial content
    |   'none'             - No preset (default). All values from 'scoring' below.
    |
    */
    'preset' => env('SCOLTA_PRESET', 'none'),

    'ai_provider' => env('SCOLTA_AI_PROVIDER', 'anthropic'),
    'ai_api_key' => env('SCOLTA_API_KEY', env('SCOLTA_AI_API_KEY', '')),
    'ai_model' => env('SCOLTA_AI_MODEL', 'claude-sonnet-4-5-20250929'),
    'ai_expansion_model' => env('SCOLTA_EXPANSION_MODEL', ''),
    'ai_base_url' => env('SCOLTA_AI_BASE_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | AI Feature Toggles
    |--------------------------------------------------------------------------
    */

    'ai_expand_query' => env('SCOLTA_AI_EXPAND', true),
    'ai_summarize' => env('SCOLTA_AI_SUMMARIZE', true),
    'ai_languages' => array_filter(array_map('trim', explode(',', env('SCOLTA_AI_LANGUAGES', 'en')))),
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

    /*
    |--------------------------------------------------------------------------
    | Indexer
    |--------------------------------------------------------------------------
    |
    | Controls which indexing backend is used when running `scolta:build`.
    |
    | - 'auto'   (default) Use the Pagefind binary if available, otherwise
    |             fall back to the pure-PHP indexer.
    | - 'php'    Always use the pure-PHP indexer (no external binary needed).
    | - 'binary' Always use the Pagefind CLI binary (fails if not found).
    |
    | Can be overridden per-invocation with `--indexer=php|binary|auto`.
    |
    */

    'indexer' => env('SCOLTA_INDEXER', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Memory Budget
    |--------------------------------------------------------------------------
    |
    | Controls peak RAM used by the PHP indexer pipeline.
    |
    | - 'conservative' (default): peak ≤ 96 MB — safe for shared hosting.
    | - 'balanced':  ~384 MB — recommended for dedicated VMs.
    | - 'aggressive': ~1 GB — maximises throughput on high-memory servers.
    |
    | Can be overridden per-run with --memory-budget on `artisan scolta:build`.
    | Set SCOLTA_MEMORY_BUDGET in .env to change the persistent default.
    |
    */

    'memory_budget' => [
        // Named profile (conservative, balanced, aggressive) or a raw byte value
        // such as "256M" or "1G". Can be overridden per-run with --memory-budget.
        'profile' => env('SCOLTA_MEMORY_BUDGET', 'conservative'),

        // Pages per chunk during a PHP build. null = use the profile default
        // (50 / 200 / 500 for conservative / balanced / aggressive).
        // Lower values reduce peak RSS; higher values reduce merge overhead.
        // Can be overridden per-run with --chunk-size.
        'chunk_size' => env('SCOLTA_CHUNK_SIZE') ? (int) env('SCOLTA_CHUNK_SIZE') : null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto Rebuild
    |--------------------------------------------------------------------------
    |
    | When enabled, content changes detected by the model observer will
    | automatically dispatch a debounced rebuild to the queue. The delay
    | (in seconds) prevents excessive rebuilds when multiple items are
    | edited in quick succession.
    |
    | Requires a queue worker to be running. Set to false to only rebuild
    | manually via `artisan scolta:build`.
    |
    */

    'auto_rebuild' => env('SCOLTA_AUTO_REBUILD', false),
    'auto_rebuild_delay' => env('SCOLTA_AUTO_REBUILD_DELAY', 300),

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
    | Any value you set here overrides the preset above. Leave a value at
    | its default to use the preset's recommendation.
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
        'expand_primary_weight' => 0.5,

        // Language-aware stop words (0.2.2+)
        // ISO 639-1 code for stop word filtering. Supported: ar, ca, da, de, el,
        // en, es, et, eu, fi, fr, ga, hi, hu, hy, id, it, lt, ne, nl, no, pl,
        // pt, ro, ru, sr, sv, ta, tr, yi. CJK/unknown → no filtering.
        'language' => env('SCOLTA_LANGUAGE', 'en'),
        'custom_stop_words' => array_filter(array_map(
            'trim',
            explode(',', env('SCOLTA_CUSTOM_STOP_WORDS', ''))
        )),

        // Pluggable recency functions (0.2.2+)
        // Strategies: 'exponential' (default), 'linear', 'step', 'none', 'custom'.
        'recency_strategy' => env('SCOLTA_RECENCY_STRATEGY', 'exponential'),
        // For 'custom': JSON array of [[days, boost], …] control points.
        // e.g. SCOLTA_RECENCY_CURVE='[[0,1.0],[180,0.5],[365,0.0]]'
        'recency_curve' => json_decode(env('SCOLTA_RECENCY_CURVE', '[]'), true) ?: [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Display
    |--------------------------------------------------------------------------
    */

    'excerpt_length' => 300,
    'results_per_page' => 10,
    'max_pagefind_results' => 50,
    'ai_summary_top_n' => 10,
    'ai_summary_max_chars' => 4000,

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
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Maximum requests per minute for the Scolta API endpoints.
    | Set to 0 to disable rate limiting. Uses Laravel's built-in
    | rate limiter with per-IP tracking.
    |
    */

    'rate_limit' => env('SCOLTA_RATE_LIMIT', 30),

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
    | Health Check Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied to the health check endpoint. Separated from the
    | main middleware to allow monitoring tools unrestricted access.
    |
    */

    'health_middleware' => ['api'],

    /*
    |--------------------------------------------------------------------------
    | Prompt Overrides
    |--------------------------------------------------------------------------
    |
    | Leave empty to use Scolta's built-in prompts.
    | Set a string to override with your own system prompt for that feature.
    | Supports {SITE_NAME} and {SITE_DESCRIPTION} placeholders.
    |
    | To see the full default prompts, run:
    |   php artisan tinker
    |   \Tag1\Scolta\Prompt\DefaultPrompts::getTemplate('expand_query')
    |   \Tag1\Scolta\Prompt\DefaultPrompts::getTemplate('summarize')
    |   \Tag1\Scolta\Prompt\DefaultPrompts::getTemplate('follow_up')
    |
    | Default expand_query: "You expand search queries for {SITE_NAME}...
    |   Return a JSON array of 2-4 alternative search terms..."
    | Default summarize: "You are a search assistant for the {SITE_NAME}
    |   website... provide a brief, scannable summary..."
    | Default follow_up: "You are a search assistant for the {SITE_NAME}
    |   website. You are continuing a conversation..."
    |
    */

    'prompt_expand_query' => '',
    'prompt_summarize' => '',
    'prompt_follow_up' => '',
];
