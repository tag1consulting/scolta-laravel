# Changelog

All notable changes to scolta-laravel will be documented in this file.

This project uses [Semantic Versioning](https://semver.org/). Major versions are synchronized across all Scolta packages.

## [Unreleased]

### Fixed
- **`TriggerRebuild` no longer passes `$fingerprint` to `FinalizeIndex`.** The `$fingerprint` parameter was removed from `FinalizeIndex`'s constructor in the 0.3.0 rewrite, but `TriggerRebuild` was not updated. The md5 hash was silently flowing into the `$memoryBudget` slot and causing auto-rebuild jobs to always use the conservative memory budget, ignoring any explicitly configured budget.
- **Hygiene:** Replaced `md5(serialize($items))` with `md5(json_encode($items, JSON_THROW_ON_ERROR))` in `TriggerRebuild` for content fingerprinting — `json_encode` is faster, produces deterministic output across PHP versions, and avoids `serialize` baggage.
- **Hygiene:** Added `=== false` error check to `file_put_contents` in `DownloadPagefindCommand` — failed `.env` writes now report an error instead of silently continuing.
- **Hygiene:** Added TOCTOU-safe comments to intentional `@unlink` calls in `CleanupCommand` and `DownloadPagefindCommand`.
- **Hygiene:** Added source-parse tests preventing reintroduction of bare `serialize()` and unchecked `file_put_contents`.

### Added
- **`LaravelCacheDriver` behavior tests.** New `ScoltaCacheBehaviorTest`: verifies the driver contract (get/set/miss/array values) and end-to-end handler+driver caching — second call to `handleExpandQuery`/`handleSummarize` serves from the in-memory Cache facade (AI called once), while `cacheTtl=0` calls the AI service both times. Uses `Cache::swap(new Repository(new ArrayStore))` so no real cache store is needed.
- **New `ScoltaConfigIntegrationTest`.** Verifies the full pipeline: Laravel config → `ScoltaAiService::flattenConfig()` → `ScoltaConfig::fromArray()` → `toJsScoringConfig()` / `toAiClientConfig()`. Covers all 8 scoring fields, language, recency_strategy, all 5 display fields, feature toggles, AI client config (provider/model/base_url/omission), ai_languages, custom_stop_words, phrase proximity (defaults + overrides), cache_ttl (including 0), and all three prompt overrides.
- **AI configuration tests (Phase 5).** Added `test_ai_feature_toggle_defaults` asserting that `ai_expand_query`, `ai_summarize`, and `max_follow_ups` have the correct default values (true, true, 3).
- **Scoring behavior tests (Phase 1).** Extended `test_scoring_section` to assert `language`, `recency_strategy`, and `recency_curve` keys are present in the scoring config. Extended `test_scoring_defaults` to assert their default values. Added `test_ai_languages_default` confirming `ai_languages` is an array containing `'en'`.

## [0.3.3] - 2026-04-26

### Fixed
- **`$budgetProfile` undefined variable in `dispatchToQueue()`**: `FinalizeIndex` was receiving `null` as its `$memoryBudget` argument. Now passes `$budget->profile()`.

### Changed
- **`buildWithPhpIndexer()` and `dispatchToQueue()`**: Budget and chunk-size resolution now delegated to `MemoryBudgetConfig::fromCliAndConfig()` (scolta-php), removing ~8 lines of duplicated precedence logic from each method.
- **`buildWithPhpIndexer()` intent construction**: Replaced inline `match(true)` with `BuildIntentFactory::fromFlags()` (scolta-php).
- **`ExpandQueryController`, `SummarizeController`, `FollowUpController`**: Now use `AiControllerTrait` (scolta-php) for `AiEndpointHandler` construction. `Dispatcher` moved from method injection to constructor injection so `resolveEnricher()` can access it.
- **`ArtisanProgressReporter::advance()`**: Now calls `setMessage($detail)` on the Symfony ProgressBar when a detail string is provided.
- **Anti-pattern CI check.** New `antipatterns` CI job asserts `orchestrator->build()` is always called with a logger argument.
- **scolta-php dependency bumped to `^0.3.3`** (atomic manifest writes, CRC32 chunk validation, stale lock detection).

## [0.3.2] - 2026-04-24

Coordinated release. Ports the streaming gather and CLI wiring pattern from scolta-wp to Laravel.

### Fixed
- **Peak RAM on large deployments**: `BuildCommand::streamContentItems()` replaces `Model::all()` with `Model::cursor()` (PDO cursor, one row hydrated at a time) so the full model dataset is never resident in RAM. The sync PHP indexer path passes this generator to `ContentExporter::filterItems()` and then to `IndexBuildOrchestrator::build()`. (#6)
- **`reporter:` named argument crash**: `buildWithPhpIndexer()` called `$orchestrator->build($intent, $items, reporter: $reporter)` but the parameter is named `$progress`, not `$reporter`. PHP 8.1+ raises `TypeError: Unknown named argument $reporter`. Fixed to use positional arguments. (#6)
- **Silent CLI during large builds**: `buildWithPhpIndexer()` was passing the progress reporter but not a PSR-3 logger to `build()`. Now also passes Laravel's PSR-3 logger so memory telemetry and phase markers are visible in `php artisan scolta:build` output. (#6)
- **Lint**: Fixed `concat_space`, `unary_operator_spaces`, `not_operator_with_successor_space`, `blank_line_before_statement`, unused import, and import ordering violations in `MemoryBudgetCommand.php` and `ScoltaServiceProvider.php`. (#5)

### Added
- **Flexible memory budget and chunk size**: `artisan scolta:build` now accepts `--memory-budget=<budget>` with profile names *or* raw byte values (`256M`, `1G`), and a new `--chunk-size=<n>` flag for pages-per-chunk independent of the memory profile. Both are also configurable via `scolta.memory_budget.chunk_size` in `config/scolta.php` and the `SCOLTA_CHUNK_SIZE` env var. `ProcessIndexChunk` queue job accepts the `$chunkSize` parameter and passes it to `MemoryBudget::fromOptions()`.
- **`BuildCommand::gatherItemCount(): int`**: Uses `Model::count()` (one `SELECT COUNT` per model class) to get the total without loading any model fields. (#6)
- **`BuildCommand::streamContentItems(): \Generator`**: Uses `Model::cursor()` (PDO cursor, one row hydrated at a time) instead of `Model::all()` so the full model dataset is never resident in RAM. (#6)

### Changed
- CI now pulls scolta-php at `@dev`.

## [0.3.1] - 2026-04-23

### Fixed
- **Release packaging**: Release workflow now triggers on both `v0.x.x` and bare `0.x.x` tag formats, fixing the 0.3.0 release that shipped with no binary assets.

### Added
- **Zip structure regression test**: New `validate-zip` CI job asserts `scolta-laravel/vendor/autoload.php` and `scolta-laravel/src/ScoltaServiceProvider.php` are present in each release archive.
- **`memory_budget` config section**: `config/scolta.php` now includes a `memory_budget.profile` key (env `SCOLTA_MEMORY_BUDGET`, default `conservative`). `artisan scolta:build` reads this as the default for `--memory-budget` instead of always using `'conservative'`.
- **`artisan scolta:memory-budget` command**: Interactive command to view the current memory budget setting, the detected PHP `memory_limit`, and the advisory suggestion. Use `--set=<profile>` to display instructions for updating `.env`.

## [0.3.0] - 2026-04-23

### Added
- **`--memory-budget` flag**: Pass `conservative` (default), `balanced`, or `aggressive` to `scolta:build` to control peak RSS vs. throughput trade-off.
- **`--resume` flag**: Resume a previously interrupted PHP index build from the last committed chunk.
- **`--restart` flag**: Discard interrupted state and force a clean rebuild.
- **`ArtisanProgressReporter`**: Routes `IndexBuildOrchestrator` progress callbacks to Laravel's native Artisan progress bar.

### Changed
- **`BuildCommand::buildWithPhpIndexer()`** rewritten to use `IndexBuildOrchestrator::build()` — 85 lines down to ~30.
- **`ProcessIndexChunk`**: Now uses `BuildCoordinator::commitChunk()` directly; `tries = 1` to prevent duplicate chunks from retries.
- **`FinalizeIndex`**: Now uses `IndexBuildOrchestrator::finalize()`; `tries = 1`; `$fingerprint` parameter removed (fingerprint management moved to orchestrator layer).
- Inherits all scolta-php 0.3.0 improvements: `MemoryBudget`, `BuildIntent`, `BuildCoordinator`, streaming pipeline, OOM fix.

### Fixed
- **Chunk size corrected**: `BuildCommand` now uses chunk size 100 (was 50), aligning with the WP and Drupal adapters and reducing the number of partial files written per build.

## [0.2.4] - 2026-04-21

### Changed
- Inherits all scolta-php 0.2.4 fixes and features (phrase-proximity scoring, WASM config key fix, quoted-phrase forced-mode, second WASM rebuild)

### Fixed
- **`<x-scolta::search />` rendered empty after index build**: `search.blade.php` was checking `$outputDir/pagefind/pagefind-entry.json` but Pagefind writes `pagefind-entry.json` directly to `$outputDir` (no `/pagefind/` subdirectory). The component always fell through to the "index not built" warning even on sites with a valid index.

### Added
- **Route smoke test suite** (`tests/Http/RouteSmokeTest.php`): Twenty test methods covering all six named Scolta API routes (`scolta.expand`, `scolta.summarize`, `scolta.followup`, `scolta.health`, `scolta.build-progress`, `scolta.rebuild-now`). Asserts correct HTTP methods, controller class references, and middleware guards — in particular that `build-progress` and `rebuild-now` are behind `auth:sanctum` and that the AI endpoints are not. Uses plain PHPUnit source-text analysis (no Laravel kernel boot) so it runs in the same fast unit-test suite as all other Laravel tests.

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
