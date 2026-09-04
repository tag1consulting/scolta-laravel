# Upgrade notes

Breaking changes and the action each one requires, newest first. A release that
needs nothing from you is not listed here; see `CHANGELOG.md` for the full
record.

## 1.4.0

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
cannot be resolved to a page — `scolta:export --incremental` and
`scolta:build --incremental --indexer=binary` now say so on the console instead
of reporting a removal that did not happen, and fall back to a full run for
that build, which clears the orphans.

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
