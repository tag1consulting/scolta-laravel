# Changelog

All notable changes to scolta-laravel will be documented in this file.

This project uses [Semantic Versioning](https://semver.org/). Major versions are synchronized across all Scolta packages.

## [Unreleased] (0.2.0-dev)

### Added

- `PromptEnrichEvent` Laravel event dispatched before AI prompts are sent to the LLM provider
- `EventDrivenEnricher` bridging scolta-php's `PromptEnricherInterface` with Laravel's event system
- All AI controllers now inject the event dispatcher and pass the enricher to `AiEndpointHandler`

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
