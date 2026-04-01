<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Tag1\ScoltaLaravel\Commands\BuildCommand;
use Tag1\ScoltaLaravel\Commands\StatusCommand;
use Tag1\ScoltaLaravel\Commands\DownloadPagefindCommand;
use Tag1\ScoltaLaravel\Observers\ScoltaObserver;
use Tag1\ScoltaLaravel\Services\ContentSource;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * Scolta service provider.
 *
 * Wires up config, routes, views, commands, migrations, model observers,
 * asset publishing, and rate limiting. Auto-discovery means users just
 * run `composer require` and it works.
 */
class ScoltaServiceProvider extends ServiceProvider
{
    /**
     * Register bindings in the container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/scolta.php', 'scolta');

        $this->app->singleton(ScoltaAiService::class, function ($app) {
            return new ScoltaAiService($app['config']['scolta']);
        });

        $this->app->singleton(ContentSource::class);
    }

    /**
     * Bootstrap package services.
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
     */
    private function registerPublishables(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/scolta.php' => config_path('scolta.php'),
            ], 'scolta-config');

            $this->publishesMigrations([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'scolta-migrations');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/scolta'),
            ], 'scolta-views');

            // Publish JS/CSS assets from scolta-core.
            $coreAssetsPath = $this->resolveScoltaCoreAssetsPath();
            if ($coreAssetsPath !== null) {
                $this->publishes([
                    $coreAssetsPath . '/js/scolta.js' => public_path('vendor/scolta/scolta.js'),
                    $coreAssetsPath . '/css/scolta.css' => public_path('vendor/scolta/scolta.css'),
                ], 'scolta-assets');
            }
        }
    }

    /**
     * Resolve the path to scolta-core's assets directory.
     */
    private function resolveScoltaCoreAssetsPath(): ?string
    {
        // Find the scolta-core package via its class location.
        if (!class_exists(\Tag1\Scolta\Config\ScoltaConfig::class)) {
            return null;
        }

        $reflection = new \ReflectionClass(\Tag1\Scolta\Config\ScoltaConfig::class);
        // ScoltaConfig lives at <package-root>/src/Config/ScoltaConfig.php
        // — go up 3 levels to reach the package root where /assets/ lives.
        $packageRoot = dirname($reflection->getFileName(), 3);
        $assetsPath = $packageRoot . '/assets';

        return is_dir($assetsPath) ? $assetsPath : null;
    }

    /**
     * Load the API routes.
     */
    private function registerRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
    }

    /**
     * Register the search Blade component.
     */
    private function registerBladeComponents(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'scolta');
    }

    /**
     * Attach the change-tracking observer to configured models.
     *
     * Validates that each model exists and uses the Searchable trait.
     */
    private function registerModelObservers(): void
    {
        $models = config('scolta.models', []);

        foreach ($models as $modelClass) {
            if (!class_exists($modelClass)) {
                logger()->warning("[scolta] Model class not found: {$modelClass}. Check config/scolta.php 'models' array.");
                continue;
            }

            if (!in_array(Searchable::class, class_uses_recursive($modelClass))) {
                logger()->warning("[scolta] Model {$modelClass} does not use the Searchable trait. It will not be indexed.");
                continue;
            }

            $modelClass::observe(ScoltaObserver::class);
        }
    }

    /**
     * Register a rate limiter for the AI endpoints.
     */
    private function registerRateLimiter(): void
    {
        $rateLimit = config('scolta.rate_limit', 30);

        if ($rateLimit > 0) {
            RateLimiter::for('scolta', function ($request) use ($rateLimit) {
                return Limit::perMinute($rateLimit)->by($request->ip());
            });
        }
    }

    /**
     * Register Artisan commands.
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
