# Scolta for Laravel

Laravel package providing AI-powered search with Pagefind. Delivers client-side search with optional AI query expansion, summarization, and follow-up conversations.

## How It Works

1. **Indexing** -- The `php artisan scolta:build` command exports Eloquent models as HTML files with Pagefind data attributes, then runs the Pagefind CLI to build a static search index.
2. **Search** -- Entirely client-side. The browser loads `pagefind.js`, searches the static index, and scolta.js handles scoring, filtering, and result rendering.
3. **AI features** -- Optional. When an API key is configured, the package provides API endpoints for query expansion, result summarization, and follow-up conversations powered by Anthropic or OpenAI.

## Requirements

- Laravel 11 or 12
- PHP 8.1+
- [Extism](https://extism.org) shared library (for WASM scoring)
- PHP FFI enabled (`ffi.enable=true`)
- Pagefind CLI (`npm install -g pagefind`)

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

### Install Extism (if not already present)

```bash
curl -s https://get.extism.org/cli | bash -s -- -y
sudo extism lib install --version latest
sudo ldconfig  # Linux only
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

```
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

This verifies PHP version, FFI extension, Extism library, WASM binary, Pagefind binary, AI provider configuration, and cache backend. Fix any items marked as failed before proceeding.

## Configuration

All settings in `config/scolta.php` with `.env` overrides:

| Key | Env Var | Default | Description |
|-----|---------|---------|-------------|
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
|--------|------|------------|-------------|
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
php artisan scolta:clear-cache              # Clear Scolta AI response caches
php artisan scolta:download-pagefind        # Download Pagefind binary for your platform
php artisan scolta:check-setup              # Verify PHP, Extism, Pagefind, and configuration
```

## Content Tracking

The package uses an Eloquent observer attached to your registered models. Changes are automatically tracked:

- **Created/updated** -- Marked for indexing (if `shouldBeSearchable()` returns true)
- **Deleted/force-deleted** -- Marked for removal
- **Restored** (soft deletes) -- Marked for re-indexing

The tracker table (`scolta_tracker`) is cleaned after each successful build.

## Searchable Trait API

| Method | Default | Description |
|--------|---------|-------------|
| `toSearchableContent()` | *abstract* | Return a `ContentItem` for indexing |
| `scopeSearchable($query)` | all records | Filter which records to index |
| `getSearchableType()` | class name | Content type identifier for tracking |
| `shouldBeSearchable()` | `true` | Whether this instance should be indexed |

## Package Structure

```
src/
  ScoltaServiceProvider.php              # Service provider (auto-discovered)
  Searchable.php                         # Trait for Eloquent models
  Commands/BuildCommand.php              # artisan scolta:build
  Commands/StatusCommand.php             # artisan scolta:status
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

**Unit tests** (fast, no Laravel required — 314 tests):

```bash
cd packages/scolta-laravel
./vendor/bin/phpunit
```

**Integration tests** (requires DDEV — 38 tests):

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

## Troubleshooting

### "FFI not enabled" or WASM load failure

```bash
php -r "echo extension_loaded('ffi') ? 'OK' : 'MISSING';"
php -r "echo class_exists('\Extism\Plugin') ? 'OK' : 'MISSING';"
```

Install Extism if missing:

```bash
curl -s https://get.extism.org/cli | bash -s -- -y
sudo extism lib install --version latest
sudo ldconfig  # Linux only
```

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
