# Changelog

All notable changes to scolta-laravel will be documented in this file.

This project uses [Semantic Versioning](https://semver.org/). Major versions are synchronized across all Scolta packages; minor and patch versions are released independently per package.

## [Unreleased]

### Security
- **Amazee.ai admin settings routes are no longer registered with the default configuration.** `routes/scolta-amazee.php` (settings page, `POST /scolta/amazee/trial`, `DELETE /scolta/amazee/disconnect`, …) was registered behind `config('scolta.amazee_middleware')`, which defaults to `['web']` — no authentication. Any anonymous visitor could wipe the stored AI credentials via `disconnect` or bind a trial to an arbitrary email via `trial`; the README's "protect the route in production" note was the only safeguard. The routes are now registered **only when** `scolta.amazee_middleware` is configured beyond the bare `['web']` group (e.g. `['web', 'auth']`); with the shipped default they do not exist and requests return 404. Added `AmazeeAdminRouteSecurityTest` (booted-kernel feature tests): anonymous trial/disconnect requests 404 by default, a configured guard middleware blocks anonymous access, and a satisfied guard restores access. **⚠️ Upgrade note:** if you use the admin UI at `/scolta/amazee`, set `'amazee_middleware' => ['web', 'auth']` (or your own guard) in `config/scolta.php` — with the previous default your routes were publicly reachable, so adding a guard is required anyway. CLI provisioning (`artisan scolta:amazee:provision`) and first-request auto-provisioning are unaffected.
- **`scolta:download-pagefind` now verifies the tarball against the upstream-published SHA-256 checksum before extracting.** Pagefind publishes a `.sha256` asset next to every release tarball; the command fetches it and fails closed — refusing to install — when the checksum cannot be fetched, is malformed, or does not match. Covered by `DownloadPagefindChecksumTest`.
- **The public follow-up endpoint now caps conversation payload size.** `POST /api/scolta/v1/followup` validated `messages.*.content` with no `max:` while the sibling endpoints cap their inputs (expand 500 chars, summarize 50,000 chars), so an anonymous client could POST arbitrarily large conversation payloads straight into an LLM prompt. Validation now enforces 10,000 chars per message, 50,000 chars combined across the conversation (matching the summarize cap), and at most 25 messages. Added `FollowUpValidationTest` covering all three caps plus the in-cap happy path.

### Fixed
- **`scolta:export` deletions now go through the manifest-aware exporter.** Deletions were performed by manually concatenating `{buildDir}/{id}.html` and `File::delete()`-ing it — wrong (or no) deletion when the export manifest maps non-flat paths (the nested URL layout introduced in 1.0.1), plus an `$id`-in-filesystem-path smell. `ExportCommand` now calls `ContentExporter::deleteById()` exactly like `BuildCommand` always did.
- **`scolta:build --indexer` (and `scolta.indexer` config) now reject unknown backends.** Any typo (e.g. `--indexer=pphp`) silently selected the binary pipeline; the command now errors with the valid choices, matching how `scolta:memory-budget` validates `--set`.
- **`scolta:rebuild-index` no longer interpolates the Pagefind binary path unescaped.** Both `scolta:build` and `scolta:rebuild-index` now share the new `PagefindRunner` service (binary escaped via `escapeshellcmd`, paths via `escapeshellarg`, 300s timeout, recursive HTML count, cache-generation bump). Side effect: `scolta:rebuild-index` now counts exported HTML recursively like `scolta:build`, instead of a flat glob that missed the nested export layout.
- **`ScoltaObserver` auto-rebuild fallback default aligned with config.** `maybeDispatchRebuild()`/`afterBulkUpdate()` used `config('scolta.auto_rebuild', false)` while `config/scolta.php` defaults to `true` — behavior differed when the package config wasn't merged. The fallback is now `true`, and `afterBulkUpdate()` delegates to `maybeDispatchRebuild()` instead of duplicating it line-for-line.
- **`/rebuild-now` lock race fixed and moved to an invokable controller.** The route closure acquired the build lock, released it immediately, then dispatched — two concurrent requests could both dispatch a rebuild. The new `RebuildNowController` (replacing the repo-convention-violating logic-bearing closure) holds the lock through the dispatch and returns 409 while it is held. Covered by `RebuildNowControllerTest`.
- **`scolta:clear-cache` increments the generation counter atomically.** The previous `Cache::get()` + `Cache::put()` pair could lose an increment racing a concurrent build finish; it now uses `Cache::increment()` like every other call site.

### Internal
- **Larastan ratcheted from level 5 to level 6 and `tests/` added to the analysed paths.** The 14-entry baseline is burned down to 6 entries: the `CheckSetupCommand` match expression gained a defensive default arm; `ScoltaTracker::getPending()`'s return PHPDoc no longer claims `static`; the `Searchable` trait is now analysed (used by test fixtures) with typed `scopeSearchable()` generics; the four controller `isset.offset` entries disappeared with the `AiController` extraction; and ~30 missing iterable-type PHPDocs were added across src and tests. The remaining entries are the BuildCommand/TriggerRebuild gathering methods (deleted by the content-gathering-unification PR), a larastan namespaced-view false positive in `AmazeeSettingsController`, and one upstream `AiEndpointHandler` PHPDoc shape gap. Level 7 jumps to 229 errors (mostly framework generics in tests) and is left for a future pass.

### Changed
- **Extracted an abstract `AiController` base for the three AI endpoints.** `ExpandQueryController`/`SummarizeController`/`FollowUpController` duplicated cache-driver resolution, the generation counter, enricher wiring, and the error response shape. The base class owns them; the follow-up controller keeps its no-cache overrides. The shared `scolta_expand_generation` key is intentionally **not** renamed (a rename would orphan deployed counters mid-flight); its definition now documents that it is the shared AI generation counter for both expansion and summaries.
- **Extracted `AssetStatus` service for published-asset checks.** The "are assets published / do they match the package checksum" logic was triplicated across `HealthController`, `scolta:status`, and the provider's publishable registration. All three now use one service. `scolta:build` also stops force-republishing assets on every build when the published files already match the package checksum (force-rewriting bumped the mtime-based `?v=` cache busters for no reason), and uses the imported `Artisan` facade instead of the root `\Artisan` alias.
- **Build auto-resume log moved from the system temp dir to `storage_path('logs/scolta-resume.log')`** so it survives tmp cleaners and lands where Laravel logs are expected.
- **`composer.json` PHP floor raised to `^8.2`.** The previous `>=8.1` was unsatisfiable with `illuminate/* ^11|^12|^13` (Laravel 11 requires PHP ≥ 8.2) and only appeared to work because CI installs with `--ignore-platform-req=php`.
- **Doc accuracy:** `scolta:cleanup` docblock no longer claims dry-run-by-default (deletion is the default; `--dry-run` previews); `scolta:memory-budget` docblock no longer claims `--set` writes `.env` (it prints the instruction); `config/scolta.php` reunites the AI-provider docblock with the `ai_*` keys and moves the stranded `sortable_fields` key next to its own docblock; the default AI model string is defined once as `ScoltaAiService::DEFAULT_MODEL` (previously duplicated in the provider and config, where the Amazee auto-model check would silently rot when the default bumps).
- **`ProgressController` now extends the base controller** like every other controller, and `HealthController` carries the standard `@since`/`@stability` annotations; both `ProgressController` and `RebuildNowController` are enforced by `ControllerStructureTest`.

## [1.0.3] - 2026-06-05

### Fixed
- **Site Type presets now actually take effect ([#82](https://github.com/tag1consulting/scolta-laravel/issues/82)).** The published `config/scolta.php` hard-coded a concrete value for every scoring/display field, and `ScoltaAiService` forwards the full config to `ScoltaConfig::fromArray()` unfiltered, so every key was always present and always overrode the selected preset. The result: the **entire** Site Type preset was inert — not just `expansion_combine_mode`, but ~13 fields (ranking weights, recency, sub-word recall, AI-summary candidate breadth, results-per-page, excerpt length). Picking `content_catalog` vs `none` produced near-identical behavior. The config's own comment "Leave a value at its default to use the preset's recommendation" was false. Now the preset-overridable fields default to `null` (env-driven, no fallback), and scolta-php's `fromArray()` treats `null` as "not set" so it falls through to the preset (or the base default when the preset is `none`); an explicit value — set via the matching `SCOLTA_*` env var — still overrides. Requires the scolta-php null-as-unset fix ([scolta-php#181](https://github.com/tag1consulting/scolta-php/pull/181), merged). Drupal and WordPress were never affected (they omit keys to fall through). **⚠️ Behavior change on upgrade:** existing Laravel sites that selected a Site Type preset will now actually receive that preset's tuning, which was previously ignored — result ranking, results-per-page, AI-summary candidate breadth, recency, and query expansion may shift. Sites on the default `none` preset move to `expand_subword_max_frequency` `0.10` (previously the hard-coded `0.05`), slightly broadening recall. This is the intended fix, but it is visible on upgrade; sites that want the old numbers can set the corresponding `SCOLTA_*` env vars to pin them.

### Added
- **`expansion_combine_mode` scoring config.** Expose the browser-side query-expansion candidate-selection knob added in scolta-php ([tag1consulting/scolta-php#170](https://github.com/tag1consulting/scolta-php/issues/170)) in the published `config/scolta.php`. `expansion_combine_mode` (`SCOLTA_EXPANSION_COMBINE_MODE`, default `relevance_union`) controls how a multi-term expansion's per-sub-query result sets are combined into the AI-summary candidate set — `relevance_union` keeps the historical behavior, `round_robin` deals the top few from each sub-query so the summarizer sees breadth across expansion terms. It is surfaced exactly like the other preset-overridable scoring values (an explicit value in the `scoring` section overrides the preset default — see Changed below). Requires scolta-php#179 (merged) to take effect at runtime.
- Added `SearchComponentRenderTest` — renders the search Blade component in a booted app and asserts the emitted `window.scolta` `pagefindPath`/`wasmPath`/endpoint URLs (parsed out of the script block) against both the nested and flat Pagefind layouts, plus the not-built warning path. The prior `BladeComponentTest` only string-matched the template source and was blind to wrong emitted URL values; this is the first true render test (uses the already-present `orchestra/testbench` dev dependency).

### Changed
- **`expansion_combine_mode` is now preset-defaulted, and the `expansion_per_term_top_k` (K) config key is removed.** Following the scolta-php #170 evaluation, `round_robin` is the better summary-candidate strategy on faceted corpora and a safe no-op elsewhere, so it is now a per-Site-Type preset default in scolta-php: the `content_catalog`, `blog`, and `ecommerce` presets default the combine mode to `round_robin`; `reference`, `none`, and the base default stay `relevance_union`. `expansion_combine_mode` stays in the `scoring` section as the per-site override, surfaced exactly like the other preset-overridable scoring values (an explicit value wins over the preset default). The per-sub-query top-K is no longer tunable — evaluation found no benefit above `3` and over-reach below it — so scolta-php locks it at `3` internally and the **`scoring.expansion_per_term_top_k` config key and its `SCOLTA_EXPANSION_PER_TERM_TOP_K` env var are removed**; `ConfigTest` now asserts the key is absent. The preset-default behavior flows through the existing config plumbing, so no scolta-php release bump is required for it. ([tag1consulting/scolta-php#180](https://github.com/tag1consulting/scolta-php/pull/180))
- **Stopped restating scoring/display defaults in the README; point to scolta-php's `CONFIG_REFERENCE.md` instead.** The `Default` column of the README's `Search Scoring` and `Display` tables duplicated scolta-php's canonical defaults and had drifted (`scoring.title_match_boost` showed `1.0` vs the current `2.0`; `scoring.recency_boost_max` showed `0.5` vs `0.25`). Those columns are removed and each table now links to [scolta-php `docs/CONFIG_REFERENCE.md`](https://github.com/tag1consulting/scolta-php/blob/main/docs/CONFIG_REFERENCE.md), the single source of truth for defaults (verified against `ScoltaConfig` in scolta-php CI). The `.env` keys, `config/scolta.php` paths, preset guidance, and worked override examples are unchanged. Docs-only — no config or default values change.
- **Reworked the README "Preset" section to lead with the site type and a symptom→fix prompt.** Opens with "Getting fewer search results than you expect on a recipe, product, or catalog site? Set `SCOLTA_PRESET=content_catalog`…", frames presets as the recommended path over hand-set numbers, and links to scolta-php's `docs/TUNING.md` for the evidence. Docs-only — no config or default values change.
- **Dropped the redundant `message()`/`conversation()`/`messageForOperation()` overrides in `ScoltaAiService`; the base `AiServiceAdapter` now owns the budget-exception try/catch.** Each override existed only to wrap the `parent::` call in `try/catch (\RuntimeException)` → `handlePossibleBudgetException()`. scolta-php's base class now does that wrapping and calls a protected `handlePossibleBudgetException()` hook, so the adapter keeps only its hook override (visibility changed `private`→`protected`). Behavior is identical — an Amazee budget error still converts to `AmazeeBudgetExceededException`. Requires scolta-php ≥ 1.0.3 (the release that adds the hook). ([scolta-php#173](https://github.com/tag1consulting/scolta-php/pull/173))
- Opened 1.0.3-dev development cycle.

### Internal
- **Release workflow is now notes-only; dropped the redundant vendor-bundled release zip and its `validate-zip` job.** Composer resolves `tag1/scolta-laravel` from Packagist's GitHub source zipball, so the manually-built `scolta-laravel-${VERSION}.zip` release asset had no consumer. The tag-triggered release now publishes the CHANGELOG entry with GitHub's auto-attached source archives only. The `lock-guard` job (verifies `tag1/scolta-php` is pinned to a Packagist stable dist) is retained. Removed the `StructuralIntegrityTest` methods that asserted the deleted zip/validate-zip behavior and replaced them with `test_release_workflow_uploads_no_build_artifact`, which fails if a build artifact upload or `validate-zip` job is re-added.
- **Block-scoped ignores for the security advisories that broke CI dependency resolution on the EOL Laravel 11 matrix row.** Newly-published advisories against laravel/framework 11.x made `composer update` refuse to install on the Laravel 11/12 CI rows. Migrated `config.audit.ignore` to the detailed object form with `apply: "block"` (resolver stops blocking; `composer audit` still reports), one documented + tagged entry per advisory ID. The five laravel/framework IDs are tagged `[PERMANENT:laravel-11-eol]` (Laravel 11 reached EOL 2026-03-12; the genuine blocker `CVE-2026-48019` covers all 11.x with no 11.x fix coming, and the other four are already patched in 11.x but enumerated across the candidate range) — remove them when Laravel 11 is dropped from the matrix. The two pre-existing PHPUnit IDs are re-tagged `[DEV-ONLY:phpunit]`. No runtime/dependency change; supported Laravel 12/13 are unaffected (already patched).

## [1.0.2] - 2026-06-02

### Fixed
- **Browsers no longer serve a stale `scolta.js`/`scolta.css` after a deploy.** Root cause: the search Blade component emitted the published asset URL via `asset('vendor/scolta/scolta.js')` with **no version or hash at all**, so the URL was byte-identical across deploys and HTTP caches kept serving the old file. The asset URLs now append `?v=filemtime(public_path(...))` (guarded by the existing `file_exists` check), a token that changes whenever the published file changes, so a normal reload picks up fresh JS/CSS. Added `AssetCacheBustingTest` (behavioral) plus `BladeComponentTest` coverage asserting the rendered URLs carry a non-empty `?v=` token derived from the asset mtime.

### Added
- **`expand_subword_deny_list` scoring config (default empty).** Reads from `SCOLTA_EXPAND_SUBWORD_DENYLIST` (comma-separated). Guard-only veto list for the scolta-php sub-word query-term exemption (scolta-php#156 follow-up): words listed here are never auto-exempted from the sub-word frequency guard even when the user types them, so a typed-but-generic word (e.g. `hot` on a recipe site) cannot re-flood results. Unlike `custom_stop_words` this does not affect relevance scoring or query tokenization; listed words stay searchable and scorable.
- **`expand_subword_max_frequency` scoring config (default `0.05`).** Threads through to the JS scoring config for the scolta-php sub-word frequency guard (scolta-php#156) — a multi-word expansion term's constituent words are searched on their own only when below this corpus frequency, restoring broad-query recall while blocking high-frequency noise. Also added `cross_list_bonus` (`0.05`) to the published config.

### Changed
- Opened 1.0.2-dev development cycle.
- **Scoring default tuning (matches scolta-php):** `title_match_boost` `1.0` → `2.0`, `recency_boost_max` `0.5` → `0.25` in the published `config/scolta.php`.
- **Updated bundled scolta-php dependency to 1.0.2.** The committed `composer.lock` pin moves from 1.0.1 to the 1.0.2 Packagist release, so the `scolta.js` served from the dependency now ships the #156 frequency-guard follow-ups (Fix A/D typed-query-term exemption + `expand_subword_deny_list`) and the reverted query-word-importance line.
- **Release archive cleanup now strips all vendored `*.neon`/`*.dist`/`*.log` dev-config files.** The build-stage `find` glob only removed `phpstan.neon*`, so newer transitive dependencies shipping other `.neon` files (e.g. `nesbot/carbon`'s `extension.neon`) survived cleanup and tripped the `validate-zip` disallowed-extension guard, leaving the release CI red. The cleanup glob is now a superset of the validator's denylist, so the archive stays clean and `validate-zip` passes.

> Note: scolta-laravel serves `scolta.js` directly from the scolta-php dependency (no committed copy), so the frequency-guarded build ships automatically once the scolta-php dependency is updated to the release that contains it. This PR adds the config plumbing so the new control is wired and ready.

## [1.0.1] - 2026-05-30

### Changed
- **Export files now use nested directory layout mirroring canonical URLs** instead of flat `{id}.html`, aligning binary indexer output with PHP indexer (scolta-php#157).
- **HTML file counting uses recursive directory walk** instead of flat glob.
- **Decoupled release build from lockstep scolta-php tagging.** `release.yml` no longer checks out scolta-php at the same tag or runs `composer update tag1/scolta-php`. The committed `composer.lock` pins scolta-php to a stable Packagist release (currently 1.0.0), and the release job uses `composer install --no-dev` against that lock. A new `lock-guard` CI job (in both `ci.yml` and `release.yml`) fails if the committed lock pins scolta-php to a path, dev, or pre-release source.
- **Release archive uses fail-closed allowlist.** The ZIP build now copies enumerated root files and source dirs by extension, rather than using a denylist of `--exclude` patterns. Vendor is pruned of test dirs and dev config files. A disallowed-extension content guard in `validate-zip` catches regressions.

## [1.0.0] - 2026-05-27

### Documentation
- **README: document all Artisan commands.** Added `scolta:amazee:provision`, `scolta:cleanup`, and `scolta:memory-budget` to the Artisan Commands section.
- **README: document all API routes.** Added `build-progress` and `rebuild-now` endpoints to the API Endpoints table, with note about Sanctum authentication requirement.
- **README: document `ai_expansion_model` config key.** Added to configuration table with env var and description.

### Tests
- **Replace tautological `ControllerValidationTest`.** Tests now exercise real `AiEndpointHandler` code paths instead of testing PHP built-in functions against hardcoded values.

### Fixed
- **Blade component detects search index in both nested and flat layouts.** The `<x-scolta::search />` component now checks `output_dir/pagefind/pagefind-entry.json` (nested — PHP indexer) and falls back to `output_dir/pagefind-entry.json` (flat — binary pipeline / Cloud flatten step). Previously only the nested path was checked, causing a false "index not built" warning on Laravel Cloud deploys. The `pagefindPath` URL and CSS include are also derived from the detected layout. ([#60](https://github.com/tag1consulting/scolta-laravel/issues/60))
- **`state_dir` path mismatch (runtime bug).** `BuildCommand` and `TriggerRebuild` used `storage_path('scolta/state')` while `CleanupCommand` and `ProgressController` used `config('scolta.state_dir')` with a default of `storage_path('app/scolta')`. All callers now consistently use `config('scolta.state_dir')`, and the key is explicitly defined in `config/scolta.php`.
- **Stale version annotations in config comments.** Replaced `(0.2.2+)` with `(1.0.0+)` in `config/scolta.php`.
- **Stale `@deprecated 0.3.2` annotation.** Updated to `@deprecated 1.0.0` in `BuildCommand::gatherContentItems()`.

### Changed
- **`minimum-stability` set to `stable` and `@RC` dropped from `scolta-php` constraint.** Ready for 1.0.0 stable release.
- **Removed duplicate `auto_rebuild` from `pagefind` config group.** The top-level `auto_rebuild` is the canonical key; the nested `pagefind.auto_rebuild` was unused.

### Added
- **`state_dir` config key** — explicit directory for PHP indexer build state. Default: `storage_path('app/scolta')`.
- **`amazee_route_prefix` and `amazee_middleware` config keys** — previously only defined inline in `routes/scolta-amazee.php`. Now visible in the published config file.
- **Composer `support` and `keywords` metadata** for Packagist discoverability.
- **`.gitattributes` export-ignore entries** for `CLAUDE.md`, `.env.example`, `composer.lock`, `.phpunit.cache/`, and `.editorconfig`.
- **Complete `.env.example`** covering all `env()` calls in `config/scolta.php`.
- **README documentation** for preset, indexer/memory, pagefind, caching, routes, auto-rebuild, sort/filter fields, Amazee.ai integration, and database migrations.

## [1.0.0-rc4] - 2026-05-18

### Fixed
- **Exclude `vendor/*/test/` directories from release ZIP.** The ZIP builder now excludes vendor `test/` directories (singular — `wamania/php-stemmer/test/files/` is ~17 MB), vendor `tests/` directories, and dev-only config files (`phpunit.xml*`, `phpstan.neon*`, `.php-cs-fixer*`). The `validate-zip` CI job now fails if any `.log` files or vendor test content appear in the archive. ([#56](https://github.com/tag1consulting/scolta-laravel/issues/56))
- **Add `## External Services` section to `README.md`.** Documents all external HTTP connections: GitHub API (pagefind download via `php artisan scolta:download-pagefind`), Pagefind binary from GitHub Releases, and AI provider APIs (Anthropic, OpenAI, OpenAI-compatible endpoints). Includes terms of service and privacy policy links for each service, consistent with scolta-wp's `readme.txt` disclosure.

### Added
- **`show_attribution` config key** — opt-in "Powered by Scolta" attribution rendered below the search widget. Set `SCOLTA_SHOW_ATTRIBUTION=true` in `.env` or `'show_attribution' => true` in `config/scolta.php`. Defaults to `false`. When enabled, the Blade component appends `<p class="scolta-attribution">Powered by Scolta</p>` after the search container. ([#102](https://github.com/tag1consulting/scolta-php/issues/102))
- **`sortable_field_descriptions` config key** — human-readable descriptions for each sortable field, passed to the LLM so it can map natural language like "longest" or "most recent" to the correct `data-pagefind-sort` attribute. Set as an associative array in `config/scolta.php` or via environment variables.
- **`filter_fields` and `filter_field_descriptions` config keys** — list the Pagefind filter dimensions your site emits (via `data-pagefind-filter`) and provide descriptions that help the LLM detect filter intent in queries. When non-empty, the expand-query prompt gains a FILTER INTENT section.

### Fixed
- **`auto_rebuild` now defaults to `true`**, matching WordPress and Drupal adapter behavior. Previously defaulted to `false`, causing new Laravel users' content changes to silently not appear in search results. Users with `SCOLTA_AUTO_REBUILD=false` in `.env` are unaffected. ([#51](https://github.com/tag1consulting/scolta-laravel/issues/51))
- **`scolta:build --sync` emits an actionable error when `memory_abort` fires before any chunks are committed.** Previously the command fell through to the generic "Index build failed: memory_abort" message. It now says "Memory limit hit before any chunks were committed. Reduce --chunk-size or increase memory_limit." to match the Drupal and WordPress adapter behaviour.
- **`scolta:build --sync` no longer exits with an error on memory pressure.**

### Tests
- **`BuildCommandMemoryHandlingTest`** — 15 structural tests verifying `memory_abort` and `index_only_complete` branch logic in `BuildCommand::buildWithPhpIndexer()`. Guards against regressions in the conditions that trigger background resume and queue-dispatched finalize. When `IndexBuildOrchestrator` returns `memory_abort` (voluntary yield at 75% of memory limit), `BuildCommand` now spawns `php artisan scolta:build --sync --resume` in the background instead of returning `FAILURE`. When all pages are indexed but the merge was deferred (`index_only_complete`), `FinalizeIndex` is dispatched to the queue. Both cases let the build continue in a fresh process with a clean heap. Requires [scolta-php#107](https://github.com/tag1consulting/scolta-php/pull/107). ([#87](https://github.com/tag1consulting/scolta-php/issues/87))

## [1.0.0-rc3] - 2026-05-13

### Added
- **Larastan (PHPStan for Laravel) at level 5** with a `composer analyse` script and a new `analyse` job in CI.
- **`.gitattributes`** to exclude dev files (`tests/`, `.github/`, `phpstan.neon`, etc.) from distribution archives.
- **Regression tests** for env() usage, filesystem abstraction, config caching, and ServiceProvider exception handling.

### Fixed
- **Removed `env()` calls from runtime code** (`AmazeeProvisionCommand`, `AmazeeSettingsController`). These calls break silently after `php artisan config:cache` because Laravel's config cache does not call `env()`. Both locations now read from `config('scolta.ai_api_key')`, which is already populated from `SCOLTA_API_KEY` via `config/scolta.php`.
- **Fixed nested `env()` in `config/scolta.php`**. The `ai_api_key` entry used `env('SCOLTA_API_KEY', env('SCOLTA_AI_API_KEY', ''))`, which evaluates the inner `env()` unconditionally. Replaced with `env('SCOLTA_API_KEY', '')`. The legacy `SCOLTA_AI_API_KEY` variable had no other references.
- **Replaced raw PHP filesystem functions with Laravel's `File` facade** across 8 files (`BuildCommand`, `ExportCommand`, `RebuildIndexCommand`, `CleanupCommand`, `StatusCommand`, `DownloadPagefindCommand`, `DiscoverCommand`, `HealthController`). Raw calls (`unlink`, `glob`, `mkdir`, `chmod`, `file_get_contents`, `file_put_contents`, `@filemtime`) are now routed through the facade, which is mockable in tests.
- **Removed error suppression operators** (`@unlink`, `@filemtime`) in favor of `File::delete()` and `rescue(fn () => File::lastModified(...))`. `File::delete()` does not throw on missing files, making the `@` operator unnecessary.
- **ServiceProvider exception catch blocks now call `report()`** instead of silently discarding the exception. The DB-not-migrated path is expected on first install, but previously any other exception (permissions, corrupt config) was silently lost.

## [1.0.0-rc2] - 2026-05-12

### Fixed
- **`scolta:build` now auto-publishes vendor assets.** After a successful build (PHP indexer, queue dispatch, or binary), the command runs `vendor:publish --tag=scolta-assets --force` to ensure the published JS/CSS/WASM in `public/vendor/scolta/` matches the installed package version. Previously, package updates via Composer left stale published assets in place, which could serve outdated JavaScript missing critical fixes.
- **Health endpoint and status command now verify asset freshness.** `assets_published` was a boolean existence check that reported `true` even when published JS was outdated and missing critical fixes. The health endpoint now compares the sha256 of published JS against the canonical checksum from scolta-php. A new `assets_current` field (true/false) and `assets_warning` message appear when assets are stale. The status command shows "published (current)" vs "published (STALE)" with a remediation hint.
- **`scolta:status` now shows "php (recommended)" when indexer is `auto`**, matching `scolta:build` behavior. Previously showed "binary (auto-detected)" when Pagefind was installed, contradicting the build command which correctly resolves auto→php. Binary status details are now only shown when indexer is explicitly set to `binary`.
- **CRITICAL: Amazee auto-provisioning no longer silently overrides users who configured their API key.** The `ScoltaAiService` singleton in `register()` now checks `$config['ai_api_key']` (SCOLTA_API_KEY env var) before loading Amazee credentials. When an explicit key is present, Amazee credentials and model overrides are skipped entirely — previously the singleton unconditionally loaded stored Amazee credentials and overrode the configured provider on every request.

## [1.0.0-rc1] - 2026-05-11

First stable release — all features from 0.3.x promoted to 1.0 API surface.

### Added
- **Config file documents all `ai_provider` options including `amazee`**, with setup instructions for each. A developer setting up Scolta for the first time can now see that Amazee.ai is an option and how to provision it without searching through external docs.
- **`scolta:status` shows Amazee.ai as the active provider** when Amazee credentials are stored, and suggests `php artisan scolta:amazee:provision` when no API key is configured.
- **Budget exceeded middleware returns user-friendly response for non-JSON requests.** Web visitors to the search page previously received a raw JSON 503. Non-JSON requests now get a redirect back with a session flash message ("AI search is temporarily unavailable. Your Amazee.ai trial budget has been exceeded."). API clients (JSON requests) continue to receive the 503 JSON response with `Retry-After: 3600`.

### Added
- **Search component now passes `currentLanguage` to the JS config.** `app()->getLocale()` is used to detect the active Laravel locale (e.g. `en`, `fr`, `en-US` → `en`), and the 2-letter language code is added to `window.scolta.currentLanguage`. `scolta.js` reads this value to auto-scope search results to the active language on first load. The auto-filter only activates when `ai_languages` has more than one entry, so single-language sites are unaffected.

- **Amazee.ai trial is provisioned automatically on first boot after install.** `ScoltaServiceProvider::boot()` calls the new private `attemptAmazeeAutoProvisioning()` method, which delegates to `AutoProvisioner::ensureAiAvailable()` from scolta-php. A cache key (`scolta_amazee_provisioned`) prevents re-running on every request. The provisioner is a no-op when `SCOLTA_API_KEY` / `scolta.ai_api_key` is configured. On success, `storeModels()` is called via the `onModelsResolved` callback so the best Claude model is persisted to the `scolta_config` table. DB exceptions (table not yet migrated) are caught and silenced so the first request doesn't fail.

### Documentation
- **PaaS deployment guidance added to README.** The `vendor:publish --tag=scolta-assets` step must run on every deploy on platforms like Laravel Cloud, Forge, and Vapor because the filesystem is rebuilt from the repo. The new "Deploying to PaaS Platforms" section explains why this happens, how to wire `vendor:publish --tag=scolta-assets --force` into the build pipeline via `post-autoload-dump` in the app's `composer.json`, and why the same hook in a dependency's `composer.json` is not executed for consumers. Platform-specific notes cover Laravel Cloud, Vapor, and Forge. ([scolta-laravel#25](https://github.com/tag1consulting/scolta-laravel/issues/25))

### Fixed
- **Async build writes Pagefind output to wrong path.** The PHP indexer (`IndexBuildOrchestrator::atomicSwap`) always writes the final index into `$outputDir/pagefind/`. The binary Pagefind invocations in `BuildCommand` and `RebuildIndexCommand` now also use `--output-path $outputDir/pagefind` so both indexers produce the same layout. `StatusCommand`, `HealthController`, and `CleanupCommand` now look for `pagefind.js` and fragment files in the `pagefind/` subdirectory. The Blade search component checks `$outputDir/pagefind/pagefind-entry.json` for index presence and constructs asset URLs with the `pagefind/` subdirectory. ([scolta-laravel#24](https://github.com/tag1consulting/scolta-laravel/issues/24))
- **Search index warning hidden from anonymous visitors.** The `@auth` gate that wrapped the "Search index has not been built yet" notice in the Blade component has been removed. The notice is now visible to all visitors when the index is absent. ([scolta-laravel#24](https://github.com/tag1consulting/scolta-laravel/issues/24))
- **`php artisan scolta:build` no longer crashes with "Call to undefined method ProcessIndexChunk::chain()".** `ProcessIndexChunk` and `FinalizeIndex` were missing the `Illuminate\Bus\Queueable` trait. Laravel's `Bus::chain()` calls `$firstJob->chain()` internally, which is provided by that trait; without it, dispatching a chained job sequence threw a fatal error. `TriggerRebuild` now also uses `Queueable` for consistency. ([scolta-laravel#23](https://github.com/tag1consulting/scolta-laravel/issues/23))

### Added
- **Laravel 13 support.** All `illuminate/*` constraints now include `^13.0`, and `orchestra/testbench` allows `^11.0`. The CI matrix covers Laravel 11, 12, and 13 with dedicated jobs. ([scolta-laravel#22](https://github.com/tag1consulting/scolta-laravel/issues/22))
- **Amazee.ai auto-configuration: best available Claude model is applied after trial provisioning.** `LaravelConfigStorage` gains `storeModels()` / `loadModels()` backed by the `scolta_config` table. `ScoltaServiceProvider` overlays the stored model values at boot time if `config('scolta.ai_model')` is still the default. `AmazeeSettingsController::startTrial()` and `AmazeeProvisionCommand::handle()` call `AmazeeModelResolver` and persist the results. The artisan command prints the auto-selected model names.
- **`AmazeeTrialProvisioner` now skips provisioning when an AI provider is already configured.** If `config('scolta.ai_api_key')` or `SCOLTA_API_KEY` is set, `scolta:amazee:provision` exits early with an informational message instead of consuming a trial slot. Pass `--force` to bypass the check. The Amazee settings page shows "AI provider already configured" instead of the connection form. `ProvisioningResult` gains a `STATUS_SKIPPED_EXISTING_PROVIDER` constant and `$status` field to let callers distinguish skips from provisioned and failed outcomes.
- **Amazee.ai integration.** Connect Scolta to Amazee.ai's privacy-respecting LiteLLM proxy.
  - `LaravelConfigStorage`: `ConfigStorageInterface` backed by the `scolta_config` database table (token encrypted via `Crypt` facade).
  - Migration `2026_05_08_000001_create_scolta_config_table` creates the key/value config store.
  - `AmazeeSettingsController`: multi-step connection flow (free trial + OTP sign-in) via 7 JSON endpoints at `GET|POST|DELETE /scolta/amazee/*`. In-flight state stored in the Laravel session.
  - `HandleAmazeeBudgetExceeded` middleware: converts `AmazeeBudgetExceededException` to a `503` JSON response with `Retry-After: 3600`; automatically applied to AI endpoints.
  - `AmazeeProvisionCommand` (`scolta:amazee:provision {email}`): CLI provisioning for headless/CI environments.
  - `amazee-settings.blade.php`: Alpine.js multi-step settings UI, matching the WordPress/Drupal admin pages.
  - `ScoltaAiService` now detects stored Amazee credentials at container-bind time and routes requests through the LiteLLM proxy. Budget `RuntimeException`s are converted to `AmazeeBudgetExceededException`.

### Changed
- Added `extra.branch-alias` (`dev-main` → `1.0.x-dev`) so consumers can resolve this package with `^1.0@dev` from a VCS repository.
- **`indexer: auto` now always uses the PHP indexer.** Previously `auto` tried the Pagefind binary first and fell back to PHP. The PHP indexer works on all Laravel hosting environments without `exec()` or Node.js, uses less memory, and supports fast incremental re-indexing. Use `indexer: binary` to keep the old binary-first behaviour.
- **`php artisan scolta:build --force` now bypasses the per-item token cache** in addition to the existing fingerprint check. Previously `--force` only skipped the `shouldBuild()` fingerprint comparison; the page-word cache (new in this release, provided by scolta-php) was still consulted. With this change, `--force` triggers a full re-tokenization of every content item.

## [0.3.10] - 2026-05-05

### Note
- Version synchronized with coordinated 0.3.10 release across all Scolta packages. No Laravel-specific changes in this release.

## [0.3.9] - 2026-05-02

### Added
- **`preset` key in `config/scolta.php`**: Set `SCOLTA_PRESET` in `.env` (or edit the published config file) to apply a named scoring preset. Available values: `content_catalog`, `reference`, `ecommerce`, `blog`, `none` (default). The `flattenConfig()` helper already passes this key through to `ScoltaConfig::fromArray()`, which applies preset values before any explicit scoring overrides in the `scoring` array. Requires scolta-php ≥ 0.3.9.

## [0.3.8] - 2026-05-01

### Note
- Version synchronized with scolta-php 0.3.8. No Laravel-specific changes in this release.

## [0.3.7] - 2026-04-30

### Improved
- Documentation: clearer use-case descriptions for Laravel applications, cross-platform messaging.

## [0.3.6] - 2026-04-29

### Added
- **`ai_expansion_model` config key** (`SCOLTA_EXPANSION_MODEL` env var, default `''`): Optional model for query expansion only. When set, expand-query uses this model while summarize and follow-up continue using `ai_model`. Leave empty for unchanged single-model behavior.

## [0.3.5] - 2026-04-28

### Changed
- **Default `expand_primary_weight` lowered to 0.5** (was 0.7) — gives AI-expanded terms more influence for intent-based queries. To restore the previous behavior, set `expand_primary_weight: 0.7` in config.
- **Default `ai_summary_top_n` raised to 10** (was 5) — AI sees more results and curates better for constraint queries and diverse result sets.
- **Default `ai_summary_max_chars` raised to 4000** (was 2000) — supports the increased `ai_summary_top_n` with enough excerpt content for quality curation.

## [0.3.4] - 2026-04-27

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

[Unreleased]: https://github.com/tag1consulting/scolta-laravel/compare/1.0.1...HEAD
[1.0.1]: https://github.com/tag1consulting/scolta-laravel/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/tag1consulting/scolta-laravel/compare/1.0.0-rc4...1.0.0
[1.0.0-rc4]: https://github.com/tag1consulting/scolta-laravel/compare/1.0.0-rc3...1.0.0-rc4
[1.0.0-rc3]: https://github.com/tag1consulting/scolta-laravel/compare/1.0.0-rc2...1.0.0-rc3
[1.0.0-rc2]: https://github.com/tag1consulting/scolta-laravel/compare/1.0.0-rc1...1.0.0-rc2
[1.0.0-rc1]: https://github.com/tag1consulting/scolta-laravel/compare/0.3.10...1.0.0-rc1
[0.3.10]: https://github.com/tag1consulting/scolta-laravel/compare/0.3.9...0.3.10
[0.3.9]: https://github.com/tag1consulting/scolta-laravel/compare/0.3.8...0.3.9
[0.3.8]: https://github.com/tag1consulting/scolta-laravel/compare/0.3.7...0.3.8
[0.3.7]: https://github.com/tag1consulting/scolta-laravel/compare/0.3.6...0.3.7
[0.3.6]: https://github.com/tag1consulting/scolta-laravel/compare/0.3.5...0.3.6
[0.3.5]: https://github.com/tag1consulting/scolta-laravel/compare/0.3.4...0.3.5
[0.3.4]: https://github.com/tag1consulting/scolta-laravel/compare/0.3.3...0.3.4
[0.3.3]: https://github.com/tag1consulting/scolta-laravel/compare/0.3.2...0.3.3
[0.3.2]: https://github.com/tag1consulting/scolta-laravel/compare/0.3.1...0.3.2
[0.3.1]: https://github.com/tag1consulting/scolta-laravel/compare/0.3.0...0.3.1
[0.3.0]: https://github.com/tag1consulting/scolta-laravel/compare/0.2.4...0.3.0
[0.2.4]: https://github.com/tag1consulting/scolta-laravel/compare/0.2.3...0.2.4
[0.2.3]: https://github.com/tag1consulting/scolta-laravel/compare/0.2.2...0.2.3
[0.2.2]: https://github.com/tag1consulting/scolta-laravel/compare/0.2.1...0.2.2
[0.2.1]: https://github.com/tag1consulting/scolta-laravel/compare/0.2.0...0.2.1
[0.2.0]: https://github.com/tag1consulting/scolta-laravel/releases/tag/0.2.0
