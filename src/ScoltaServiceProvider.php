<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;
use Tag1\Scolta\AiProvider\Amazee\AutoProvisioner;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\ScoltaLaravel\AiProvider\Amazee\LaravelConfigStorage;
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
        // Amazee.ai credentials are only injected when no explicit API key is
        // configured so users who set SCOLTA_API_KEY are never silently rerouted.
        $this->app->singleton(ScoltaAiService::class, function ($app) {
            $config = $app['config']['scolta'];
            $amazeeActive = false;

            // Explicit key (SCOLTA_API_KEY env var or published config) wins.
            $explicitKey = $config['ai_api_key'] ?? '';
            if ($explicitKey === '') {
                try {
                    $storage = new LaravelConfigStorage;
                    $creds = $storage->load();
                    if ($creds !== null) {
                        $config['ai_provider'] = 'openai';
                        $config['ai_api_key'] = $creds['litellm_token'];
                        $config['ai_base_url'] = $creds['litellm_api_url'];
                        $amazeeActive = true;
                    }

                    // Apply auto-selected models only when the current config
                    // value is still the default (not manually overridden).
                    $models = $storage->loadModels();
                    if ($models !== null) {
                        $defaultModel = 'claude-sonnet-4-5-20250929';
                        if ($models['ai_model'] !== '' && $config['ai_model'] === $defaultModel) {
                            $config['ai_model'] = $models['ai_model'];
                        }
                        if ($models['ai_expansion_model'] !== '' && ($config['ai_expansion_model'] ?? '') === '') {
                            $config['ai_expansion_model'] = $models['ai_expansion_model'];
                        }
                    }
                } catch (\Exception $e) {
                    // DB not yet migrated — skip Amazee credential check.
                    report($e);
                }
            }

            return new ScoltaAiService($config, $amazeeActive);
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

        if ($this->app->runningInConsole()) {
            $this->registerCommands();
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

        // First-run Amazee.ai provisioning: attempt once per installation.
        // Uses a cache flag to avoid re-running on every boot. The DB may
        // not be migrated yet on the very first boot; exceptions are silenced.
        $this->attemptAmazeeAutoProvisioning();
    }

    /**
     * Attempt Amazee.ai trial provisioning on first boot after install.
     *
     * No-op when SCOLTA_API_KEY is configured, credentials are already stored,
     * or the DB is not yet migrated. Uses a cache flag so the attempt only
     * happens once per installation.
     */
    private function attemptAmazeeAutoProvisioning(): void
    {
        $cacheKey = 'scolta_amazee_provisioned';
        if (Cache::has($cacheKey)) {
            return;
        }

        $hasExplicitApiKey = config('scolta.ai_api_key', '') !== '';

        try {
            $storage = new LaravelConfigStorage;
            $provisioned = AutoProvisioner::ensureAiAvailable(
                $storage,
                hasExplicitApiKey: $hasExplicitApiKey,
                onModelsResolved: function (string $aiModel, string $aiExpansionModel) use ($storage): void {
                    $storage->storeModels($aiModel, $aiExpansionModel);
                },
            );

            if ($provisioned) {
                // Mark as provisioned so we don't re-run on every boot.
                Cache::put($cacheKey, true, now()->addDays(30));
            } elseif (! $hasExplicitApiKey) {
                // Already provisioned or skipped — cache the result so we
                // don't attempt (and query the DB) on every request.
                Cache::put($cacheKey, true, now()->addDays(30));
            }
        } catch (\Exception $e) {
            // DB not migrated or API unreachable — silently degrade.
            report($e);
        }
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

            // Frontend assets from scolta-php.
            // Resolve the scolta-php package path via ReflectionClass to avoid
            // hardcoding vendor paths — works in monorepo and standard installs.
            try {
                $coreRef = new ReflectionClass(ScoltaConfig::class);
                $corePath = dirname($coreRef->getFileName(), 3);
                $assetsPath = $corePath.'/assets';

                if (is_dir($assetsPath)) {
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
            } catch (\ReflectionException $e) {
                // scolta-php not installed — skip asset publishing.
            }
        }
    }

    /**
     * Load the API routes.
     *
     * Routes are loaded from a dedicated file, respecting the
     * configured prefix and middleware. This is the standard Laravel
     * package pattern for API routes.
     */
    private function registerRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/scolta-amazee.php');
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
}
