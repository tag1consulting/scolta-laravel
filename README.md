# Scolta for Laravel

[![CI](https://github.com/tag1consulting/scolta-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/tag1consulting/scolta-laravel/actions/workflows/ci.yml)

Built and maintained by [Tag1 Consulting](https://www.tag1.com/) — technology leadership since 2007.

Laravel 11/12/13 package — Artisan commands, `Searchable` trait for Eloquent models, and AI-powered search built on Pagefind.

## Status

Scolta 1.0 — the API documented here is stable. Breaking changes follow semantic versioning: no removal or signature change without a major version bump and a deprecation cycle. File bugs at the repo issue tracker.

## What Is Scolta?

Scolta is a scoring, ranking, and AI layer built on [Pagefind](https://pagefind.app/). Pagefind is the search engine: it builds a static inverted index at publish time, runs a browser-side WASM search engine, produces word-position data, and generates highlighted excerpts. Scolta takes Pagefind's result set and re-ranks it with configurable boosts — title match weight, content match weight, recency decay curves, and phrase-proximity multipliers. No search server required. Queries resolve in the visitor's browser against a pre-built static index.

This package is the Laravel adapter. It provides Artisan commands for building and maintaining the index, a `Searchable` trait for Eloquent models, a `<x-scolta::search />` Blade component, change tracking via an observer pattern, and REST API endpoints for the AI features. The actual scoring, indexing logic, memory management, and AI communication live in [scolta-php](https://github.com/tag1consulting/scolta-php), which this package depends on. Scoring runs client-side via the `scolta.js` browser asset and the pre-built WASM module shipped with scolta-php.

The LLM tier — query expansion, result summarization, follow-up questions — is optional. When enabled, it sends the query text and selected result excerpts to a configured LLM provider (Anthropic, OpenAI, or a self-hosted Ollama endpoint). The base search tier shares nothing with any third party.

## Running Example

The examples in this README and the other Scolta repos use a recipe catalog as the concrete data set. Recipes are a good showcase because recipe vocabulary has genuine cross-dialect mismatches:

- A search for `aubergine parmesan` should surface *Eggplant Parmigiana*.
- A search for `chinese noodle soup` should surface *Lanzhou Beef Noodles*, *Wonton Soup*, and *Dan Dan Noodles*.
- A search for `gluten free pasta` should surface *Zucchini Spaghetti with Pesto* and *Rice Noodle Stir-Fry*.
- A search for `quick dinner under 30 min` should surface *Pad Kra Pao*, *Dan Dan Noodles*, and *Steak Frites*.

Here is how to model and index the recipe catalog in Laravel:

```php
// app/Models/Recipe.php
use Tag1\Scolta\Export\ContentItem;
use Tag1\ScoltaLaravel\Searchable;

class Recipe extends Model
{
    use Searchable;

    public function toSearchableContent(): ContentItem
    {
        return new ContentItem(
            id:       "recipe-{$this->id}",
            title:    $this->name,
            bodyHtml: "<p>{$this->description}</p>"
                    . "<h2>Ingredients</h2><ul>"
                    . implode('', array_map(fn($i) => "<li>{$i}</li>", $this->ingredients))
                    . "</ul><p>Tags: {$this->tags}, {$this->regional_synonyms}</p>",
            url:      "/recipes/{$this->slug}",
            date:     $this->updated_at->format('Y-m-d'),
            siteName: config('scolta.site_name', config('app.name')),
        );
    }

    public function scopeSearchable($query)
    {
        return $query->where('published', true);
    }
}
```

Register the model in `config/scolta.php`:

```php
'models' => [App\Models\Recipe::class],
```

Build the index:

```bash
php artisan scolta:build
```

Then add `<x-scolta::search />` to any Blade template and visit the page. A search for `aubergine parmesan` surfaces *Eggplant Parmigiana* because the body HTML includes both the American term "eggplant" and the Italian name. Scolta's title boost lifts it above pages that mention aubergine only in passing.

The recipe fixture HTML files live in [scolta-php](https://github.com/tag1consulting/scolta-php) at `tests/fixtures/recipes/` if you want a pre-built data set to index without a database.

## Quick Install

```bash
# 1. Install
composer require tag1/scolta-laravel:^1.0 tag1/scolta-php:^1.0

# 2. Publish config, migrations, and assets
php artisan vendor:publish --tag=scolta-config --tag=scolta-migrations --tag=scolta-assets

# 3. Run migrations
php artisan migrate

# 4. Add the Searchable trait to your models and register them in config/scolta.php

# 5. Build the search index
php artisan scolta:build

# 6. Add <x-scolta::search /> to any Blade template

# 7. Set your API key to unlock AI features
```

In `.env`:

```env
SCOLTA_API_KEY=sk-ant-...
```

With an API key configured, search queries are automatically expanded with related terms, results include an AI summary, and visitors can ask follow-up questions.

## Verify It Works

```bash
php artisan scolta:check-setup
```

This verifies PHP version, index directories, indexer selection, AI provider configuration, and binary availability.

```bash
php artisan scolta:status
```

The health endpoint also reports current state: `GET /api/scolta/v1/health`

## What Scolta Is Built For

Scolta is designed for content search on Laravel applications: articles, documentation, product catalogs, knowledge bases, and other Eloquent model content indexed at build time. Laravel powers SaaS products, enterprise applications, API platforms, and content-driven sites — and Scolta is tuned for the content search needs of these applications.

The static-index architecture means no Elasticsearch or Solr server to provision. Scolta replaces hosted search SaaS (Algolia, Coveo, SearchStax) and Solr/Elasticsearch backends for Laravel applications where the search use case is full-text relevance, recency, and phrase matching. It runs on managed hosting where binary execution is restricted, using the PHP indexer automatically.

### Migrating from Laravel Scout

Scout and Scolta solve different problems. Scout drives external search servers (Algolia, Meilisearch, Typesense). Scolta runs Pagefind, which produces a static browser-side index — no search server required. Scolta then re-ranks Pagefind's results and optionally adds an AI layer.

Replace `toSearchArray()` with `toSearchableContent()` and `scopeSearch()` with `scopeSearchable()`. Remove Scout from `composer.json`, publish Scolta's config and migrations, and replace Scout search calls with `<x-scolta::search />`.

What you gain: no external search service bill, AI query expansion and summarization, works on shared and managed hosting. What you give up: Scout's per-record real-time index updates and its driver flexibility.

## Memory and Scale

The default memory profile is `conservative`, which targets a peak RSS under 96 MB and works on shared hosting with a 128 MB PHP `memory_limit`. Scolta never silently upgrades to a larger profile.

The admin interface shows the detected PHP `memory_limit` and suggests a profile. The profile selection is always left to the admin.

Pass the profile via the Artisan CLI:

```bash
php artisan scolta:build --memory-budget=balanced
```

Available profiles: `conservative` (default, ≤96 MB), `balanced` (≤200 MB), `aggressive` (≤384 MB). Higher budget means fewer, larger index chunks and faster builds.

Or set it in `.env`:

```env
SCOLTA_MEMORY_BUDGET=balanced
```

Tested ceiling at the `conservative` profile: 50,000 pages. Higher counts likely work; not certified yet.

### Builds that continue themselves

A corpus too large to index in one process yields when RSS approaches the PHP `memory_limit`, and `scolta:build` finishes the job in fresh processes. It runs each *resume segment* as a child in the foreground and streams its output, so one command drives the whole build and its exit code describes the whole build: 0 only once the index is published and verified on disk.

The flags that shape a build travel with it: `--memory-budget`, `--chunk-size` and `--force` are all passed to each segment, so a forced build stays forced for its whole length rather than only for its first segment.

Two things bound the chain. A segment that hits the memory limit without committing a single page gets no successor, because another one would do exactly the same; and no build may use more than 50 segments in total. Either way the command fails with a message naming the page count reached and the PHP `memory_limit` it ran under, and `The index has not been republished`: raise `memory_limit`, or lower the per-chunk footprint with `--memory-budget=conservative` / `--chunk-size`, then re-run with `--restart`.

## Deploying to PaaS Platforms

On PaaS platforms — including Laravel Cloud, Forge with push-to-deploy, Vapor, Railway, and Render — the filesystem is rebuilt from your repository on every deploy. Any files written outside the repo at install time are wiped, including assets published by `vendor:publish`.

`php artisan vendor:publish --tag=scolta-assets` must run as part of your **build pipeline**, not just during initial setup.

### Wiring it automatically via `post-autoload-dump`

Add it to your **application's** `composer.json` scripts:

```json
"scripts": {
    "post-autoload-dump": [
        "@php artisan package:discover --ansi",
        "@php artisan vendor:publish --tag=scolta-assets --force --ansi"
    ]
}
```

Composer runs `post-autoload-dump` on every `composer install` and `composer update`, which PaaS platforms execute automatically on each deploy. The `--force` flag is required so assets are refreshed even when the destination directory already exists from a previous build cache.

> **Important:** Composer only runs scripts from the **root package** — your application. Scripts in a dependency's `composer.json` (including Scolta's own) are never executed for consumers. You must add the script to your own `composer.json`.

### Platform-specific steps

**Laravel Cloud**: Runs `composer install` on each deployment. The `post-autoload-dump` script above runs automatically.

**Laravel Vapor**: Runs `composer install` in the Lambda package build step. The `post-autoload-dump` script above runs automatically.

**Laravel Forge (push-to-deploy)**: Add the publish command to your Forge deployment script, after the `composer install` line:

```bash
php artisan vendor:publish --tag=scolta-assets --force
```

### Building the index on deploy

Run `php artisan scolta:build` as part of your deploy (build pipeline, release step, or initContainer). It is **synchronous and verified**: it blocks until the index is built and exits 0 only when a usable index is live on disk, so a deploy that gates on its exit code can trust it. If the build cannot produce a valid index, the command exits non-zero — fail your deploy on that rather than serving dead search.

> **Do not pass `--queue` in a deploy step unless you have a worker that finishes before traffic is served.** `--queue` defers the build to the queue: on an asynchronous connection it returns a distinct deferred exit code (`3`) **without** building the index — the index only appears once a worker (`php artisan queue:work`) drains the chain. It is intended for large-corpus background rebuilds, not for the deploy-time index your first requests depend on. An interrupted or never-drained rebuild degrades to the *previous* index (stale), never to an empty one.

## AI Features and Privacy

Scolta's AI tier is optional. When enabled:

- The LLM receives: the query text, and the titles and excerpts of the top N results (default: 10, configurable via `ai_summary_top_n`).
- The LLM does not receive: the full index contents, full page text, user session data, or visitor identity.
- Which provider receives the query data depends on your `SCOLTA_AI_PROVIDER` setting: `anthropic`, `openai`, or a self-hosted endpoint via `SCOLTA_AI_BASE_URL`.

The base search tier — Pagefind index lookup and Scolta WASM scoring — runs entirely in the visitor's browser with no server-side involvement beyond serving static index files.

## Configuration

All settings live in `config/scolta.php` with `.env` overrides. After editing `config/scolta.php`, run `php artisan config:clear`.

### AI Provider

| Setting | `.env` key | `config/scolta.php` key | Default | Description |
| ------- | ---------- | ----------------------- | ------- | ----------- |
| Provider | `SCOLTA_AI_PROVIDER` | `ai_provider` | *(none)* | `anthropic`, `openai` or `amazee`. No default: while none is selected, AI features are off and search works exactly as it does now. |
| API key | `SCOLTA_API_KEY` | `ai_api_key` | — | Authentication for AI features |
| Model | `SCOLTA_AI_MODEL` | `ai_model` | `claude-sonnet-4-5-20250929` | LLM model identifier |
| Expansion model | `SCOLTA_EXPANSION_MODEL` | `ai_expansion_model` | `''` (same as `ai_model`) | Optional separate model for query expansion. When set, `expand-query` uses this model while `summarize` and `followup` use `ai_model`. Empty means all AI operations use `ai_model`. |
| Base URL | `SCOLTA_AI_BASE_URL` | `ai_base_url` | provider default | Custom endpoint for proxies or Azure OpenAI |
| Query expansion | `SCOLTA_AI_EXPAND` | `ai_expand_query` | `true` | Toggle AI query expansion on/off |
| Visitor expansion switch | `SCOLTA_EXPANSION_TOGGLE` | `expansion_toggle` | `true` | Render a switch in the results header letting each visitor turn expanded terms off for themselves. The choice lives in browser storage and only ever narrows — it can never re-enable expansion that `ai_expand_query` has turned off, and no switch is drawn where expansion is unavailable. Set to `false` to make expansion a site-level decision only |
| Summarization | `SCOLTA_AI_SUMMARIZE` | `ai_summarize` | `true` | Toggle AI result summarization on/off |
| Summary top N | — | `ai_summary_top_n` | `10` | How many top results to send to AI for summarization |
| Summary max chars | — | `ai_summary_max_chars` | `4000` | Max content characters sent to AI per request |
| Max follow-ups | `SCOLTA_MAX_FOLLOWUPS` | `max_follow_ups` | `3` | Follow-up questions allowed per session |
| AI languages | `SCOLTA_AI_LANGUAGES` | `ai_languages` | `['en']` | Languages the AI responds in (matches user query language) |

In `.env`:

```env
SCOLTA_AI_PROVIDER=anthropic   # required — there is no default; omit it and AI features stay off
SCOLTA_API_KEY=sk-ant-...
SCOLTA_AI_MODEL=claude-sonnet-4-5-20250929
SCOLTA_AI_EXPAND=true
SCOLTA_AI_SUMMARIZE=true
SCOLTA_EXPANSION_TOGGLE=true
```

For multilingual sites:

```php
// config/scolta.php
'ai_languages' => ['en', 'fr', 'de'],
```

### Search Scoring

Scoring settings live under the `scoring` key in `config/scolta.php`.

| Setting | `.env` key | `config/scolta.php` path | Description |
| ------- | ---------- | ------------------------ | ----------- |
| Title match boost | — | `scoring.title_match_boost` | Boost when query terms appear in the title |
| Title all-terms multiplier | — | `scoring.title_all_terms_multiplier` | Extra multiplier when ALL terms match the title |
| Content match boost | — | `scoring.content_match_boost` | Boost for query term matches in body/excerpt |
| Expand primary weight | — | `scoring.expand_primary_weight` | Weight for original query results vs AI-expanded results (higher = original query dominates; raise to 0.7+ if you want literal keyword matches to win) |
| Recency strategy | `SCOLTA_RECENCY_STRATEGY` | `scoring.recency_strategy` | `exponential`, `linear`, `step`, `none`, or `custom` |
| Recency boost max | — | `scoring.recency_boost_max` | Maximum positive boost for very recent content |
| Recency half-life days | — | `scoring.recency_half_life_days` | Days until recency boost halves |
| Recency penalty after days | — | `scoring.recency_penalty_after_days` | Age before content gets a penalty (~5 years) |
| Recency max penalty | — | `scoring.recency_max_penalty` | Maximum negative penalty for very old content |
| Language | `SCOLTA_LANGUAGE` | `scoring.language` | ISO 639-1 code for stop word filtering |
| Custom stop words | — | `scoring.custom_stop_words` | Extra stop words beyond the language's built-in list |
| Specificity weighting | `SCOLTA_SPECIFICITY_WEIGHTING` | `scoring.specificity_weighting` | Weight each partial match by how rare its term is in the corpus (default `true`), so a match on a rare intent-bearing term outranks a match on a ubiquitous one. This is what stops a common word, typed or leaked from an expansion phrase, from flooding the head of the result list. `false` restores flat sub-query weighting |
| Specificity floor | `SCOLTA_SPECIFICITY_FLOOR` | `scoring.specificity_floor` | Floor for a ubiquitous term's specificity weight (`0`-`1`, default `0.15`). Damped rather than dropped, so recall is preserved; lower is more aggressive damping |
| Specificity strong match | `SCOLTA_SPECIFICITY_STRONG_MATCH` | `scoring.specificity_strong_match` | Specificity at or above which a match counts as strong and on-intent (`0`-`1`, default `0.55`), which stops the partial-match banner and the AI summary framing a good result set as a failure |
| Co-occurrence bonus | `SCOLTA_SPECIFICITY_COOCCURRENCE` | `scoring.specificity_cooccurrence` | Multiplier on the bonus a result earns for agreeing with several query and expansion terms at once rather than matching one strongly (`0`-`5`, default `0.9`). Set to `0` to score each result purely by its single best-matching sub-query |
| Co-occurrence gate | `SCOLTA_SPECIFICITY_AGREEMENT_GATE` | `scoring.specificity_agreement_gate` | Specificity a term must clear to count toward the agreement bonus (`0`-`1`, default `0.45`), so a near-ubiquitous word earns none |
| Co-occurrence decay | `SCOLTA_SPECIFICITY_AGREEMENT_DECAY` | `scoring.specificity_agreement_decay` | Geometric factor applied to each successive agreeing term (`0`-`5`, default `1.0`). Below `1` the bonus saturates, so a long enumerative page cannot out-accumulate a focused one through breadth alone |
| Expansion combine mode | `SCOLTA_EXPANSION_COMBINE_MODE` | `scoring.expansion_combine_mode` | How multi-term expansion sub-query results are combined for the AI summary: `relevance_union` or `round_robin`. Preset-defaulted in scolta-php (`round_robin` on the content_catalog/blog/ecommerce presets, `relevance_union` otherwise); an explicit value overrides the preset |

Defaults and the full reference: [scolta-php `docs/CONFIG_REFERENCE.md`](https://github.com/tag1consulting/scolta-php/blob/main/docs/CONFIG_REFERENCE.md).

**News site** (recency matters a lot):

```php
// config/scolta.php
'scoring' => [
    'recency_boost_max'           => 0.8,
    'recency_half_life_days'      => 30,
    'recency_penalty_after_days'  => 365,
    'recency_max_penalty'         => 0.5,
],
```

**Documentation site** (recency doesn't matter, titles matter a lot):

```php
'scoring' => [
    'recency_strategy'           => 'none',
    'title_match_boost'          => 2.0,
    'title_all_terms_multiplier' => 2.5,
],
```

**Recipe catalog** (no recency, title precision matters):

```php
'scoring' => [
    'recency_strategy'           => 'none',
    'title_match_boost'          => 1.5,
    'title_all_terms_multiplier' => 2.0,
],
```

### Display

Display settings are top-level keys in `config/scolta.php`.

| Setting | `config/scolta.php` key | Description |
| ------- | ----------------------- | ----------- |
| Excerpt length | `excerpt_length` | Characters shown in result excerpts |
| Results per page | `results_per_page` | Results shown per page |
| Max Pagefind results | `max_pagefind_results` | Total results fetched from index before scoring |
| Show attribution | `show_attribution` | Render "Powered by Scolta" below the search widget |

Defaults and the full reference: [scolta-php `docs/CONFIG_REFERENCE.md`](https://github.com/tag1consulting/scolta-php/blob/main/docs/CONFIG_REFERENCE.md).

### Site Identity

| Setting | `.env` key | `config/scolta.php` key | Default | Description |
| ------- | ---------- | ----------------------- | ------- | ----------- |
| Site name | `SCOLTA_SITE_NAME` | `site_name` | app name | Included in AI prompts so the AI knows what site it's searching |
| Site description | — | `site_description` | `website` | Brief description for AI context |

### Custom Prompts

Override prompts via the top-level keys `prompt_expand_query`, `prompt_summarize`, and `prompt_follow_up` in `config/scolta.php`, or use an event listener:

```php
// app/Listeners/EnrichScoltaPrompt.php
use Tag1\ScoltaLaravel\Events\PromptEnrichEvent;

class EnrichScoltaPrompt
{
    public function handle(PromptEnrichEvent $event): void
    {
        if ($event->promptName === 'summarize') {
            $event->resolvedPrompt .= "\n\nFocus on dietary information and cuisine type.";
        }
    }
}
```

Register in `EventServiceProvider`:

```php
protected $listen = [
    \Tag1\ScoltaLaravel\Events\PromptEnrichEvent::class => [
        \App\Listeners\EnrichScoltaPrompt::class,
    ],
];
```

### Preset

**Getting fewer results than you expect on a recipe, product, or catalog site?** Set `SCOLTA_PRESET=content_catalog` and rebuild your index — the **Recipe & Content Catalog** preset widens search breadth so ingredient, technique, and product-attribute searches return the fuller set of matches you'd expect.

A preset is the recommended way to tune scoring: pick the one that matches your site instead of setting individual numbers. Set `SCOLTA_PRESET` in `.env` (or edit `config/scolta.php`) to apply one. Any explicit values in the `scoring` array still override the preset.

| Preset | Best for |
| ------ | -------- |
| `content_catalog` | Recipe sites, product/content catalogs, wikis |
| `reference` | Documentation, knowledge bases, encyclopedias |
| `ecommerce` | Online stores, product catalogs |
| `blog` | Blogs, news, editorial content |
| `none` | No preset (default) — all values from the `scoring` array |

```env
SCOLTA_PRESET=content_catalog
```

For the evidence behind each preset — the scoring sweeps and per-parameter data — see [scolta-php's `docs/TUNING.md`](https://github.com/tag1consulting/scolta-php/blob/main/docs/TUNING.md).

### Indexer and Memory

| Setting | `.env` key | `config/scolta.php` key | Default | Description |
| ------- | ---------- | ----------------------- | ------- | ----------- |
| Indexer backend | `SCOLTA_INDEXER` | `indexer` | `auto` | `auto` (always PHP), `php`, or `binary` |
| Memory budget | `SCOLTA_MEMORY_BUDGET` | `memory_budget.profile` | `conservative` | `conservative`, `balanced`, or `aggressive` |
| Chunk size | `SCOLTA_CHUNK_SIZE` | `memory_budget.chunk_size` | profile default | Pages per chunk during PHP indexer build |

### Pagefind

| Setting | `.env` key | `config/scolta.php` path | Default | Description |
| ------- | ---------- | ------------------------ | ------- | ----------- |
| Binary path | `SCOLTA_PAGEFIND_BINARY` | `pagefind.binary` | `pagefind` | Path to Pagefind CLI binary |
| Build dir | `SCOLTA_BUILD_DIR` | `pagefind.build_dir` | `storage/scolta/build` | HTML export directory for binary pipeline |
| Output dir | `SCOLTA_OUTPUT_DIR` | `pagefind.output_dir` | `public/scolta-pagefind` | Pagefind index output directory |

### Caching and Rate Limiting

| Setting | `.env` key | `config/scolta.php` key | Default | Description |
| ------- | ---------- | ----------------------- | ------- | ----------- |
| Cache TTL | `SCOLTA_CACHE_TTL` | `cache_ttl` | `2592000` (30 days) | AI response cache TTL in seconds |
| Rate limit | `SCOLTA_RATE_LIMIT` | `rate_limit` | `30` | Max API requests per minute per IP |

### Routes and Middleware

| Setting | `config/scolta.php` key | Default | Description |
| ------- | ----------------------- | ------- | ----------- |
| Route prefix | `route_prefix` | `api/scolta/v1` | Prefix for all Scolta API routes |
| API middleware | `middleware` | `['api']` | Middleware for AI API routes |
| Health middleware | `health_middleware` | `['api']` | Middleware for the health check endpoint |
| Amazee route prefix | `amazee_route_prefix` | `scolta/amazee` | Prefix for Amazee.ai admin settings routes |
| Amazee middleware | `amazee_middleware` | `['web']` | Middleware for Amazee.ai settings routes. With the default, the routes are **not registered** — set it beyond the bare `['web']` group (e.g. `['web', 'auth']`) to enable the admin UI |

### Auto Rebuild

| Setting | `.env` key | `config/scolta.php` key | Default | Description |
| ------- | ---------- | ----------------------- | ------- | ----------- |
| Auto rebuild | `SCOLTA_AUTO_REBUILD` | `auto_rebuild` | `true` | Dispatch rebuild to queue on content changes |
| Rebuild delay | `SCOLTA_AUTO_REBUILD_DELAY` | `auto_rebuild_delay` | `300` | Debounce delay in seconds |

### Sort and Filter Fields

| Setting | `config/scolta.php` key | Default | Description |
| ------- | ----------------------- | ------- | ----------- |
| Sortable fields | `sortable_fields` | `[]` | Field names for `data-pagefind-sort` attributes |
| Sort descriptions | `sortable_field_descriptions` | `[]` | Human-readable sort field descriptions for LLM |
| Filter fields | `filter_fields` | `[]` | Pagefind filter dimension names |
| Filter descriptions | `filter_field_descriptions` | `[]` | Human-readable filter descriptions for LLM |
| Hide empty facets | `hide_empty_facets` | `true` | Hide a facet value with no results for the current query, and drop a filter group whose values are all zero; an active value stays visible so it can be unchecked. Set `false` (or `SCOLTA_HIDE_EMPTY_FACETS=false`) to render every value, showing a zero-count one as a disabled "(0)" row |

### Search as You Type

Typing in the search box populates a suggestions dropdown under it. The full search — AI query expansion, the AI summary, follow-ups — still runs only on Enter, on the search button, or when a visitor picks a suggestion. It is on by default and needs no index rebuild: suggestions read the index you already have.

| Setting | `.env` key | `config/scolta.php` key | Default | Description |
| ------- | ---------- | ----------------------- | ------- | ----------- |
| Suggestions | `SCOLTA_SAYT_ENABLED` | `sayt_enabled` | `true` | Master switch. `false` restores the pre-1.1.0 widget exactly: no dropdown node, no combobox ARIA roles, no browser storage, no suggest searches |
| Minimum characters | `SCOLTA_SAYT_MIN_CHARS` | `sayt_min_chars` | `2` | Characters typed before suggestions are requested, counted in graphemes so an emoji is one character. CJK sites commonly want `1` |
| Typing debounce | `SCOLTA_SAYT_DEBOUNCE_MS` | `sayt_debounce_ms` | `150` | Trailing debounce in milliseconds before a suggest cycle fires |
| Max suggestions | `SCOLTA_SAYT_MAX_SUGGESTIONS` | `sayt_max_suggestions` | `6` | Most suggestions shown, and the cap on fragment loads per pass |
| Recent searches | `SCOLTA_SAYT_RECENT_SEARCHES` | `sayt_recent_searches` | `true` | Offer the visitor's own recent searches, kept in their browser under a single `localStorage` key. `false` reads and writes nothing |
| Max recent searches | `SCOLTA_SAYT_MAX_RECENT` | `sayt_max_recent` | `3` | Most recent searches shown above the content suggestions |
| AI enrichment | `SCOLTA_SAYT_EXPAND` | `sayt_expand` | `true` | Enrich suggestions with AI query expansion. Inert with no AI endpoints configured or with `ai.expand_query` off |
| AI enrichment cap | `SCOLTA_SAYT_EXPAND_PER_MINUTE` | `sayt_expand_per_minute` | `6` | Expansion calls per visitor per minute. SAYT expansions share the AI flood budget with committed searches, so an uncapped suggest path would spend a visitor's allowance on prefixes and starve the search they actually ran. Over the cap the dropdown degrades to keyword-only suggestions |
| AI enrichment delay | `SCOLTA_SAYT_EXPANSION_DELAY_MS` | `sayt_expansion_delay_ms` | `500` | Idle milliseconds before an enrichment call. Longer than the typing debounce on purpose |
| Suggestion action | `SCOLTA_SAYT_SUGGESTION_ACTION` | `sayt_suggestion_action` | `navigate` | `navigate` goes straight to the result; `search` puts the title in the box and runs the full search. A recent search always searches |

**If you have already published `config/scolta.php`, check it before relying on these defaults.** The service provider merges the package config with `mergeConfigFrom()`, which is a shallow `array_merge()` of the package file under your published one. That is why all ten are top-level keys rather than a `sayt` group: a top-level key missing from your published file still picks up the package default, while a published `sayt` group would have replaced the package's group whole and taken every default in it with it. Two cases still need your attention:

- **You want to change a value.** Add the key to your published `config/scolta.php`. Editing the package file under `vendor/` is not persistent.
- **You run `php artisan config:cache`.** `mergeConfigFrom()` is skipped entirely when the configuration is cached, so a cached config built before this release carries none of these keys. Re-run `php artisan config:cache` after upgrading.

Full behaviour, including the browser events and the theming custom properties: [scolta-php `docs/SAYT.md`](https://github.com/tag1consulting/scolta-php/blob/main/docs/SAYT.md).

### Amazee.ai Integration

Amazee.ai provides a managed LiteLLM proxy. Try it for AI-powered search with a free demo, no email required; sign in with your email to set up an account and keep it when the demo credit runs out.

Connecting is an explicit action, through either the CLI command or the admin settings page below, and there are exactly two of them:

- **Try the demo** — one action, no email, no account, no card. Runs until the demo's included credit is used up. One-time per site: once it has been used, the settings page and the command both point you at the account path instead of failing opaquely.
- **Enter your Amazee credentials** — sign in with the email address on your amazee.ai account. Amazee emails a verification code, you pick a region, and your account's credentials are stored for you. If you do not have an account yet, this creates one. You never generate or paste an API key: this mirrors amazee.ai's own `ai_provider_amazeeio` module, so there is deliberately no bring-your-own-key form. This flow needs a browser, so it lives on the settings page rather than in the command.

Nothing connects on your behalf: with no `SCOLTA_API_KEY` and no stored connection, search runs with AI features off (queries are not expanded and no summary is generated) until you take one of those actions. Configuring `SCOLTA_API_KEY` takes precedence and clears any stored Amazee.ai connection, so a leftover connection can never shadow your own key.

The settings page and `php artisan scolta:status` state which of the two actions established the current connection, because that is recorded when it happens rather than inferred afterwards; a connection made before Scolta recorded it says only "Connected to Amazee.ai".

**CLI provisioning:**

```bash
php artisan scolta:amazee:provision            # the free demo — no email needed
php artisan scolta:amazee:provision user@example.com   # optionally bind the demo to an address
```

**Admin UI:** The admin settings UI at `/scolta/amazee` (configurable via `amazee_route_prefix`) provides the multi-step connection flow. Its routes can disconnect stored AI credentials, so they are **disabled by default** — they are only registered when you configure `amazee_middleware` with protection beyond the bare `['web']` group:

```php
// config/scolta.php
'amazee_middleware' => ['web', 'auth'],
```

With the shipped default (`['web']`), requests to these routes return 404 and the CLI command above is the way to enable Amazee.ai.

**Routes (when enabled):**

| Method | Path | Description |
| ------ | ---- | ----------- |
| GET | `/scolta/amazee` | Settings page |
| POST | `/scolta/amazee/trial` | Start free trial |
| POST | `/scolta/amazee/request-code` | Request OTP code |
| POST | `/scolta/amazee/verify-code` | Verify OTP code |
| GET | `/scolta/amazee/regions` | List available regions |
| POST | `/scolta/amazee/connect` | Complete connection |
| DELETE | `/scolta/amazee/disconnect` | Disconnect |

### Migrations

Scolta uses two database tables. Publish and run migrations during installation:

```bash
php artisan vendor:publish --tag=scolta-migrations
php artisan migrate
```

| Table | Description |
| ----- | ----------- |
| `scolta_tracker` | Change tracking for Eloquent models. The `ScoltaObserver` writes here when models are created, updated, or deleted. Used by incremental builds to process only changed content. |
| `scolta_config` | Key/value config store for Amazee.ai credentials and auto-configured model settings. Tokens are encrypted via Laravel's `Crypt` facade. |

## Debugging

### "Pagefind binary not found"

On managed hosting where `exec()` is disabled, the package falls back to the PHP indexer automatically. The PHP indexer works on WP Engine, Kinsta, Flywheel, Pantheon, and any host where `exec()` is unavailable. It supports 14 languages via Snowball stemming. The search experience is identical to using the binary.

```bash
php artisan scolta:check-setup
php artisan scolta:status
```

To install the binary on a host that supports it:

```bash
php artisan scolta:download-pagefind
# or: npm install -g pagefind
```

Set `SCOLTA_INDEXER=binary` in `.env` and rebuild.

### "AI features not working"

1. Verify API key: `php artisan scolta:check-setup`
2. Clear stale cache: `php artisan scolta:clear-cache`
3. Clear config cache: `php artisan config:clear`
4. Confirm the model name in `config/scolta.php`

### "AI summary says 'I don't have enough context'"

The defaults (10 results, 4000 chars) are already tuned for curation. If still insufficient, increase further:

```php
// config/scolta.php
'ai_summary_top_n'     => 15,
'ai_summary_max_chars' => 6000,
```

### "AI responses are in the wrong language"

Set `ai_languages` to match your site's language(s):

```php
'ai_languages' => ['de'],  // or ['en', 'fr', 'de'] for multilingual
```

### "Expanded queries return irrelevant results"

Raise `expand_primary_weight` (default: 0.5) to make original query terms dominate more, or disable expansion:

```php
// config/scolta.php
'scoring' => [
    'expand_primary_weight' => 0.8,  // closer to 1.0 = original query dominates
],
// or: 'ai_expand_query' => false,
```

### "No search results"

1. Check index status: `php artisan scolta:status`
2. Run a full rebuild: `php artisan scolta:build`
3. Verify published assets: `php artisan vendor:publish --tag=scolta-assets --force`
4. Confirm the Pagefind output directory is web-accessible (must be under `public/`)

### "Models not being indexed"

Run `php artisan scolta:discover` to find `Searchable` models not registered in `config/scolta.php`. The observer only tracks models listed there.

### "The build stalled at N pages" / "did not complete within 50 resume segments"

The build continued itself in fresh processes and the chain was stopped — a segment committed nothing, or the 50-segment allowance ran out. The index was **not** republished and the previous one is still serving; every segment's output is on the console above the message.

1. Give each process more room and rebuild: `php artisan scolta:build --memory-budget=balanced --restart`
2. If `memory_limit` cannot be raised, shrink the chunks instead: `--chunk-size=25`

## Add the Searchable Trait

```php
use Tag1\Scolta\Export\ContentItem;
use Tag1\ScoltaLaravel\Searchable;

class Article extends Model
{
    use Searchable;

    public function toSearchableContent(): ContentItem
    {
        return new ContentItem(
            id:       "article-{$this->id}",
            title:    $this->title,
            bodyHtml: $this->body,
            url:      "/articles/{$this->slug}",
            date:     $this->updated_at->format('Y-m-d'),
            siteName: config('scolta.site_name', config('app.name')),
        );
    }

    // Optional: filter which records to index
    public function scopeSearchable($query)
    {
        return $query->where('published', true);
    }
}
```

Register the model in `config/scolta.php`:

```php
'models' => [
    App\Models\Article::class,
    App\Models\Page::class,
],
```

## Artisan Commands

```bash
php artisan scolta:build                    # Full build: synchronous and verified (exit 0 = index built and live)
php artisan scolta:build --queue            # Defer the build to the queue (index is NOT built until a worker drains the chain)
php artisan scolta:build --incremental      # Only process tracked changes
php artisan scolta:build --skip-pagefind    # Export HTML without rebuilding index
php artisan scolta:build --memory-budget=balanced  # Use balanced memory profile
php artisan scolta:build --resume           # Resume an interrupted PHP index build
php artisan scolta:build --restart          # Discard interrupted state and rebuild from scratch (also discards the page-table ledger)
php artisan scolta:build --reset-ledger     # Discard the page-table ledger under a plain build (escape hatch for a duplicate page ordinal)
php artisan scolta:export                   # Export content to HTML only
php artisan scolta:export --incremental     # Only export tracked changes
php artisan scolta:rebuild-index            # Rebuild index from existing HTML files
php artisan scolta:status                   # Show tracker, content, index, and AI status
php artisan scolta:discover                 # Find Searchable models not yet in config
php artisan scolta:clear-cache              # Clear Scolta AI response caches
php artisan scolta:cleanup                  # Remove stale index artifacts, orphaned state files, and retired indexes
php artisan scolta:cleanup --dry-run        # Show what would be removed without deleting
php artisan scolta:cleanup --retired-only    # Sweep retired indexes only; leave build state alone
php artisan scolta:cleanup --max-seconds=60 # Stop sweeping retired indexes after 60 seconds
php artisan scolta:memory-budget            # Show the current memory budget profile
php artisan scolta:memory-budget --set=balanced  # Set profile: conservative, balanced, or aggressive
php artisan scolta:download-pagefind        # Download Pagefind binary for your platform
php artisan scolta:check-setup              # Verify PHP, indexer, and configuration
php artisan scolta:amazee:provision {email}  # Enable Amazee.ai with a free trial
php artisan scolta:amazee:provision {email} --force  # Provision even if a provider is already configured
```

### Retired-index cleanup

Publishing a new index renames the outgoing one to a `.scolta-trash-*` directory beside `pagefind/` and deletes it after the swap, rather than unlinking it file by file inside the swap. On NFS-backed storage that inline deletion ran at single-digit files per second, so a finished build looked hung for hours while the new index was already live. A rename is O(1), and the deletion afterwards is parallelized (16 concurrent `rm` workers) under a CLI process; environments without process spawning fall back to serial deletion automatically.

A successful build sweeps its own trash: `scolta:build` and the queued `FinalizeIndex` job both go through the orchestrator, which sweeps right after the swap. Two things are left over for a backstop — a build that failed or was killed during the merge (each retry retires the previous attempt's staging directory into trash), and the `--indexer=binary` paths (`scolta:build --indexer=binary`, `scolta:rebuild-index`), which never reach the orchestrator.

**Scolta schedules that backstop for you.** The service provider registers a daily `scolta:cleanup --retired-only` on your application's scheduler, so nothing needs wiring beyond the `schedule:run` cron entry Laravel already asks for. It shows up under its own name in `php artisan schedule:list`. Each run spends at most `cleanup.cron_seconds` (default 180, `SCOLTA_CLEANUP_CRON_SECONDS`) deleting trash and then stops; the next run resumes on whatever is left. Set it to `0` to register no task at all, and schedule your own if you want different timing:

```php
// routes/console.php — only needed if you set SCOLTA_CLEANUP_CRON_SECONDS=0
use Illuminate\Support\Facades\Schedule;

Schedule::command('scolta:cleanup --retired-only --max-seconds=180')->hourly();
```

On demand, and unbounded unless you ask otherwise:

```bash
php artisan scolta:cleanup
php artisan scolta:cleanup --dry-run      # List what would be deleted, delete nothing
```

Cleanup is always safe: the live `pagefind/` index is never touched, `.scolta-new` and `.scolta-building` are left alone because a build may be using them right now, and a directory that cannot be deleted is left for the next run. `.scolta-trash-*` directories are also safe to remove by hand at any time. `--retired-only` restricts the command to this sweep; without it, it also clears stale build-state files, which is why the scheduled run passes it. When the command cannot resolve `pagefind.output_dir`, or the directory is not there, it writes to the Laravel log as well as stdout, so a scheduled run that is quietly doing nothing is visible in `storage/logs/`.

## API Endpoints

| Method | Path | Middleware | Description |
| ------ | ---- | ---------- | ----------- |
| POST | `/api/scolta/v1/expand-query` | api, throttle:scolta | Expand a search query |
| POST | `/api/scolta/v1/summarize` | api, throttle:scolta | Summarize search results |
| POST | `/api/scolta/v1/followup` | api, throttle:scolta | Continue a conversation |
| GET | `/api/scolta/v1/health` | api | Health check (status only when anonymous) |
| GET | `/api/scolta/v1/build-progress` | api, auth:sanctum | Build progress status |
| POST | `/api/scolta/v1/rebuild-now` | api, auth:sanctum | Dispatch a rebuild job |

Route prefix and middleware are configurable via `route_prefix` and `middleware` in `config/scolta.php`.

The `build-progress` and `rebuild-now` endpoints require Sanctum authentication and are intended for admin dashboards. The AI endpoints (`expand-query`, `summarize`, `followup`) use the configurable `middleware` array and are typically public-facing.

### Health detail authorization

`GET /api/scolta/v1/health` always answers monitoring tools: anonymous requests
get `{"status": "ok"}` (or `"degraded"`), HTTP 200. The full diagnostic payload
(AI provider, index integrity, tracker counts, asset staleness) requires the
`scolta.health-detail` Gate. By default any authenticated user passes. To change
who sees the detail, redefine the gate in your `AuthServiceProvider`:

```php
Gate::define('scolta.health-detail', fn (User $user) => $user->isAdmin());
```

> **Note:** Amazee.ai admin routes (`/scolta/amazee/*`) use `web` middleware and are documented in the [Amazee.ai Integration](#amazeeai-integration) section below.

## Searchable Trait API

| Method | Default | Description |
| ------ | ------- | ----------- |
| `toSearchableContent()` | column heuristic | Return a `ContentItem` for indexing |
| `scopeSearchable($query)` | all records | Filter which records to index |
| `getSearchableType()` | class name | Content type identifier for tracking |
| `shouldBeSearchable()` | `true` | Whether this instance should be indexed |

## Optional Upgrades

### Upgrade to the Pagefind binary indexer

On hosts with Node.js ≥ 18 or binary execution support, the Pagefind binary is 5–10× faster than the PHP indexer:

```bash
php artisan scolta:download-pagefind
# or: npm install -g pagefind
```

Set `SCOLTA_INDEXER=binary` in `.env` and rebuild. The PHP indexer continues to work on managed hosts (WP Engine, Kinsta, Pantheon, etc.) where binary execution is disabled.

### Keeping the Index Fresh

When **auto_rebuild** is enabled (`SCOLTA_AUTO_REBUILD=true` in `.env`), a `ScoltaObserver` watches the models listed in `config/scolta.php` and dispatches a debounced `TriggerRebuild` job whenever a model is saved or deleted (default delay: 5 minutes). This requires a queue worker running.

Three paths are available, in order of reliability:

#### Path A: Queue worker / Supervisor (recommended)

Enable **auto_rebuild** and run a persistent queue worker:

```bash
php artisan queue:work --tries=3
```

For production, use [Supervisor](https://laravel.com/docs/queues#supervisor-configuration) or [Laravel Forge](https://forge.laravel.com) to keep the worker running. Forge configures this automatically.

Content saves trigger `ScoltaObserver`, which dispatches a `TriggerRebuild` job after the configured delay. The queue worker processes that job in the background.

#### Path B: Laravel Scheduler

Add a scheduled rebuild to your app. One system cron entry handles all Laravel scheduled tasks:

```
* * * * * cd /var/www/html && php artisan schedule:run 2>&1 | logger -t scolta
```

Then schedule the build in `routes/console.php` (Laravel 11+):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('scolta:build --incremental')->everyFifteenMinutes();
```

Or in `app/Console/Kernel.php` (Laravel 10):

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('scolta:build --incremental')->everyFifteenMinutes();
}
```

`--incremental` only processes tracked changes, so runs are fast when nothing has changed.

#### Path C: System cron (direct)

Call `scolta:build` directly from system cron, bypassing the Scheduler:

```
*/15 * * * * cd /var/www/html && php artisan scolta:build --incremental 2>&1 | logger -t scolta
```

Simpler than the Scheduler but without Laravel's logging integration and overlap protection.

## Requirements

- Laravel 11, 12, or 13
- PHP 8.1+

The Pagefind binary is optional — the PHP indexer works without it.

### Laravel 11 and end-of-life versions

Scolta runs on Laravel 11, 12, and 13, and its test suite covers all three so apps on older releases aren't stranded. Be aware of what that means for Laravel 11 specifically:

**Laravel 11 reached end of life on 12 March 2026.** The Laravel team no longer ships security patches for it, so advisories against `laravel/framework` 11.x stay open with no fix. To see which advisories affect the version you're actually running, run `composer audit` in your project — it reports the affected and fixed version ranges from the same database Scolta's CI uses. The published list is maintained at [Laravel's security advisories](https://github.com/laravel/framework/security/advisories).

Scolta installs and tests cleanly on Laravel 11 because it deliberately allows these upstream framework advisories in its own CI, which keeps the 11.x compatibility check green. **That does not make your application secure** — the Laravel version your app runs is your responsibility, and on 11.x you are running an unsupported framework with known, unpatched holes.

If you are on Laravel 11, upgrade to Laravel 12 (security-supported through 24 February 2027) or Laravel 13. If you must stay on 11 for now, treat it as temporary and plan the upgrade.

## Testing

**Unit tests** (no Laravel bootstrap required):

```bash
cd packages/scolta-laravel
./vendor/bin/phpunit
```

**Integration tests** (requires DDEV):

```bash
cd test-laravel-12
ddev exec php vendor/bin/phpunit --testsuite=Integration
```

**Coding standards:**

```bash
cd packages/scolta-laravel
composer lint    # Laravel Pint
composer format  # Auto-fix violations
```

## Architecture

```text
scolta-laravel (this package)      scolta-php              scolta-core (browser WASM)
  Artisan commands ──────────> ContentExporter ──────> cleanHtml()
  ScoltaAiService ───────────> AiClient                buildPagefindHtml()
  ScoltaServiceProvider ─────> ScoltaConfig
  Searchable trait ──────────> DefaultPrompts            (runs in browser)
  ScoltaObserver ────────────> PagefindBinary            scoreResults()
  LaravelCacheDriver ────────> CacheDriverInterface      mergeResults()
```

This package handles Laravel-specific concerns: Artisan commands, Eloquent model observation, Blade components, route registration, publishable config/migrations, and middleware. It depends on scolta-php and never on scolta-core directly. Scoring runs client-side via WebAssembly loaded by `scolta.js`.

```text
src/
  ScoltaServiceProvider.php              Service provider (auto-discovered)
  Searchable.php                         Trait for Eloquent models
  Commands/BuildCommand.php              artisan scolta:build
  Commands/StatusCommand.php             artisan scolta:status
  Commands/DiscoverCommand.php           artisan scolta:discover
  Commands/DownloadPagefindCommand.php   artisan scolta:download-pagefind
  Http/Controllers/ExpandQueryController.php
  Http/Controllers/SummarizeController.php
  Http/Controllers/FollowUpController.php
  Http/Controllers/HealthController.php
  Models/ScoltaTracker.php               Change tracking model
  Observers/ScoltaObserver.php           Auto-tracking observer
  Services/ScoltaAiService.php           AI service wrapper
  Services/ContentSource.php             Eloquent content source
config/scolta.php                        Publishable configuration
database/migrations/                     Tracker table migration
routes/api.php                           API route definitions
resources/views/components/search.blade.php  <x-scolta::search /> component
```

## External Services

Scolta connects to external services under specific conditions. No data is sent automatically — all connections are triggered by developer action or explicit configuration.

### GitHub API (api.github.com)

**When:** A developer runs `php artisan scolta:download-pagefind` to download the Pagefind binary.
**What is sent:** A standard HTTPS GET request to `https://api.github.com/repos/CloudCannon/pagefind/releases/latest`. No personally identifiable information is transmitted beyond standard HTTP request headers (IP address, user agent).
**Service:** GitHub, operated by GitHub, Inc. (a subsidiary of Microsoft Corporation).
**Terms of Service:** https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
**Privacy Statement:** https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement

### Pagefind Binary (GitHub Releases / Pagefind)

**When:** `php artisan scolta:download-pagefind` downloads the Pagefind binary from GitHub Releases after querying the GitHub API above.
**What is sent:** A standard HTTPS GET request to download the release archive. No personally identifiable information is transmitted beyond standard HTTP request headers.
**Service:** Pagefind is an open-source project (MIT license) maintained by the Pagefind project.
**Pagefind:** https://pagefind.app/
**CloudCannon:** https://cloudcannon.com/
**Pagefind License:** https://github.com/Pagefind/pagefind/blob/main/LICENSE

### AI Provider APIs

**When:** A visitor performs a search and AI features are enabled (`SCOLTA_AI_EXPAND=true` or `SCOLTA_AI_SUMMARIZE=true` in `.env`). AI features are disabled by default and require an API key to be configured.
**What is sent:** The user's search query text and selected page content excerpts (for result summarization) are sent to the configured AI provider's API endpoint. See [AI Features and Privacy](#ai-features-and-privacy) for full details on what is and is not transmitted.
**Providers:** The specific provider depends on the `SCOLTA_AI_PROVIDER` setting:

- **Anthropic (Claude)** — processes search queries and page excerpts.
  Terms of Service: https://www.anthropic.com/legal/consumer-terms
  Privacy Policy: https://www.anthropic.com/legal/privacy
- **OpenAI** — processes search queries and page excerpts.
  Terms of Use: https://openai.com/policies/terms-of-use
  Privacy Policy: https://openai.com/policies/privacy-policy
- **OpenAI-compatible endpoints** (including self-hosted Ollama and other providers) — any endpoint configured via `SCOLTA_AI_BASE_URL`. Review the terms and privacy policy of your chosen provider.

No AI API calls are made unless `SCOLTA_API_KEY` is set and AI features are enabled.

## About Tag1 Consulting

Scolta is designed, built, and maintained by [Tag1 Consulting](https://www.tag1.com/). Tag1 has been delivering technology leadership since 2007 and is one of the leading open-source consulting firms in the world.

Tag1 offers AI strategy, architecture, and implementation consulting — from evaluating whether AI search is right for your organization, to production deployment and ongoing tuning. If you need help integrating Scolta, customizing scoring for your content model, or connecting it to your AI provider of choice, [get in touch](https://www.tag1.com/).

## Credits

Scolta is built on [Pagefind](https://pagefind.app/) by [CloudCannon](https://cloudcannon.com/). Without Pagefind, Scolta has no search to score — the index format, WASM search engine, word-position data, and excerpt generation are all Pagefind's. Scolta's contribution is the layer that sits on top: configurable scoring, multi-adapter ranking parity, AI features, and platform glue.

## License

MIT

## Related Packages

- [scolta-core](https://github.com/tag1consulting/scolta-core) — Rust/WASM scoring, ranking, and AI layer that runs in the browser.
- [scolta-php](https://github.com/tag1consulting/scolta-php) — PHP library that indexes content into Pagefind-compatible indexes, plus the shared orchestration and AI client.
- [scolta-drupal](https://github.com/tag1consulting/scolta-drupal) — Drupal 10/11 Search API backend with Drush commands, admin settings form, and a search block.
- [scolta-wp](https://github.com/tag1consulting/scolta-wp) — WordPress 6.x plugin with WP-CLI commands, Settings API page, and a `[scolta_search]` shortcode.
