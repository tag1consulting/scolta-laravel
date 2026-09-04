<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\ScoltaLaravel\AiProvider\Amazee\LaravelConfigStorage;
use Tag1\ScoltaLaravel\Cache\LaravelCacheDriver;
use Tag1\ScoltaLaravel\Commands\AmazeeProvisionCommand;
use Tag1\ScoltaLaravel\Commands\BuildCommand;
use Tag1\ScoltaLaravel\Commands\CheckSetupCommand;
use Tag1\ScoltaLaravel\Commands\CleanupCommand;
use Tag1\ScoltaLaravel\Commands\ClearCacheCommand;
use Tag1\ScoltaLaravel\Commands\DiscoverCommand;
use Tag1\ScoltaLaravel\Commands\DownloadPagefindCommand;
use Tag1\ScoltaLaravel\Commands\ExportCommand;
use Tag1\ScoltaLaravel\Commands\MemoryBudgetCommand;
use Tag1\ScoltaLaravel\Commands\RebuildIndexCommand;
use Tag1\ScoltaLaravel\Commands\StatusCommand;
use Tag1\ScoltaLaravel\Http\Middleware\HandleAmazeeBudgetExceeded;
use Tag1\ScoltaLaravel\Jobs\TriggerRebuild;
use Tag1\ScoltaLaravel\Observers\ScoltaObserver;
use Tag1\ScoltaLaravel\Services\AssetStatus;
use Tag1\ScoltaLaravel\Services\ContentSource;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * Scolta service provider.
 *
 * Laravel's service provider pattern is elegant: one class wires up
 * everything the package needs — config, routes, views, commands,
 * migrations, model observers. Auto-discovery means users just run
 * `composer require` and it works.
 *
 * This provider showcases Laravel's strengths:
 *   - Container bindings for dependency injection (no global state)
 *   - Publishable assets (config, migrations, views) for full customization
 *   - Model observers auto-registered from config (no manual wiring)
 *   - Artisan commands conditionally loaded (console only)
 *   - Blade components registered with a namespace prefix
 */
