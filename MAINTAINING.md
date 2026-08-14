# MAINTAINING — scolta-laravel

The Laravel adapter over scolta-php. Publishes to Packagist.

Everything true of more than one Scolta repo lives in
[scolta-core/MAINTAINING.md](https://github.com/tag1consulting/scolta-core/blob/main/MAINTAINING.md):
the version rules, the release order, the fleet checks, the rules every repo shares.

**What it is.** A Laravel adapter, glue only. It depends on `scolta-php` and never on `scolta-core`
directly.

**Where the version lives.** `composer.json` `version`, with `extra.branch-alias.dev-main` beside it
naming the same line.

**Where it publishes.** Packagist, as `tag1/scolta-laravel`. To confirm:
`composer show tag1/scolta-laravel -a | grep versions`.

**CI checks.** phpunit (`test`, plus `coverage`), `docs-check` (CHANGELOG when code changes),
`version-sync` (this package's major against the `tag1/scolta-php` constraint), `analyse` (Larastan),
`lock-guard` (`composer validate`, and the lock must not name `tag1/scolta-php` from a `dist.type=path`
repo), `antipatterns` (which also refuses a duplicate `###` sub-header under `## [Unreleased]` in
CHANGELOG.md), `Validate Composer dist archive`, and `Version coherence`. The scolta-php floor is
covered by `tests/ScoltaPhpFloorTest.php` inside phpunit.

**On release day.** Release this after scolta-php. During a cycle the floor carries `@dev`; once
scolta-php `X.Y.0` is tagged, drop the `@dev`, re-lock, then release. `lock-guard` in `release.yml`
refuses to publish while the lock still names a development version of scolta-php.

**Watch out for.**

- This package keeps no copy of the browser bundle. It reads the assets from `vendor/tag1/scolta-php/`
  and publishes them with `vendor:publish`.
- `composer update` does not re-publish `public/vendor/`, and `vendor:publish` runs once without
  noticing staleness, so a stale published asset is the regression to look for. `src/Services/AssetStatus.php`
  compares the published file's sha256 against the bare hash in
  `vendor/tag1/scolta-php/assets/js/scolta.js.sha256`; `HealthController`, `StatusCommand`, `BuildCommand`
  and the service provider all read it through that class. Never check the asset with `file_exists()`:
  a present-but-stale file passes and is still broken.
- On PaaS platforms the filesystem is rebuilt on every deploy, so `vendor:publish --tag=scolta-assets`
  belongs in the build pipeline, not just in first-time setup.
