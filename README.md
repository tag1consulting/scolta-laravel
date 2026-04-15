# Scolta for Laravel

[![CI](https://github.com/tag1consulting/scolta-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/tag1consulting/scolta-laravel/actions/workflows/ci.yml)

Scolta adds AI-powered search to your Laravel site. Search runs entirely in the browser using Pagefind — no search server needed. Optional AI features handle query expansion, result summarization, and follow-up conversations. Works with any content type, any language.

## Quickstart

```bash
# 1. Install
composer require tag1/scolta-laravel tag1/scolta-php

# 2. Publish config, migrations, and assets
php artisan vendor:publish --tag=scolta-config --tag=scolta-migrations --tag=scolta-assets

# 3. Run migrations
php artisan migrate

# 4. Add the Searchable trait to your models (see Setup section below)

# 5. Verify prerequisites
php artisan scolta:check-setup

# 6. Build the search index
php artisan scolta:build

# 7. Add <x-scolta::search /> to your Blade template.
```

## Configuration

Set the API key to enable AI features in `.env`:

```env
SCOLTA_API_KEY=sk-ant-...
```

Key settings in `config/scolta.php`:

| Key | Env Var | Default | Description |
| --- | ------- | ------- | ----------- |
| `ai_provider` | `SCOLTA_AI_PROVIDER` | `anthropic` | AI provider (anthropic, openai, laravel) |
| `ai_api_key` | `SCOLTA_API_KEY` | | API key for AI features |
| `ai_model` | `SCOLTA_AI_MODEL` | `claude-sonnet-4-5-20250929` | Model identifier |

See [CONFIG_REFERENCE.md](../../docs/CONFIG_REFERENCE.md) for the full list of settings.

## Prompt Enrichment

The built-in expand, summarize, and follow-up prompts can be customized in `config/scolta.php`. You can also set `site_name` and `site_description` to give the AI better context about your content. See [ENRICHMENT.md](../../docs/ENRICHMENT.md) for details on prompt customization.

## How It Works

1. **Indexing** -- The `php artisan scolta:build` command exports Eloquent models as HTML files with Pagefind data attributes, then runs the Pagefind CLI to build a static search index.
2. **Search** -- Entirely client-side. The browser loads `pagefind.js`, searches the static index, and scolta.js handles scoring, filtering, and result rendering.
3. **AI features** -- Optional. When an API key is configured, the package provides API endpoints for query expansion, result summarization, and follow-up conversations powered by Anthropic or OpenAI.

## Architecture

Scolta is a multi-package system. This Laravel package is a platform adapter that sits on top of the shared PHP library:

```text
scolta-laravel (this package)      scolta-php              scolta-core (WASM)
  Artisan commands ──────────> ContentExporter ──────> cleanHtml()
  ScoltaAiService ───────────> AiClient                buildPagefindHtml()
  ScoltaServiceProvider ─────> ScoltaConfig ─────────> toJsScoringConfig()
  Searchable trait ──────────> DefaultPrompts ───────> resolvePrompt()
  ScoltaObserver ────────────> PagefindBinary           scoreResults()
  LaravelCacheDriver ────────> CacheDriverInterface     mergeResults()
```

The Laravel package handles framework-specific concerns: Artisan commands, Eloquent model observation, Blade components, route registration, publishable config/migrations, and middleware. All scoring, HTML processing, and prompt logic lives in the WASM module, accessed through scolta-php. This package never depends on scolta-core directly.

## Requirements

- Laravel 11 or 12
- PHP 8.1+
- Pagefind CLI (`npm install -g pagefind`) — optional, see Indexer section below

## Installation

```bash
composer require tag1/scolta-laravel tag1/scolta-php
```

The service provider is auto-discovered. Then publish the config, migrations, and assets:

```bash
php artisan vendor:publish --tag=scolta-config
php artisan vendor:publish --tag=scolta-migrations
php artisan vendor:publish --tag=scolta-assets
php artisan migrate
```

## Setup

### 1. Add the Searchable Trait

Add the `Searchable` trait to any Eloquent model you want indexed:

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

    // Optional: filter which records to index.
    public function scopeSearchable($query)
    {
        return $query->where('published', true);
    }
}
```

### 2. Register Models in Config

In `config/scolta.php`:

```php
'models' => [
    App\Models\Article::class,
    App\Models\Page::class,
],
```

### 3. Set the API Key (Optional, for AI features)

In `.env`:

```env
SCOLTA_API_KEY=sk-ant-...
```

### 4. Build the Search Index

```bash
php artisan scolta:build
```

### 5. Add the Search Component

In any Blade template:

```blade
<x-scolta::search />
```

## Verify Your Setup

After installation, run the setup check to verify all prerequisites:

```bash
php artisan scolta:check-setup
```

This verifies PHP version, Pagefind binary, AI provider configuration, and cache backend. Fix any items marked as failed before proceeding.

## Configuration Details

All settings in `config/scolta.php` with `.env` overrides:

| Key | Env Var | Default | Description |
| --- | ------- | ------- | ----------- |
| `ai_provider` | `SCOLTA_AI_PROVIDER` | `anthropic` | AI provider (anthropic, openai, laravel) |
| `ai_api_key` | `SCOLTA_API_KEY` | | API key for AI features |
| `ai_model` | `SCOLTA_AI_MODEL` | `claude-sonnet-4-5-20250929` | Model identifier |
| `ai_expand_query` | `SCOLTA_AI_EXPAND` | `true` | Enable AI query expansion |
| `ai_summarize` | `SCOLTA_AI_SUMMARIZE` | `true` | Enable AI summarization |
| `max_follow_ups` | `SCOLTA_MAX_FOLLOWUPS` | `3` | Max follow-up questions per session |
| `site_name` | `SCOLTA_SITE_NAME` | app name | Site name for AI prompts |
| `rate_limit` | `SCOLTA_RATE_LIMIT` | `30` | API requests per minute per IP |
| `cache_ttl` | `SCOLTA_CACHE_TTL` | `2592000` | Cache TTL in seconds (30 days) |

Scoring parameters are nested under `scoring`, display under `display`, and Pagefind paths under `pagefind`. See the published config file for the full list.

## API Endpoints

| Method | Path | Middleware | Description |
| ------ | ---- | ---------- | ----------- |
| POST | `/api/scolta/v1/expand-query` | api, throttle:scolta | Expand a search query |
| POST | `/api/scolta/v1/summarize` | api, throttle:scolta | Summarize search results |
| POST | `/api/scolta/v1/followup` | api, throttle:scolta | Continue a conversation |
| GET | `/api/scolta/v1/health` | api | Health check (for monitoring) |

Route prefix and middleware are configurable via `route_prefix` and `middleware` in `config/scolta.php`.

## Artisan Commands

```bash
php artisan scolta:build                    # Full build: mark, export, run Pagefind
php artisan scolta:build --incremental      # Only process tracked changes
php artisan scolta:build --skip-pagefind    # Export HTML without rebuilding index
php artisan scolta:export                   # Export content to HTML only
php artisan scolta:export --incremental     # Only export tracked changes
php artisan scolta:rebuild-index            # Rebuild Pagefind index from existing HTML
php artisan scolta:status                   # Show tracker, content, index, AI status
php artisan scolta:discover                 # Find Searchable models not yet in config
php artisan scolta:clear-cache              # Clear Scolta AI response caches
php artisan scolta:download-pagefind        # Download Pagefind binary for your platform
php artisan scolta:check-setup              # Verify PHP, Pagefind, and configuration
```

## Content Tracking

The package uses an Eloquent observer attached to your registered models. Changes are automatically tracked:

- **Created/updated** -- Marked for indexing (if `shouldBeSearchable()` returns true)
- **Deleted/force-deleted** -- Marked for removal
- **Restored** (soft deletes) -- Marked for re-indexing

The tracker table (`scolta_tracker`) is cleaned after each successful build.

## Searchable Trait API

| Method | Default | Description |
| ------ | ------- | ----------- |
| `toSearchableContent()` | column heuristic | Return a `ContentItem` for indexing |
| `scopeSearchable($query)` | all records | Filter which records to index |
| `getSearchableType()` | class name | Content type identifier for tracking |
| `shouldBeSearchable()` | `true` | Whether this instance should be indexed |

## Package Structure

```text
src/
  ScoltaServiceProvider.php              # Service provider (auto-discovered)
  Searchable.php                         # Trait for Eloquent models
  Commands/BuildCommand.php              # artisan scolta:build
  Commands/StatusCommand.php             # artisan scolta:status
  Commands/DiscoverCommand.php           # artisan scolta:discover
  Commands/DownloadPagefindCommand.php   # artisan scolta:download-pagefind
  Http/Controllers/ExpandQueryController.php
  Http/Controllers/SummarizeController.php
  Http/Controllers/FollowUpController.php
  Http/Controllers/HealthController.php
  Models/ScoltaTracker.php               # Change tracking model
  Observers/ScoltaObserver.php           # Auto-tracking observer
  Services/ScoltaAiService.php           # AI service wrapper
  Services/ContentSource.php             # Eloquent content source
config/scolta.php                        # Publishable configuration
database/migrations/                     # Tracker table migration
routes/api.php                           # API route definitions
resources/views/components/search.blade.php  # <x-scolta::search /> component
```

## Testing

**Unit tests** (fast, no Laravel required -- 314 tests):

```bash
cd packages/scolta-laravel
./vendor/bin/phpunit
```

**Integration tests** (requires DDEV -- 38 tests):

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

## Indexer

Scolta auto-detects the best available indexer (`indexer: auto` default). See [scolta-php README](../scolta-php/README.md) for the full comparison table.

| Feature | PHP Indexer | Pagefind Binary |
| ------- | ----------- | --------------- |
| Languages with stemming | 15 (Snowball) | 33+ |
| Speed (1 000 pages) | ~3–4 seconds | ~0.3–0.5 seconds |
| Shared / managed hosting | Yes | Only if binary installable |

**To upgrade to the binary indexer:**

```bash
npm install -g pagefind
# or:
php artisan scolta:download-pagefind
```

Verify: `php artisan scolta:check-setup` — the health endpoint also reports `indexer_active`.

## Migrating from Laravel Scout

Scolta and Scout solve different problems. Scout is a driver-based full-text search adapter (Algolia, Meilisearch, Typesense, database). Scolta is a static, browser-side search engine with optional AI features — no search server required.

### Side-by-side comparison

| | Laravel Scout | Scolta |
| - | ------------- | ------ |
| Search runs on | Search server | Browser (WASM) |
| Infrastructure | External service or DB | Static files only |
| AI features | No | Yes (expand, summarize, follow-up) |
| Real-time updates | Yes (observer-driven) | Near-real-time (incremental build) |
| Full-text languages | Depends on driver | 14 (PHP) / 33+ (binary) |

### Migration steps

1. **Keep the `Searchable` trait pattern** — Scolta uses the same trait-on-model convention as Scout.

2. **Replace `toSearchArray()` with `toSearchableContent()`**:

   ```php
   // Before (Scout)
   public function toSearchArray(): array
   {
       return ['title' => $this->title, 'body' => $this->body];
   }

   // After (Scolta)
   public function toSearchableContent(): ContentItem
   {
       return new ContentItem(
           id: "post-{$this->id}",
           title: $this->title,
           bodyHtml: $this->body,
           url: route('posts.show', $this),
           date: $this->updated_at->format('Y-m-d'),
           siteName: config('scolta.site_name', config('app.name')),
       );
   }
   ```

3. **Replace `scopeSearch()` with `scopeSearchable()`** (optional — both filter which records are indexed).

4. **Remove Scout from `composer.json`** and run `composer require tag1/scolta-laravel tag1/scolta-php`.

5. **Publish and migrate**: follow the Installation steps above.

6. **Replace the search UI**: remove Scout's search calls and add `<x-scolta::search />` to your Blade template.

### What you gain

- No Algolia/Meilisearch bill or server to maintain.
- AI query expansion and result summarization out of the box.
- Works on shared/managed hosting (PHP indexer fallback).

### What you give up

- Scout's real-time per-record index updates (Scolta uses a background build step).
- Driver flexibility — Scolta only produces Pagefind-compatible indexes.

## Hosting

See the [Scolta Hosting Guide](../scolta-php/HOSTING.md) for platform-specific
deployment guidance, indexer selection, and ephemeral filesystem handling.

## Troubleshooting

### "Pagefind binary not found"

```bash
php artisan scolta:download-pagefind
# or
npm install -g pagefind
```

### "AI features not working"

1. Verify API key: `php artisan scolta:check-setup`
2. Clear stale cache: `php artisan scolta:clear-cache`
3. Clear config cache: `php artisan config:clear`

### "No search results"

1. Check index status: `php artisan scolta:status`
2. Run a full build: `php artisan scolta:build`
3. Verify published assets: `php artisan vendor:publish --tag=scolta-assets --force`

## License

MIT
