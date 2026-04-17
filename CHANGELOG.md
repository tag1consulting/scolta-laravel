# Changelog

All notable changes to scolta-laravel will be documented in this file.

This project uses [Semantic Versioning](https://semver.org/). Major versions are synchronized across all Scolta packages.

## [0.2.3] - 2026-04-17

### Changed
- Inherits all scolta-php 0.2.3 fixes and features (filter sidebar, N-set merge, AI context, PII sanitization, priority pages)

## [0.2.2] - 2026-04-16

### Added

- **`scoring.language`** (default `'en'`): ISO 639-1 language code for stop word filtering. Readable from `SCOLTA_LANGUAGE` env var.
- **`scoring.custom_stop_words`** (default `[]`): Comma-separated extra stop words via `SCOLTA_CUSTOM_STOP_WORDS` env var.
- **`scoring.recency_strategy`** (default `'exponential'`): Recency decay function. Readable from `SCOLTA_RECENCY_STRATEGY` env var.
- **`scoring.recency_curve`** (default `[]`): JSON `[[days,boost],…]` control points for the `custom` strategy. Readable from `SCOLTA_RECENCY_CURVE` env var.

## [0.2.1] - 2026-04-15

### Fixed

- **Security:** Replace `Cache::has()` + `Cache::put()` rebuild debounce with atomic `Cache::add()` in `ScoltaObserver` to eliminate TOCTOU race that could trigger duplicate concurrent rebuilds
- **Correctness:** `Searchable::toSearchableContent()` now uses `strip_tags()` instead of `e()` to remove HTML tags from body content; HTML entities in body text are no longer double-encoded
- **UX:** Pagefind fallback notice corrected — "14 languages (Snowball)" instead of "English-only"; binary install command updated

## [0.2.0] - 2026-04-13

### Fixed

- **UX:** `scolta:build` now emits a `warn`-level CLI message and an `info`-level log entry via Laravel's `Log` facade when the Pagefind binary is not found and the PHP indexer fallback is used, including the install command.

### Added

- First-run auto-build detection in `ScoltaServiceProvider::boot()` — dispatches `TriggerRebuild` job when no index exists and `auto_rebuild` is enabled
- `GET /build-progress` endpoint — returns current build status from cache (requires `auth:sanctum`)
- `POST /rebuild-now` endpoint — dispatches a rebuild with optional `force` parameter, protected by cache lock to prevent concurrent builds (requires `auth:sanctum`)
- `$force` constructor parameter on `TriggerRebuild` job — bypasses fingerprint check when true
- Index-missing validation in Blade search component — shows admin-only warning with build instructions when search index has not been built yet
- Queue/job integration for asynchronous index building via Laravel's queue system
- `ProcessIndexChunk` job — processes a single chunk of content through PhpIndexer as a queue job
- `FinalizeIndex` job — merges partial indexes and writes final Pagefind format after all chunks complete
- `TriggerRebuild` job — gathers content from models and dispatches chunk/finalize chain, used for auto-rebuild
- `--sync` flag on `scolta:build` command — runs synchronously (previous behavior); default now dispatches to queue when using PHP indexer
- Auto-rebuild support: `ScoltaObserver` dispatches debounced `TriggerRebuild` jobs on content changes when `auto_rebuild` config is enabled
- `auto_rebuild` config key (`SCOLTA_AUTO_REBUILD` env var, default `false`) — enables automatic queue-based rebuild on content changes
- `auto_rebuild_delay` config key (`SCOLTA_AUTO_REBUILD_DELAY` env var, default `300`) — debounce delay in seconds for auto-rebuild
- PHP indexer integration in `scolta:build` command — pure-PHP alternative to the Pagefind binary pipeline
- `--indexer` option on `scolta:build` to choose backend (`php`, `binary`, or `auto`), overriding config
- `--force` option on `scolta:build` to skip fingerprint check and force a full rebuild
- `indexer` config key (`SCOLTA_INDEXER` env var) with `auto` default: prefers binary, falls back to PHP
- CLI routing logic: when indexer is `php` (or `auto` without binary), gathers content directly from Eloquent models via `PhpIndexer`
- Content gathering from configured models with progress bar output during PHP indexer builds
- `ai_languages` config setting for multilingual AI response support, configurable via `SCOLTA_AI_LANGUAGES` env var (comma-separated)
- All AI controllers now pass `aiLanguages` from config to `AiEndpointHandler`
- `PromptEnrichEvent` Laravel event dispatched before AI prompts are sent to the LLM provider
- `EventDrivenEnricher` bridging scolta-php's `PromptEnricherInterface` with Laravel's event system
- All AI controllers now inject the event dispatcher and pass the enricher to `AiEndpointHandler`

### Removed

- Removed `ffi` PHP extension requirement from CI workflow
- Removed Extism/FFI dependency — scolta-php now uses pure PHP for all operations
- Removed server-side WASM asset publishing (`scolta_core.js`, `scolta_core_bg.wasm`, `scolta_core.d.ts`) from `scolta-assets` publishable group

### Changed

- Scoring now runs client-side via WASM in the browser; server-side WASM scoring methods (`ScoltaWasm::scoreResults`, `mergeResults`, `parseExpansion`) are no longer called
- Default prompts are now resolved via pure PHP (`DefaultPrompts::resolve()`), no longer requiring the WASM runtime
- `scolta:check-setup` command docblock updated to remove FFI/Extism/server WASM references
- CI lint step no longer uses `continue-on-error`

### Previously added

- 7 Artisan commands: `scolta:build`, `scolta:export`, `scolta:rebuild-index`, `scolta:status`, `scolta:clear-cache`, `scolta:download-pagefind`, `scolta:check-setup`
- `Searchable` trait for Eloquent models with `toSearchableContent()`, `scopeSearchable()`, `getSearchableType()`, and `shouldBeSearchable()` methods
- Model observer (`ScoltaObserver`) for automatic content change tracking on create, update, delete, and restore
- Blade component `<x-scolta::search />` for embedding the search UI
- 4 API endpoints: `expand-query`, `summarize`, `followup`, `health` at `/api/scolta/v1/` with configurable middleware
- `LaravelCacheDriver` implementing `CacheDriverInterface` for Laravel's Cache facade
- Auto-discovered service provider (`ScoltaServiceProvider`)
- Publishable config (`config/scolta.php`), migrations (`scolta_tracker` table), and assets
- Eloquent-based content source with model registration in config
- Rate limiting via Laravel's throttle middleware
- Environment variable overrides for all key settings (`SCOLTA_*`)