class ScoltaServiceProvider extends ServiceProvider
{
    /**
     * Register bindings in the container.
     *
     * This runs before boot() — only container bindings here, no
     * side effects. Laravel's container is the backbone of the
     * framework; every service is resolved through it.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/scolta.php', 'scolta');

        // Bind the AI service as a singleton — one instance per request,
        // config read once, reused across all three endpoints.
        // The managed Amazee.ai gateway is enabled explicitly by an operator,
        // through the settings page or `artisan scolta:amazee:provision`; this
        // factory only ever reads the credentials such an action stored. It
        // establishes no connection of its own, so a request can never turn AI
        // on by itself. Amazee credentials are read only when no explicit API
        // key is configured, so users who set SCOLTA_API_KEY are never silently
        // rerouted.
        $this->app->singleton(ScoltaAiService::class, function ($app) {
            $config = $app['config']['scolta'];
            $amazeeActive = false;

            // Explicit key (SCOLTA_API_KEY env var or published config) wins.
            $explicitKey = $config['ai_api_key'] ?? '';
            if ($explicitKey !== '') {
                // The operator is on their own provider now: drop any stored
                // managed-gateway connection so no leftover state shadows the
                // explicit key.
                $this->clearAmazeeConnection();
            } else {
                try {
                    $storage = new LaravelConfigStorage;
                    $creds = $storage->load();
                    if ($creds !== null) {
                        if (self::modelsAreResolved($storage->loadModels())) {
                            // Fully provisioned: drive the LiteLLM gateway with
                            // the resolved model.
                            $config['ai_provider'] = 'openai';
                            $config['ai_api_key'] = $creds['litellm_token'];
                            $config['ai_base_url'] = $creds['litellm_api_url'];
                            $amazeeActive = true;

                            // Apply auto-selected models only when the current
                            // config value is still the default (not manually
                            // overridden).
                            $models = $storage->loadModels();
                            $defaultModel = ScoltaAiService::DEFAULT_MODEL;
                            if ($models['ai_model'] !== '' && $config['ai_model'] === $defaultModel) {
                                $config['ai_model'] = $models['ai_model'];
                            }
                            if ($models['ai_expansion_model'] !== '' && ($config['ai_expansion_model'] ?? '') === '') {
                                $config['ai_expansion_model'] = $models['ai_expansion_model'];
                            }
                        } else {
                            // Credentials are stored but model resolution never
                            // succeeded, so config still carries the shipped
                            // dated default — which the Amazee LiteLLM gateway
                            // rejects with HTTP 400, breaking AI permanently and
                            // silently. Do NOT inject the Amazee key: a key-less
                            // client throws ApiKeyMissingException, which the
                            // controllers degrade to an unexpanded/no-summary
                            // HTTP 200, never a 400. $amazeeActive stays true
                            // (credentials ARE stored) so /health and key-expiry
                            // recovery still see the Amazee path. Model names are
                            // resolved and stored by the explicit enable paths
                            // (settings page, `scolta:amazee:provision`), so
                            // re-running either one clears this state; nothing is
                            // re-resolved from a request. Mirrors scolta-node's
                            // AmazeeAiService::buildClient().
                            $amazeeActive = true;
                        }
                    }
                } catch (\Exception $e) {
                    // DB not yet migrated — skip Amazee credential check.
                    report($e);
                }
            }

            $service = new ScoltaAiService($config, $amazeeActive);

            if ($amazeeActive) {
                // Managed-gateway path only: when the stored credentials stop
                // being accepted, KeyExpiryRecovery records that state and
                // raises the re-authentication signal the settings page and
                // `scolta:status` show, instead of silently killing AI. It
                // never requests credentials — reconnecting is an operator
                // action. The explicit-key branch above leaves $amazeeActive
                // false and returns this service unwired, so a user's own key is
                // never touched; budget-exhaustion is excluded by
                // KeyExpiryRecovery so it cannot reset the spend ceiling. The
                // recovery markers live in the same cache HealthController
                // reads, keeping /health honest.
                $service->setKeyExpiryRecovery(new KeyExpiryRecovery(
                    storage: new LaravelConfigStorage,
                    cache: new LaravelCacheDriver,
                    logger: logger(),
                ));
            }

            return $service;
        });

        // Bind ContentSource as a singleton for consistent access.
        $this->app->singleton(ContentSource::class, function () {
            return new ContentSource;
        });
    }

    /**
     * Bootstrap package services.
     *
     * This runs after all providers are registered. Side effects go here:
     * route loading, view registration, migration publishing, observer
     * attachment, command registration.
     */
    public function boot(): void
    {
        $this->app['router']->aliasMiddleware('scolta.amazee-budget', HandleAmazeeBudgetExceeded::class);

        $this->registerPublishables();
        $this->registerRoutes();
        $this->registerBladeComponents();
        $this->registerModelObservers();
        $this->registerRateLimiter();
        $this->registerHealthDetailGate();

        if ($this->app->runningInConsole()) {
            $this->registerCommands();
            $this->registerScheduledCleanup();
        }

        // First-run auto-build: if no index exists and auto_rebuild is enabled,
        // dispatch a one-time build so the search UI works on first visit.
        if (! $this->app->runningInConsole()) {
            $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));
            if (! file_exists($outputDir.'/pagefind/pagefind-entry.json')) {
                $cacheKey = 'scolta_initial_build_queued';
                if (! Cache::has($cacheKey) && config('scolta.auto_rebuild', true)) {
                    Cache::put($cacheKey, true, 3600);
                    TriggerRebuild::dispatch();
                }
            }
        }

        // NOTE: nothing here — or on any request path — establishes the managed
        // Amazee.ai gateway connection. It is enabled explicitly through the
        // settings page or `artisan scolta:amazee:provision`, so no page load
        // carries the cost or the consequence of turning AI on.
    }

    /**
     * Drop a stored managed-gateway connection and its recovery markers.
     *
     * Called from the AI-service factory when an explicit API key is
     * configured. Stored credentials are unreachable at that point — the
     * explicit key wins — and leaving them in place would let a stale
     * connection resurface the moment the key is removed, and keep
     * `/health` and the settings page reporting a connection the site is
     * not using. Both recovery markers go with them: they describe the
     * credentials being cleared, so a re-authentication prompt for a
     * connection that no longer exists is noise.
     *
     * Cheap in the common case: one indexed lookup that finds nothing and
     * returns. A DB that is not migrated yet simply has nothing to clear.
     *
     * @since 1.1.0
     *
     * @stability experimental
     */
    private function clearAmazeeConnection(): void
    {
        try {
            $storage = new LaravelConfigStorage;
            if ($storage->load() === null) {
                return;
            }

            $storage->clear();

            $recovery = new KeyExpiryRecovery(
                storage: $storage,
                cache: new LaravelCacheDriver,
                logger: logger(),
            );
            $recovery->clearUpgradeNeeded();
            $recovery->clearAuthFailure();

            logger()->info('[scolta] An explicit AI API key is configured; the stored Amazee.ai connection was cleared.');
        } catch (\Exception $e) {
            // DB not migrated — there is nothing stored to clear.
            report($e);
        }
    }

    /**
     * Whether a genuinely resolved Amazee AI model name is stored.
     *
     * The Amazee models store (LaravelConfigStorage::storeModels()) is written
     * only when model resolution succeeds during an explicit enable, so it is
     * empty when the `/model/info` step failed — the clean signal that the
     * stored credentials cannot drive the gateway yet. Unlike Drupal/WordPress,
     * no dated-default exclusion is needed: the store is never seeded with the
     * dated default.
     *
     * @param  array{ai_model: string, ai_expansion_model: string}|null  $models
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    private static function modelsAreResolved(?array $models): bool
    {
        return $models !== null && $models['ai_model'] !== '';
    }

    /**
     * Publish config, migrations, views, and assets.
     *
     * Laravel's publish system lets users customize anything. Run
     * `artisan vendor:publish --tag=scolta-config` for config only,
     * or omit the tag to publish everything.
     */
    private function registerPublishables(): void
    {
        if ($this->app->runningInConsole()) {
            // Config.
            $this->publishes([
                __DIR__.'/../config/scolta.php' => config_path('scolta.php'),
            ], 'scolta-config');

            // Migrations.
            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'scolta-migrations');

            // Views (so users can override the Blade component).
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/scolta'),
            ], 'scolta-views');

            // Frontend assets from scolta-php. AssetStatus resolves the
            // package path via reflection — works in monorepo and standard
            // installs, and returns null when scolta-php is not installed.
            $assetsPath = (new AssetStatus)->packageAssetsPath();
            if ($assetsPath !== null) {
                $publishable = [
                    $assetsPath.'/js/scolta.js' => public_path('vendor/scolta/scolta.js'),
                    $assetsPath.'/css/scolta.css' => public_path('vendor/scolta/scolta.css'),
                ];

                // Include browser WASM assets for client-side scoring.
                $wasmPath = $assetsPath.'/wasm';
                if (is_dir($wasmPath)) {
                    $publishable[$wasmPath.'/scolta_core.js'] = public_path('vendor/scolta/wasm/scolta_core.js');
                    $publishable[$wasmPath.'/scolta_core_bg.wasm'] = public_path('vendor/scolta/wasm/scolta_core_bg.wasm');
                }

                $this->publishes($publishable, 'scolta-assets');
            }
        }
    }

    /**
     * Load the API routes.
     *
     * Routes are loaded from a dedicated file, respecting the
     * configured prefix and middleware. This is the standard Laravel
     * package pattern for API routes.
     *
     * The Amazee.ai admin settings routes are NOT registered with the
     * default configuration — see amazeeAdminRoutesEnabled().
     */
    private function registerRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        if ($this->amazeeAdminRoutesEnabled()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/scolta-amazee.php');
        }
    }

    /**
     * Whether the Amazee.ai admin settings routes should be registered.
     *
     * Secure by default: the admin routes can disconnect stored AI
     * credentials and bind a trial to an arbitrary email address, so they
     * are only registered when the consumer has explicitly configured
     * 'scolta.amazee_middleware' beyond the bare ['web'] group — e.g.
     * ['web', 'auth']. With the shipped default the routes do not exist
     * (anonymous requests get 404) and the CLI, `artisan
     * scolta:amazee:provision`, is the way to enable the managed gateway.
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    private function amazeeAdminRoutesEnabled(): bool
    {
        $middleware = (array) config('scolta.amazee_middleware', ['web']);

        return $middleware !== [] && $middleware !== ['web'];
    }

    /**
     * Register the search Blade component.
     *
     * Usage: <x-scolta::search /> in any Blade template.
     * Users can override by publishing views to resources/views/vendor/scolta/.
     */
    private function registerBladeComponents(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'scolta');
    }

    /**
     * Attach the change-tracking observer to configured models.
     *
     * This is the Laravel equivalent of WordPress's save_post hook or
     * Drupal's Search API tracker. Eloquent model observers fire on
     * created, updated, deleted — the ORM does the tracking for us.
     *
     * The observer is registered for every model listed in config('scolta.models').
     * Models must use the Searchable trait, which defines how content
     * is rendered for indexing.
     */
    private function registerModelObservers(): void
    {
        $models = config('scolta.models', []);

        foreach ($models as $modelClass) {
            if (! class_exists($modelClass)) {
                logger()->error("[scolta] Configured model '{$modelClass}' does not exist. Content from this model will not be tracked.");

                continue;
            }

            // Validate that the model uses the Searchable trait.
            if (! in_array(Searchable::class, class_uses_recursive($modelClass), true)) {
                logger()->warning("[scolta] Model {$modelClass} is configured but does not use the Searchable trait.");

                continue;
            }

            $modelClass::observe(ScoltaObserver::class);
        }
    }

    /**
     * Register the Scolta rate limiter.
     *
     * Uses Laravel's built-in rate limiting. The limit is configurable
     * via config('scolta.rate_limit') / SCOLTA_RATE_LIMIT env var.
     */
    private function registerRateLimiter(): void
    {
        $limit = (int) config('scolta.rate_limit', 30);

        if ($limit > 0) {
            RateLimiter::for('scolta', function ($request) use ($limit) {
                return Limit::perMinute($limit)->by($request->ip());
            });
        }
    }

    /**
     * Register the health-detail authorization gate.
     *
     * Anonymous requests to the health endpoint receive status only; the
     * full diagnostic payload requires this gate. The default allows any
     * authenticated user (the closure's non-nullable parameter denies
     * guests). Host apps can redefine 'scolta.health-detail' in their own
     * AuthServiceProvider to tighten or loosen access — application
     * providers boot after package providers, so their definition wins.
     */
    private function registerHealthDetailGate(): void
    {
        if (! Gate::has('scolta.health-detail')) {
            Gate::define('scolta.health-detail', fn (object $user): bool => true);
        }
    }

    /**
     * Register Artisan commands.
     *
     * Laravel's command system is beautifully expressive — signature
     * strings define arguments and options declaratively, and the
     * framework handles parsing, validation, and help generation.
     */
    private function registerCommands(): void
    {
        $this->commands([
            AmazeeProvisionCommand::class,
            BuildCommand::class,
            CheckSetupCommand::class,
            MemoryBudgetCommand::class,
            ClearCacheCommand::class,
            CleanupCommand::class,
            DiscoverCommand::class,
            DownloadPagefindCommand::class,
            ExportCommand::class,
            RebuildIndexCommand::class,
            StatusCommand::class,
        ]);
    }

    /**
     * Schedule the retired-index sweep on the application's scheduler.
     *
     * Deliberately mirrors the Drupal adapter's `hook_cron()`, which sweeps
     * `.scolta-trash-*` on every cron run under the same `cleanup.cron_seconds`
     * budget. The alternative — documenting a `Schedule::command()` line and
     * registering nothing, the way `telescope:prune` and `sanctum:prune-expired`
     * are documented — was rejected: those commands enforce a *retention
     * policy* only the application can choose, whereas retired trash is garbage
     * by construction that nobody has an opinion about. It would also have left
     * a Drupal site with the backstop and a Laravel site without one, and the
     * gap the backstop covers is not small. `IndexBuildOrchestrator` sweeps
     * only on the success path, after the swap; a build that fails anywhere in
     * the merge leaves the staging directory it retired into trash sitting
     * there, and every retry adds another index-sized directory. Two publishing
     * paths here — `scolta:build --indexer=binary` and `scolta:rebuild-index` —
     * never reach the orchestrator and so never sweep at all.
     *
     * `callAfterResolving()` is the framework's own hook for this, so nothing
     * is constructed unless the scheduler is actually resolved, and the entry
     * shows up in `php artisan schedule:list` under its own command name.
     *
     * `--retired-only` because Drupal's cron does only the sweep, and because
     * this command's other passes must not run unattended (see
     * CleanupCommand::sweepStaleFiles()). No `max_execution_time` cap like
     * Drupal's, because there is no web-triggered equivalent: `schedule:run` is
     * always CLI, where the limit is 0. No `withoutOverlapping()`, matching
     * hook_cron: a daily run capped at the budget cannot realistically stack,
     * concurrent sweeps of the same trash are harmless, and a wedged mutex
     * would silently disable the backstop it is meant to protect.
     *
     * @since 1.4.0
     *
     * @stability experimental
     */
    private function registerScheduledCleanup(): void
    {
        $seconds = (int) config('scolta.cleanup.cron_seconds', 180);

        // 0 disables the scheduled sweep, exactly as on Drupal. Nothing is
        // registered at all, so `schedule:list` does not claim a task that
        // would immediately no-op.
        if ($seconds <= 0) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($seconds): void {
            $schedule->command('scolta:cleanup', ['--retired-only', '--max-seconds='.$seconds])
                ->daily();
        });
    }
}
