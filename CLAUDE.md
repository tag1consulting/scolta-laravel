# Claude Rules for scolta-laravel

## Versioning (CRITICAL — read scolta-core/VERSIONING.md)

Major versions are synchronized across all Scolta packages. This is a platform adapter — it depends on scolta-php, never on scolta-core directly.

### Rules

- **NEVER** reimplement scoring, HTML cleaning, or prompt logic. These belong in scolta-core via scolta-php.
- **NEVER** change `composer.json` to depend on `tag1/scolta-core`. Depend on `tag1/scolta-php`.
- Dependency constraint MUST be a caret constraint: `"tag1/scolta-php": "^X.Y"` (or `@dev` for development).
- All public methods SHOULD have `@since` and `@stability` annotations.

### Version management and -dev workflow

The `version` field in `composer.json` is always either a tagged release (`0.2.0`) or a dev pre-release (`0.3.0-dev`). See scolta-core/VERSIONING.md for the full workflow.

- If current version has `-dev`, **do not change it** — multiple commits accumulate on one dev version.
- If current version is a bare release and you're making the first change after it, bump to next target with `-dev`.
- **WARNING:** Never commit a bare version bump without tagging it as a release.

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
- WASM-dependent tests are skipped when libextism is unavailable.
