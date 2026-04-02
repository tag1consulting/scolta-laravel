<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;
use Tag1\ScoltaLaravel\Commands\BuildCommand;
use Tag1\ScoltaLaravel\Commands\StatusCommand;
use Tag1\ScoltaLaravel\Commands\DownloadPagefindCommand;
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
        $this->mergeConfigFrom(__DIR__ . '/../config/scolta.php', 'scolta');

        // Bind the AI service as a singleton — one instance per request,
        // config read once, reused across all three endpoints.
        $this->app->singleton(ScoltaAiService::class, function ($app) {
            return new ScoltaAiService($app['config']['scolta']);
        });

        // Bind ContentSource as a singleton for consistent access.
        $this->app->singleton(ContentSource::class, function () {
            return new ContentSource();
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
        $this->registerPublishables();
        $this->registerRoutes();
        $this->registerBladeComponents();
        $this->registerModelObservers();
        $this->registerRateLimiter();

        if ($this->app->runningInConsole()) {
            $this->registerCommands();
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
                __DIR__ . '/../config/scolta.php' => config_path('scolta.php'),
            ], 'scolta-config');

            // Migrations.
            $this->publishesMigrations([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'scolta-migrations');

            // Views (so users can override the Blade component).
            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/scolta'),
            ], 'scolta-views');

            // Frontend assets from scolta-core.
            // Resolve the scolta-core package path via ReflectionClass to avoid
            // hardcoding vendor paths — works in monorepo and standard installs.
            try {
                $coreRef = new ReflectionClass(\Tag1\Scolta\Config\ScoltaConfig::class);
                $corePath = dirname($coreRef->getFileName(), 3);
                $assetsPath = $corePath . '/assets';

                if (is_dir($assetsPath)) {
                    $this->publishes([
                        $assetsPath . '/js/scolta.js' => public_path('vendor/scolta/scolta.js'),
                        $assetsPath . '/css/scolta.css' => public_path('vendor/scolta/scolta.css'),
                    ], 'scolta-assets');
                }
            } catch (\ReflectionException $e) {
                // scolta-core not installed — skip asset publishing.
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
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }

    /**
     * Register the search Blade component.
     *
     * Usage: <x-scolta::search /> in any Blade template.
     * Users can override by publishing views to resources/views/vendor/scolta/.
     */
    private function registerBladeComponents(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'scolta');
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
            if (!class_exists($modelClass)) {
                continue;
            }

            // Validate that the model uses the Searchable trait.
            if (!in_array(Searchable::class, class_uses_recursive($modelClass), true)) {
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
            BuildCommand::class,
            StatusCommand::class,
            DownloadPagefindCommand::class,
        ]);
    }
}
