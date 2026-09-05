# Upgrade notes

Breaking changes and the action each one requires, newest first. A release that
needs nothing from you is not listed here; see `CHANGELOG.md` for the full
record.

## 1.4.0

### The rebuild a content save queues is now incremental

**Action required: none, unless you rely on every save triggering a full
rebuild.**

With `auto_rebuild` on, saving or deleting a model queued a `TriggerRebuild`
that streamed the whole corpus. It now applies just the tracked changes to the
published index, and streams the corpus only when it cannot — no index to update
against, change tracking not installed, a change set over
`incremental.max_changed_items`, a tracked row naming a record that has left the
database, or the indexer's own refusal. Each fallback writes its reason to the
application log. `POST /api/scolta/v1/rebuild-now` with `force=true` is
unaffected: a forced rebuild is still a full one.

Set `SCOLTA_INCREMENTAL_ENABLED=false` to keep the old behaviour.

Two things worth knowing before you deploy:

- The in-place update needs a page-table ledger, and both build paths now write
  one: `php artisan scolta:build` and the queued chunk chain alike. Nothing has
  to be scheduled to keep the fast path available, and a site whose only builds
  are queued gets incremental updates too. If you are upgrading an installation
  that has only ever built through the queue, the first queued rebuild after
  this release is what writes the ledger; the edit after it is incremental.
  Keep an occasional `scolta:build` scheduled all the same: it is the only
  build that prunes the token cache, which the queued path only adds to.
- A queued rebuild now clears the `scolta_tracker` rows it covered, which it
  never did. If you were watching `pending_index` in `scolta:status` or
  `/api/scolta/v1/health` and treating a permanently non-zero value as normal,
  it will start reading zero.

### `scolta:build --incremental` is a deprecated no-op

**Action required: drop the flag from deploy scripts when convenient.**

The option is still accepted and still exits 0; it prints a deprecation warning
and runs a full build, which is now the only thing `scolta:build` does. That
matches `drush scolta:build`, and it is where the flag's capability went: an
edit gets an incremental update from the queued rebuild above, without anyone
having to ask.

Two behaviour changes come with it. `--incremental` no longer conflicts with
`--force`, `--resume`, `--restart`, `--reset-ledger` or `--queue` — the pair used
to exit 2 and now runs the full build all of them were asking for. And
`scolta:build --indexer=binary --incremental` no longer re-exports only the
changed HTML; it runs the full export, which removes a deleted page by emptying
the build directory rather than by sweeping it.

`scolta:export --incremental` is unchanged and not deprecated. It is the only
way to re-export just the changed HTML for the Pagefind CLI pipeline, and no
automatic path covers that pipeline.

### A new migration adds `scolta_tracker.item_id`

**Action required: re-publish the migrations and run them.**

```bash
php artisan vendor:publish --tag=scolta-migrations
php artisan migrate
```

The tracker used to record only an Eloquent primary key for a deleted record,
and a primary key cannot locate the exported page or index entry that record
owned — those are keyed by `ContentItem::$id`, which `toSearchableContent()`
invents. The new nullable `item_id` column holds that id, captured by
`ScoltaObserver` at the moment it records the deletion, which is the last
moment the answer exists.

Nothing breaks if you delay: `ScoltaTracker::track()` checks for the column
once per process and omits it when it is absent, and `ContentSource` falls back
to reloading the record. But until the migration runs, a *hard*-deleted record
cannot be resolved to a page — `scolta:export --incremental` now says so on the
console instead of reporting a removal that did not happen, and the queued
rebuild says so in the log; both fall back to a full run, which clears the
orphans.

Rows written before the migration ran keep a null `item_id` for the same
reason. If any are still pending, run one full build after migrating.

## 1.2.0

This package's own public API is unchanged in 1.2.0. Nothing in
`Tag1\ScoltaLaravel\` changed signature, and no published config key was removed
or renamed. The breaks in this release are inherited through the library it
vendors, plus one behaviour change worth knowing about before you deploy.

### Inherited from scolta-php 1.2.0

This release requires `tag1/scolta-php` `^1.2.0`, up from `^1.1.0`. That library
carries two breaking changes:

- **The `AmazeeCredentials` constructor signature changed.**
- **The `aiProvider` default changed.**

Neither type is re-exported by this package's API, so an application that only
uses the Artisan commands, the `Searchable` trait, the published config and the
HTTP routes needs to do nothing. An application with custom code constructing
`AmazeeCredentials` directly, or relying on the old `aiProvider` default when
calling into scolta-php itself, is exposed to both. See scolta-php's 1.2.0
upgrade notes for the detail and the required changes.

### No AI provider is configured by default

Not a break in the signature sense, but it changes what a fresh install does.
`config/scolta.php` shipped `ai_provider` as `'anthropic'`, and
`scolta:status` coalesced an empty value back to it, so an application nobody
had configured reported itself as an Anthropic application. The shipped default
is now `''`, nothing coalesces, and `scolta:status` reports that no provider is
selected and AI features are off. Search is unaffected either way.

This is going-forward only. An application that already sets
`SCOLTA_AI_PROVIDER`, or that has published and edited `config/scolta.php`,
keeps exactly what it has. If you were relying on the implicit Anthropic
default without ever setting it, set `SCOLTA_AI_PROVIDER=anthropic` to keep the
old behaviour.
