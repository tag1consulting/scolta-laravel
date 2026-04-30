# Scolta for Laravel

[![CI](https://github.com/tag1consulting/scolta-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/tag1consulting/scolta-laravel/actions/workflows/ci.yml)

Laravel 11/12 package — Artisan commands, `Searchable` trait for Eloquent models, and AI-powered search built on Pagefind.

## Status

Scolta is in active production use on Laravel 11 and 12. The API documented here is stable within the 0.x minor series — no changes without a deprecation notice and a replacement in place. Some capabilities are still maturing toward a 1.0 release; test in staging when upgrading between minor versions. File bugs at the repo issue tracker.

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
| Provider | `SCOLTA_AI_PROVIDER` | `ai_provider` | `anthropic` | `anthropic` or `openai` |
| API key | `SCOLTA_API_KEY` | `ai_api_key` | — | Authentication for AI features |
| Model | `SCOLTA_AI_MODEL` | `ai_model` | `claude-sonnet-4-5-20250929` | LLM model identifier |
| Base URL | `SCOLTA_AI_BASE_URL` | `ai_base_url` | provider default | Custom endpoint for proxies or Azure OpenAI |
| Query expansion | `SCOLTA_AI_EXPAND` | `ai_expand_query` | `true` | Toggle AI query expansion on/off |
| Summarization | `SCOLTA_AI_SUMMARIZE` | `ai_summarize` | `true` | Toggle AI result summarization on/off |
| Summary top N | — | `ai_summary_top_n` | `10` | How many top results to send to AI for summarization |
| Summary max chars | — | `ai_summary_max_chars` | `4000` | Max content characters sent to AI per request |
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
| Expand primary weight | — | `scoring.expand_primary_weight` | `0.5` | Weight for original query results vs AI-expanded results (higher = original query dominates; raise to 0.7+ if you want literal keyword matches to win) |
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

**Recipe catalog** (no recency, title precision matters):

```php
'scoring' => [
    'recency_strategy'           => 'none',
    'title_match_boost'          => 1.5,
    'title_all_terms_multiplier' => 2.0,
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
php artisan scolta:build                    # Full build: mark all content, export, run indexer
php artisan scolta:build --incremental      # Only process tracked changes
php artisan scolta:build --skip-pagefind    # Export HTML without rebuilding index
php artisan scolta:build --memory-budget=balanced  # Use balanced memory profile
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

## Credits

Scolta is built on [Pagefind](https://pagefind.app/) by [CloudCannon](https://cloudcannon.com/). Without Pagefind, Scolta has no search to score — the index format, WASM search engine, word-position data, and excerpt generation are all Pagefind's. Scolta's contribution is the layer that sits on top: configurable scoring, multi-adapter ranking parity, AI features, and platform glue.

## License

MIT

## Related Packages

- [scolta-core](https://github.com/tag1consulting/scolta-core) — Rust/WASM scoring, ranking, and AI layer that runs in the browser.
- [scolta-php](https://github.com/tag1consulting/scolta-php) — PHP library that indexes content into Pagefind-compatible indexes, plus the shared orchestration and AI client.
- [scolta-drupal](https://github.com/tag1consulting/scolta-drupal) — Drupal 10/11 Search API backend with Drush commands, admin settings form, and a search block.
- [scolta-wp](https://github.com/tag1consulting/scolta-wp) — WordPress 6.x plugin with WP-CLI commands, Settings API page, and a `[scolta_search]` shortcode.
