<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel;

use Illuminate\Support\ServiceProvider;
use Tag1\ScoltaLaravel\Commands\BuildCommand;
use Tag1\ScoltaLaravel\Commands\StatusCommand;
use Tag1\ScoltaLaravel\Commands\DownloadPagefindCommand;
use Tag1\ScoltaLaravel\Observers\ScoltaObserver;
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

        if ($this->app->runningInConsole()) {
            $this->registerCommands();
        }
    }

    /**
     * Publish config, migrations, and views.
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
            if (class_exists($modelClass)) {
                $modelClass::observe(ScoltaObserver::class);
            }
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
