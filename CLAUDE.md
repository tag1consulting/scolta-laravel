# Claude Rules for scolta-laravel

## Versioning (CRITICAL — read scolta-core/VERSIONING.md)

Each Scolta package versions independently, from its own git tags. Compatibility with scolta-php is expressed by the caret constraint in `composer.json`, not by matching version numbers with it. No `composer.lock` is committed (it is git-ignored, and CI's `composer-json-guard` refuses one): the constraint is the whole contract, and every install resolves fresh within it. This is a platform adapter — it depends on scolta-php, never on scolta-core directly.

### Rules

- **NEVER** reimplement scoring, HTML cleaning, or prompt logic. These belong in scolta-core via scolta-php.
- **NEVER** change `composer.json` to depend on `tag1/scolta-core`. Depend on `tag1/scolta-php`.
- Dependency constraint MUST be a caret constraint: `"tag1/scolta-php": "^X.Y"` (or `@dev` for development).
- All public methods SHOULD have `@since` and `@stability` annotations.

### Version management and -dev workflow

The `version` field in `composer.json` is always either a tagged release (`0.2.0`) or a dev pre-release (`0.3.0-dev`). See scolta-core/VERSIONING.md for the full workflow. In Composer, `-dev` prevents accidental production installs without an explicit `@dev` flag.

- If current version has `-dev`, **do not change it** — multiple commits accumulate on one dev version.
- If current version is a bare release and you're making the first change after it, bump to next target with `-dev`.
- **WARNING:** Never commit a bare version bump without tagging it as a release.

### Local cross-package development

`composer.json` already commits everything local development needs: the `../scolta-php` path repo and a `preferred-install: source` for it. While the floor carries `@dev` — the usual state mid-cycle — a plain `composer update tag1/scolta-php` picks up the sibling checkout, no config changes required. Only when the floor is a bare release constraint does testing an un-released scolta-php need `composer config minimum-stability dev && composer require tag1/scolta-php:@dev`. Your local `composer.lock` is git-ignored either way; whatever it resolves — path repo included — stays on your machine.

The release gate lives in `release.yml`: at tag time, `resolve-guard` resolves `composer.json` fresh from Packagist and refuses to publish while the `tag1/scolta-php` constraint carries `@dev` or resolves to a development version. That is the gate: scolta-laravel 1.2.0 cannot be released before scolta-php 1.2.0 exists. Drop the `@dev` suffix from the constraint when it does.

### Laravel conventions

- Follow Laravel package conventions: service provider, publishable config/views/migrations.
- Controllers are invokable (single `__invoke` method).
- Use Laravel's Cache, Process, Http facades — not raw PHP equivalents.
- Models use the `Searchable` trait pattern (similar to Scout).
- Config values should read from `env()` with sensible defaults.

## Testing

- Run: `./vendor/bin/phpunit`
- Tests use plain PHPUnit (not Orchestra Testbench) for speed.
- ConfigTest requires a minimal `Illuminate\Foundation\Application` for `storage_path()`/`public_path()`.
- All tests should pass in CI without any native runtime.

## Documentation Rules

Documentation follows code. When a PR changes behavior, the same PR must update the relevant docs.

- **CHANGELOG.md**: Every PR that changes code (not docs-only) MUST add an entry under `## [Unreleased]`. CI enforces this.
- **README.md**: Update if the change affects installation, Artisan commands, API endpoints, Searchable trait, or configuration.
- **config/scolta.php**: Published config file MUST have inline comments explaining each setting.
- **PHPDoc**: All public methods SHOULD have complete PHPDoc including `@since` and `@stability`.
