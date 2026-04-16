# Scolta for Laravel

[![CI](https://github.com/tag1consulting/scolta-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/tag1consulting/scolta-laravel/actions/workflows/ci.yml)

Scolta is a browser-side search engine: the index lives in static files, scoring runs in the browser via WebAssembly, and an optional AI layer handles query expansion and summarization. No search server required. "Scolta" is archaic Italian for sentinel — someone watching for what matters.

This package is the Laravel adapter. It provides Artisan commands, a `Searchable` trait for Eloquent models, a `<x-scolta::search />` Blade component, and API endpoints.

## Quick Install

```bash
# 1. Install
composer require tag1/scolta-laravel tag1/scolta-php

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

With an API key configured, search queries are automatically expanded with related terms, results include an AI summary, and users can ask follow-up questions.

## Verify It Works

```bash
php artisan scolta:check-setup
```

This verifies PHP version, index directories, indexer selection, AI provider configuration, and binary availability.

```bash
php artisan scolta:status
```

The health endpoint also reports current state: `GET /api/scolta/v1/health`

## Configuration

All settings live in `config/scolta.php` with `.env` overrides. After editing `config/scolta.php`, run `php artisan config:clear`.

### AI Provider

| Setting | `.env` key | `config/scolta.php` key | Default | Description |
| ------- | ---------- | ----------------------- | ------- | ----------- |
| Provider | `SCOLTA_AI_PROVIDER` | `ai_provider` | `anthropic` | `anthropic` or `openai` |
| API key | `SCOLTA_API_KEY` | `ai_api_key` | — | Authentication for AI features |
| Model | `SCOLTA_AI_MODEL` | `ai_model` | `claude-sonnet-4-5-20250929` | LLM model identifier |
| Base URL | `SCOLTA_AI_BASE_URL` | `ai_base_url` | provider default | Custom endpoint for proxies or Azure OpenAI |
| Query expansion | `SCOLTA_AI_EXPAND` | `ai_expand_query` | `true` | Toggle AI query expansion on/off |
| Summarization | `SCOLTA_AI_SUMMARIZE` | `ai_summarize` | `true` | Toggle AI result summarization on/off |
| Summary top N | — | `ai_summary_top_n` | `5` | How many top results to send to AI for summarization |
| Summary max chars | — | `ai_summary_max_chars` | `2000` | Max content characters sent to AI per request |
| Max follow-ups | `SCOLTA_MAX_FOLLOWUPS` | `max_follow_ups` | `3` | Follow-up questions allowed per session |
| AI languages | `SCOLTA_AI_LANGUAGES` | `ai_languages` | `['en']` | Languages the AI responds in (matches user query language) |

In `.env`:

```env
SCOLTA_AI_PROVIDER=anthropic
SCOLTA_API_KEY=sk-ant-...
SCOLTA_AI_MODEL=claude-sonnet-4-5-20250929
SCOLTA_AI_EXPAND=true
SCOLTA_AI_SUMMARIZE=true
```

For multilingual sites:

```php
// config/scolta.php
'ai_languages' => ['en', 'fr', 'de'],
```

### Search Scoring

Scoring settings live under the `scoring` key in `config/scolta.php`.

| Setting | `.env` key | `config/scolta.php` path | Default | Description |
| ------- | ---------- | ------------------------ | ------- | ----------- |
| Title match boost | — | `scoring.title_match_boost` | `1.0` | Boost when query terms appear in the title |
| Title all-terms multiplier | — | `scoring.title_all_terms_multiplier` | `1.5` | Extra multiplier when ALL terms match the title |
| Content match boost | — | `scoring.content_match_boost` | `0.4` | Boost for query term matches in body/excerpt |
| Expand primary weight | — | `scoring.expand_primary_weight` | `0.7` | Weight for original query results vs AI-expanded results |
| Recency strategy | `SCOLTA_RECENCY_STRATEGY` | `scoring.recency_strategy` | `exponential` | `exponential`, `linear`, `step`, `none`, or `custom` |
| Recency boost max | — | `scoring.recency_boost_max` | `0.5` | Maximum positive boost for very recent content |
| Recency half-life days | — | `scoring.recency_half_life_days` | `365` | Days until recency boost halves |
| Recency penalty after days | — | `scoring.recency_penalty_after_days` | `1825` | Age before content gets a penalty (~5 years) |
| Recency max penalty | — | `scoring.recency_max_penalty` | `0.3` | Maximum negative penalty for very old content |
| Language | `SCOLTA_LANGUAGE` | `scoring.language` | `en` | ISO 639-1 code for stop word filtering |
| Custom stop words | — | `scoring.custom_stop_words` | `[]` | Extra stop words beyond the language's built-in list |

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

### Display

Display settings live under the `display` key in `config/scolta.php`.

| Setting | `config/scolta.php` path | Default | Description |
| ------- | ------------------------ | ------- | ----------- |
| Excerpt length | `display.excerpt_length` | `300` | Characters shown in result excerpts |
| Results per page | `display.results_per_page` | `10` | Results shown per page |
| Max Pagefind results | `display.max_pagefind_results` | `50` | Total results fetched from index before scoring |

### Site Identity

| Setting | `.env` key | `config/scolta.php` key | Default | Description |
| ------- | ---------- | ----------------------- | ------- | ----------- |
| Site name | `SCOLTA_SITE_NAME` | `site_name` | app name | Included in AI prompts so the AI knows what site it's searching |
| Site description | — | `site_description` | `website` | Brief description for AI context |

The AI uses your site name and description to give contextually relevant answers. A search on "pricing" will produce very different AI summaries on a SaaS product site vs. a news outlet.

### Custom Prompts

Override prompts in `config/scolta.php` under the `prompts` key, or use an event listener:

```php
// app/Listeners/EnrichScoltaPrompt.php
use Tag1\ScoltaLaravel\Events\PromptEnrichEvent;

class EnrichScoltaPrompt
{
    public function handle(PromptEnrichEvent $event): void
    {
        if ($event->promptName === 'summarize') {
            $event->resolvedPrompt .= "\n\nAlways mention our 30-day return policy.";
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

See [ENRICHMENT.md](../../packages/scolta-php/docs/ENRICHMENT.md) for advanced use cases (vertical examples, multi-tenant, compliance).

## Debugging

### "Pagefind binary not found"

On managed hosting where `exec()` is disabled, the package falls back to the PHP indexer automatically:

```bash
php artisan scolta:check-setup
php artisan scolta:status
```

To install the binary on a host that supports it:

```bash
php artisan scolta:download-pagefind
```

### "AI features not working"

1. Verify API key: `php artisan scolta:check-setup`
2. Clear stale cache: `php artisan scolta:clear-cache`
3. Clear config cache: `php artisan config:clear`
4. Confirm the model name in `config/scolta.php`

### "AI summary says 'I don't have enough context'"

Increase how much content is sent to the AI:

```php
// config/scolta.php
'ai_summary_top_n'     => 10,
'ai_summary_max_chars' => 4000,
```

### "AI responses are in the wrong language"

Set `ai_languages` to match your site's language(s):

```php
'ai_languages' => ['de'],  // or ['en', 'fr', 'de'] for multilingual
```

### "Expanded queries return irrelevant results"

Lower `expand_primary_weight` to give more weight to the original query, or disable expansion:

```php
// config/scolta.php
'scoring' => [
    'expand_primary_weight' => 0.9,  // closer to 1.0 = original query dominates
],
// or: 'ai_expand_query' => false,
```

### "AI features are slow"

Check which model is configured — smaller models respond faster. Verify `cache_ttl` is not too low (default 30 days means expansions are cached for 30 days once computed):

```env
SCOLTA_CACHE_TTL=2592000
```

### "No search results"

1. Check index status: `php artisan scolta:status`
2. Run a full rebuild: `php artisan scolta:build`
3. Verify published assets: `php artisan vendor:publish --tag=scolta-assets --force`
4. Confirm the Pagefind output directory is web-accessible (must be under `public/`)

### "Models not being indexed"

Run `php artisan scolta:discover` to find `Searchable` models not registered in `config/scolta.php`. The observer only tracks models listed there.

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
            id: "article-{$this->id}",
            title: $this->title,
            bodyHtml: $this->body,
            url: "/articles/{$this->slug}",
            date: $this->updated_at->format('Y-m-d'),
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
php artisan scolta:build                    # Full build: mark all content, export, run indexer
php artisan scolta:build --incremental      # Only process tracked changes
php artisan scolta:build --skip-pagefind    # Export HTML without rebuilding index
php artisan scolta:export                   # Export content to HTML only
php artisan scolta:export --incremental     # Only export tracked changes
php artisan scolta:rebuild-index            # Rebuild index from existing HTML files
php artisan scolta:status                   # Show tracker, content, index, and AI status
php artisan scolta:discover                 # Find Searchable models not yet in config
php artisan scolta:clear-cache              # Clear Scolta AI response caches
php artisan scolta:download-pagefind        # Download Pagefind binary for your platform
php artisan scolta:check-setup              # Verify PHP, indexer, and configuration
```

## API Endpoints

| Method | Path | Middleware | Description |
| ------ | ---- | ---------- | ----------- |
| POST | `/api/scolta/v1/expand-query` | api, throttle:scolta | Expand a search query |
| POST | `/api/scolta/v1/summarize` | api, throttle:scolta | Summarize search results |
| POST | `/api/scolta/v1/followup` | api, throttle:scolta | Continue a conversation |
| GET | `/api/scolta/v1/health` | api | Health check |

Route prefix and middleware are configurable via `route_prefix` and `middleware` in `config/scolta.php`.

## Searchable Trait API

| Method | Default | Description |
| ------ | ------- | ----------- |
| `toSearchableContent()` | column heuristic | Return a `ContentItem` for indexing |
| `scopeSearchable($query)` | all records | Filter which records to index |
| `getSearchableType()` | class name | Content type identifier for tracking |
| `shouldBeSearchable()` | `true` | Whether this instance should be indexed |

## Optional Upgrades

### Upgrade to the Pagefind binary indexer

The package auto-selects the PHP indexer on managed hosts. On hosts that support binaries, the Pagefind binary is 5–10× faster:

```bash
php artisan scolta:download-pagefind
# or:
npm install -g pagefind
```

Set `SCOLTA_INDEXER=binary` in `.env` and rebuild. See [scolta-php README](../scolta-php/README.md) for a full indexer comparison table.

### Migrate from Laravel Scout

Scolta and Scout solve different problems. Scout drives external search servers (Algolia, Meilisearch, Typesense). Scolta produces a static, browser-side index — no search server required.

Replace `toSearchArray()` with `toSearchableContent()` and `scopeSearch()` with `scopeSearchable()`. Remove Scout from `composer.json`, publish Scolta's config and migrations, and replace Scout's search calls with `<x-scolta::search />`.

What you gain: no Algolia/Meilisearch bill, AI query expansion and summarization, works on shared/managed hosting. What you give up: Scout's per-record real-time index updates and driver flexibility.

## Requirements

- Laravel 11 or 12
- PHP 8.1+

The Pagefind binary is optional — the PHP indexer works without it.

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

## License

MIT
