<?php

declare(strict_types=1);
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

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

    /*
    |--------------------------------------------------------------------------
    | AI Provider
    |--------------------------------------------------------------------------
    |
    | The AI provider to use for query expansion and summarization.
    | Options: 'anthropic', 'openai', 'amazee', 'laravel'
    |
    | 'laravel'    — Laravel 12+ only. Use the Laravel AI SDK (laravel/ai).
    |               The SDK handles provider selection and API keys via
    |               config/ai.php. No SCOLTA_API_KEY needed.
    |
    | 'anthropic'  — Use Anthropic Claude directly. Set SCOLTA_API_KEY.
    |
    | 'openai'     — Use OpenAI directly. Set SCOLTA_API_KEY.
    |
    | 'amazee'     — Use the Amazee.ai managed gateway. Run
    |               `php artisan scolta:amazee:provision` to set up your
    |               connection, or leave SCOLTA_AI_PROVIDER unset — Scolta
    |               auto-provisions a free trial on the first AI request
    |               when no API key is configured.
    |
    */

    'ai_provider' => env('SCOLTA_AI_PROVIDER', 'anthropic'),
    'ai_api_key' => env('SCOLTA_API_KEY', ''),
    'ai_model' => env('SCOLTA_AI_MODEL', ScoltaAiService::DEFAULT_MODEL),
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

    'site_name' => env('SCOLTA_SITE_NAME') ?? env('APP_NAME', 'Laravel'),
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
    | - 'auto'   (default) Use the pure-PHP indexer. Works on all hosting
    |             environments, no binary or Node.js required.
    | - 'php'    Explicitly select the pure-PHP indexer.
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

    'auto_rebuild' => env('SCOLTA_AUTO_REBUILD', true),
    'auto_rebuild_delay' => env('SCOLTA_AUTO_REBUILD_DELAY', 300),

    /*
    |--------------------------------------------------------------------------
    | State Directory
    |--------------------------------------------------------------------------
    |
    | Directory where the PHP indexer stores build state (lock files, chunk
    | manifests, partial index data). Must be writable by the web server.
    |
    */

    'state_dir' => storage_path('app/scolta'),

    'pagefind' => [
        'binary' => env('SCOLTA_PAGEFIND_BINARY', 'pagefind'),
        'build_dir' => env('SCOLTA_BUILD_DIR', storage_path('scolta/build')),
        'output_dir' => env('SCOLTA_OUTPUT_DIR', public_path('scolta-pagefind')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring
    |--------------------------------------------------------------------------
    |
    | Fine-tune how search results are ranked. These values are passed
    | to the JavaScript scoring algorithm via the Blade component.
    |
    | Preset-overridable fields default to null, which means "use the value
    | from the Site Type preset selected above" (or the scolta-php base default
    | when the preset is 'none'). Set the matching SCOLTA_* env var — or replace
    | null with a literal value — to override the preset for that field.
    |
    */

    'scoring' => [
        // Preset-overridable fields default to null = "use the selected Site
        // Type preset's value" (scolta-php's fromArray() treats null as unset
        // and falls through to the preset, or to the base default when no
        // preset is selected). Set the matching SCOLTA_* env var to override
        // the preset explicitly. Fields NOT in any preset keep concrete
        // defaults (recency_penalty_after_days, recency_max_penalty,
        // cross_list_bonus).
        'title_match_boost' => env('SCOLTA_TITLE_MATCH_BOOST'),
        'title_all_terms_multiplier' => env('SCOLTA_TITLE_ALL_TERMS_MULTIPLIER'),
        'content_match_boost' => env('SCOLTA_CONTENT_MATCH_BOOST'),
        'recency_boost_max' => env('SCOLTA_RECENCY_BOOST_MAX'),
        'recency_half_life_days' => env('SCOLTA_RECENCY_HALF_LIFE_DAYS'),
        'recency_penalty_after_days' => 1825,
        'recency_max_penalty' => 0.3,
        'expand_primary_weight' => env('SCOLTA_EXPAND_PRIMARY_WEIGHT'),
        'cross_list_bonus' => 0.05,

        // Sub-word frequency guard (scolta-php#156): maximum corpus frequency
        // (fraction of indexed docs) for a multi-word expansion term's
        // constituent word to be searched on its own. Recovers broad-query
        // recall while blocking high-frequency noise words. Preset-overridable:
        // null falls through to the preset (e.g. 'none' and 'content_catalog'
        // broaden this to 0.10). Set SCOLTA_EXPAND_SUBWORD_MAX_FREQUENCY to a
        // value to override; 0 disables sub-word expansion, >= 1 searches every
        // sub-word.
        'expand_subword_max_frequency' => env('SCOLTA_EXPAND_SUBWORD_MAX_FREQUENCY'),

        // Language-aware stop words (1.0.0+)
        // ISO 639-1 code for stop word filtering. Supported: ar, ca, da, de, el,
        // en, es, et, eu, fi, fr, ga, hi, hu, hy, id, it, lt, ne, nl, no, pl,
        // pt, ro, ru, sr, sv, ta, tr, yi. CJK/unknown → no filtering.
        'language' => env('SCOLTA_LANGUAGE', 'en'),
        'custom_stop_words' => array_filter(array_map(
            'trim',
            explode(',', env('SCOLTA_CUSTOM_STOP_WORDS', ''))
        )),

        // Sub-word guard denylist (scolta-php#156 follow-up)
        // Words that are NEVER auto-exempted from the sub-word frequency guard
        // even when the user types them — so a typed-but-generic word (e.g.
        // "hot" on a recipe site) cannot re-flood results via the query-term
        // exemption. Unlike custom_stop_words this does NOT affect relevance
        // scoring or query tokenization; listed words stay searchable. Comma-
        // separated, lowercased. Leave empty unless a typed common word floods.
        'expand_subword_deny_list' => array_filter(array_map(
            fn ($w) => strtolower(trim($w)),
            explode(',', env('SCOLTA_EXPAND_SUBWORD_DENYLIST', ''))
        )),

        // Query-expansion candidate combination (scolta-php#170)
        // How a multi-term expansion's per-sub-query result sets are combined
        // into the AI-summary candidate set:
        //   'relevance_union' (default) — historical behavior; merge all
        //       sub-query hits and rank by relevance.
        //   'round_robin' — deal the top few hits from each sub-query in turn so
        //       the summarizer sees breadth across expansion terms.
        // This is preset-defaulted in scolta-php (round_robin on the
        // content_catalog, blog, and ecommerce presets; relevance_union
        // otherwise). Defaults to null = use the preset's value; set
        // SCOLTA_EXPANSION_COMBINE_MODE to override. The per-sub-query count
        // (K) is locked at 3 inside scolta-php and is no longer configurable.
        'expansion_combine_mode' => env('SCOLTA_EXPANSION_COMBINE_MODE'),

        // Specificity-weighted and co-occurrence ranking.
        // Each partial-match sub-query is weighted by how rare its term is in
        // the corpus, so a match on a rare, intent-bearing term outranks a match
        // on a ubiquitous one. On top of that, a document agreeing with several
        // query and expansion terms outranks one matching a single strong term.
        // None of these six appears in any scolta-php preset, so unlike the
        // preset-overridable keys above they carry concrete defaults rather than
        // a bare null. The defaults are byte-equal to the browser's own
        // fallbacks, so leaving them alone changes nothing.
        //   specificity_weighting — false restores flat sub-query weighting.
        //   specificity_floor — floor for a ubiquitous term's weight (0-1);
        //       lower damps harder. Never zero, so recall is preserved.
        //   specificity_strong_match — specificity at which a match counts as
        //       strong and on-intent (0-1), which stops the partial-match banner
        //       and the AI summary framing a good result set as a failure.
        //   specificity_cooccurrence — multiplier on the agreement bonus (0-5).
        //       Set to 0 to score each document purely by its single best
        //       sub-query, reproducing the prior maximum-only merge exactly.
        //   specificity_agreement_gate — specificity a term must clear to count
        //       toward that bonus (0-1), so near-ubiquitous words earn nothing.
        //   specificity_agreement_decay — geometric factor per successive
        //       agreeing axis (0-5). Below 1 the bonus saturates, so a long
        //       enumerative page cannot out-accumulate a focused one.
        'specificity_weighting' => env('SCOLTA_SPECIFICITY_WEIGHTING', true),
        'specificity_floor' => env('SCOLTA_SPECIFICITY_FLOOR', 0.15),
        'specificity_strong_match' => env('SCOLTA_SPECIFICITY_STRONG_MATCH', 0.55),
        'specificity_cooccurrence' => env('SCOLTA_SPECIFICITY_COOCCURRENCE', 0.9),
        'specificity_agreement_gate' => env('SCOLTA_SPECIFICITY_AGREEMENT_GATE', 0.45),
        'specificity_agreement_decay' => env('SCOLTA_SPECIFICITY_AGREEMENT_DECAY', 1.0),

        // Pluggable recency functions (1.0.0+)
        // Strategies: 'exponential' (base default), 'linear', 'step', 'none',
        // 'custom'. Preset-overridable: null falls through to the preset (the
        // catalog/reference/ecommerce/blog presets set their own strategy). Set
        // SCOLTA_RECENCY_STRATEGY to override.
        'recency_strategy' => env('SCOLTA_RECENCY_STRATEGY'),
        // For 'custom': JSON array of [[days, boost], …] control points.
        // e.g. SCOLTA_RECENCY_CURVE='[[0,1.0],[180,0.5],[365,0.0]]'
        'recency_curve' => json_decode(env('SCOLTA_RECENCY_CURVE', '[]'), true) ?: [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Display
    |--------------------------------------------------------------------------
    |
    | show_attribution — Render "Powered by Scolta" below the search widget.
    |
    | Defaults to false. Set to true if you wish to credit Scolta publicly,
    | for example to meet WordPress.org plugin directory guidelines that
    | encourage attribution for free, open-source tools.
    |
    | When true, the Blade component appends:
    |   <p class="scolta-attribution">Powered by Scolta</p>
    |
    */

    'show_attribution' => env('SCOLTA_SHOW_ATTRIBUTION', false),

    // Preset-overridable display fields default to null = "use the selected
    // Site Type preset's value" (set the SCOLTA_* env var to override). The
    // presets tune these for browsing (e.g. content_catalog/reference raise
    // results_per_page to 12 and ai_summary_top_n to 15). ai_summary_max_chars
    // is not preset-overridable and keeps its concrete default.
    'excerpt_length' => env('SCOLTA_EXCERPT_LENGTH'),
    'results_per_page' => env('SCOLTA_RESULTS_PER_PAGE'),
    'max_pagefind_results' => env('SCOLTA_MAX_PAGEFIND_RESULTS'),
    'ai_summary_top_n' => env('SCOLTA_AI_SUMMARY_TOP_N'),
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
    | Anonymous requests receive {"status": ...} only. The full diagnostic
    | payload requires the 'scolta.health-detail' Gate (default: any
    | authenticated user) — redefine it in your AuthServiceProvider to
    | change who sees the detail.
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

    /*
    |--------------------------------------------------------------------------
    | Sortable Fields
    |--------------------------------------------------------------------------
    |
    | Field names that CMS adapters should extract as sortable attributes
    | (data-pagefind-sort). When non-empty, the AI expansion prompt gains a
    | SORT INTENT section so the LLM can detect sort intent in queries.
    |
    | Example: 'sortable_fields' => ['date', 'price', 'word_count']
    |
    */

    'sortable_fields' => [],

    /*
    |--------------------------------------------------------------------------
    | Amazee.ai Route Prefix and Middleware
    |--------------------------------------------------------------------------
    |
    | Prefix and middleware for the Amazee.ai admin settings UI routes.
    | These are web (session-aware) routes used by the multi-step
    | provisioning and connection flow.
    |
    | SECURE BY DEFAULT: the admin routes can disconnect stored AI
    | credentials and start trials, so they are only registered when you
    | set 'amazee_middleware' to something beyond the bare ['web'] group —
    | typically ['web', 'auth']. With the default value below, the routes
    | do not exist (requests get 404). CLI provisioning
    | (artisan scolta:amazee:provision) and auto-provisioning still work.
    |
    */

    'amazee_route_prefix' => 'scolta/amazee',
    'amazee_middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Sortable Field Descriptions
    |--------------------------------------------------------------------------
    |
    | Human-readable descriptions keyed by field name. When present, these
    | descriptions appear in the sort-intent prompt so the LLM can map
    | natural language to the correct field.
    |
    | Writing good descriptions matters — "Article length in words — higher
    | values mean more comprehensive coverage" lets the LLM map "longest" and
    | "most comprehensive" to this field. A bare field name forces guessing.
    |
    | Example:
    |   'sortable_field_descriptions' => [
    |       'price'      => 'Product price in the store currency',
    |       'word_count' => 'Article length in words',
    |   ]
    |
    */

    'sortable_field_descriptions' => [],

    /*
    |--------------------------------------------------------------------------
    | Filter Fields
    |--------------------------------------------------------------------------
    |
    | Filter dimension names for filter-intent detection. Must match the
    | filter names emitted as data-pagefind-filter attributes by your content
    | gatherer. When non-empty, the AI expansion prompt gains a FILTER INTENT
    | section so the LLM can detect filter intent in queries.
    |
    | Example: 'filter_fields' => ['topic', 'era', 'region']
    |
    */

    'filter_fields' => [],

    /*
    |--------------------------------------------------------------------------
    | Filter Field Descriptions
    |--------------------------------------------------------------------------
    |
    | Human-readable descriptions keyed by filter name. Listing valid values
    | helps the LLM map user language to the correct filter value.
    |
    | Example:
    |   'filter_field_descriptions' => [
    |       'topic' => 'Subject area or domain. Values: Science, History, Biography, Geography, Arts, Technology',
    |       'era'   => 'Historical period. Values: Ancient, Medieval, Modern, Contemporary',
    |   ]
    |
    */

    'filter_field_descriptions' => [],

    /*
    |--------------------------------------------------------------------------
    | Hide Empty Facets
    |--------------------------------------------------------------------------
    |
    | Controls what the filter sidebar does with a facet value that has no
    | results for the current query.
    |
    | true (default) — mainstream faceted-search behavior: a zero-count value is
    | hidden, and a filter group whose values are all zero drops entirely. An
    | active (checked) value stays visible even at zero so it can be unchecked.
    | This keeps a high-cardinality dimension from burying the useful values.
    |
    | false — every value renders, and a zero-count one is shown as a disabled
    | "(0)" row. The value list stays positionally fixed across queries, which
    | some sites prefer for predictability.
    |
    | Note this is a top-level key, not a 'scoring' one: the service provider
    | merges published config with a shallow array_merge, so a top-level key
    | still picks up the package default on a published config that predates it.
    |
    */

    'hide_empty_facets' => env('SCOLTA_HIDE_EMPTY_FACETS', true),

    /*
    |--------------------------------------------------------------------------
    | Search As You Type
    |--------------------------------------------------------------------------
    |
    | Typing in the search box populates a suggestions dropdown under it. The
    | full pipeline — AI query expansion, the AI summary, follow-ups — still
    | runs only on Enter, on the search button, or on selecting a suggestion.
    |
    | On by default, and no index rebuild is needed: suggestions read the index
    | you already have. Set sayt_enabled to false and the widget is byte for
    | byte the pre-1.1.0 one — no dropdown node, no combobox ARIA roles on the
    | input, no browser storage access, no suggest searches.
    |
    | These are ten top-level keys rather than a nested 'sayt' group, for the
    | same reason hide_empty_facets is top-level: mergeConfigFrom() is a shallow
    | array_merge, so a published config that predates them picks up the package
    | defaults at the top level, while a nested group would be replaced whole by
    | whatever the published file happens to contain.
    |
    | Full behaviour, including the browser events and the theming custom
    | properties: scolta-php docs/SAYT.md.
    |
    */

    // Master switch for the suggestions dropdown.
    'sayt_enabled' => env('SCOLTA_SAYT_ENABLED', true),

    // Characters typed before suggestions are requested, counted in graphemes
    // so an emoji or a Devanagari cluster counts as the one character the
    // person typing it sees. CJK sites commonly want 1: a single han character
    // is already a meaningful query.
    'sayt_min_chars' => env('SCOLTA_SAYT_MIN_CHARS', 2),

    // Trailing debounce, in milliseconds, before a suggest cycle fires.
    'sayt_debounce_ms' => env('SCOLTA_SAYT_DEBOUNCE_MS', 150),

    // Most suggestions shown, and the hard cap on fragment loads per pass.
    'sayt_max_suggestions' => env('SCOLTA_SAYT_MAX_SUGGESTIONS', 6),

    // Offer the visitor's own recent searches, stored in their browser under a
    // single scolta-prefixed localStorage key. When false, nothing is read from
    // or written to storage at all.
    'sayt_recent_searches' => env('SCOLTA_SAYT_RECENT_SEARCHES', true),

    // Most recent searches shown. How many are stored is internal to the
    // browser bundle and deliberately larger, so the prefix filter still has
    // something to match.
    'sayt_max_recent' => env('SCOLTA_SAYT_MAX_RECENT', 3),

    // Enrich the dropdown with AI query-expansion term matches. Inert when no
    // AI endpoints are configured or when ai.expand_query is off.
    'sayt_expand' => env('SCOLTA_SAYT_EXPAND', true),

    // Client-side sliding-window cap on SAYT expansion calls per minute. SAYT
    // expansions share the AI flood budget with committed searches — expansion,
    // summarize and follow-up all count against the same per-IP limit — so an
    // unbudgeted suggest path would spend a visitor's whole allowance on
    // prefixes and starve the search they actually ran. Over the cap the
    // dropdown degrades to keyword-only suggestions until the window rolls.
    'sayt_expand_per_minute' => env('SCOLTA_SAYT_EXPAND_PER_MINUTE', 6),

    // Idle delay, in milliseconds, before the AI enrichment call. Separate from
    // and longer than the suggestion debounce: keyword suggestions should
    // appear while typing, an AI call should not.
    'sayt_expansion_delay_ms' => env('SCOLTA_SAYT_EXPANSION_DELAY_MS', 500),

    // What selecting a title suggestion does. 'navigate' goes straight to that
    // result; 'search' puts the title in the box and runs the full search. A
    // recent-search suggestion always runs the search regardless. An
    // unrecognized value clamps to 'navigate'.
    'sayt_suggestion_action' => env('SCOLTA_SAYT_SUGGESTION_ACTION', 'navigate'),
];
