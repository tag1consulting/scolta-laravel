# Scolta for Laravel

[![CI](https://github.com/tag1consulting/scolta-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/tag1consulting/scolta-laravel/actions/workflows/ci.yml)

Scolta is a browser-side search engine: the index lives in static files, scoring runs in the browser via WebAssembly, and an optional AI layer handles query expansion and summarization. No search server required. "Scolta" is Italian for "lookout" — someone watching for what matters.

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
```

To enable AI features (query expansion, summarization, follow-up), add to `.env`:

```env
SCOLTA_API_KEY=sk-ant-...
```

Then configure AI provider, model, and other options in `config/scolta.php`.

## Verify It Works

```bash
php artisan scolta:check-setup
```

This verifies PHP version, index directories, indexer selection, AI provider configuration, and binary availability.

Check current index status:

```bash
php artisan scolta:status
```

The health endpoint also reports current state:

```text
GET /api/scolta/v1/health
```

## Optional Upgrades

### Add the Searchable trait to a model

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

### Upgrade to the Pagefind binary indexer

The package auto-selects the PHP indexer on managed hosts where `exec()` is disabled. On hosts that support binaries, the Pagefind binary is 5–10× faster:

```bash
php artisan scolta:download-pagefind
# or:
npm install -g pagefind
```

Then set `SCOLTA_INDEXER=binary` in `.env` and rebuild:

```bash
php artisan scolta:build
```

See [scolta-php README](../scolta-php/README.md) for a full indexer comparison table.

### Migrate from Laravel Scout

Scolta and Scout solve different problems. Scout is a driver-based full-text search adapter (Algolia, Meilisearch, Typesense, database). Scolta produces a static, browser-side index — no search server required.

Replace `toSearchArray()` with `toSearchableContent()` and `scopeSearch()` with `scopeSearchable()`. Then remove Scout from `composer.json`, publish Scolta's config and migrations, and replace Scout's search calls with `<x-scolta::search />`.

What you gain: no Algolia/Meilisearch bill, AI query expansion and summarization, works on shared/managed hosting. What you give up: Scout's per-record real-time index updates and driver flexibility.

## Debugging

### "Pagefind binary not found"

On managed hosting where `exec()` is disabled, the package falls back to the PHP indexer automatically. To confirm:

```bash
php artisan scolta:check-setup
php artisan scolta:status
```

If you want the binary on a host that supports it:

```bash
php artisan scolta:download-pagefind
```

### "AI features not working"

1. Verify API key: `php artisan scolta:check-setup`
2. Clear stale cache: `php artisan scolta:clear-cache`
3. Clear config cache: `php artisan config:clear`
4. Confirm the model name is current in `config/scolta.php`

### "No search results"

1. Check index status: `php artisan scolta:status`
2. Run a full rebuild: `php artisan scolta:build`
3. Verify published assets: `php artisan vendor:publish --tag=scolta-assets --force`
4. Confirm the Pagefind output directory is web-accessible (must be under `public/`)

### "Models not being indexed"

Run `php artisan scolta:discover` to find `Searchable` models not registered in config. The observer only tracks models listed in `config/scolta.php`.

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
