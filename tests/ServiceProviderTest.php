<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;

/**
 * Test ScoltaServiceProvider: structure, methods, published assets, commands.
 */
class ServiceProviderTest extends TestCase
{
    private string $providerSource;

    protected function setUp(): void
    {
        $this->providerSource = file_get_contents(
            dirname(__DIR__).'/src/ScoltaServiceProvider.php'
        );
    }

    // -------------------------------------------------------------------
    // Provider class exists and extends ServiceProvider
    // -------------------------------------------------------------------

    public function test_provider_class_exists(): void
    {
        $this->assertTrue(class_exists(ScoltaServiceProvider::class),
            'ScoltaServiceProvider class should exist.');
    }

    public function test_provider_extends_service_provider(): void
    {
        $ref = new ReflectionClass(ScoltaServiceProvider::class);
        $this->assertTrue(
            $ref->isSubclassOf(\Illuminate\Support\ServiceProvider::class),
            'ScoltaServiceProvider should extend Illuminate\\Support\\ServiceProvider.'
        );
    }

    // -------------------------------------------------------------------
    // register() and boot() methods
    // -------------------------------------------------------------------

    public function test_register_method_exists(): void
    {
        $ref = new ReflectionClass(ScoltaServiceProvider::class);
        $this->assertTrue($ref->hasMethod('register'),
            'ScoltaServiceProvider should have a register() method.');
    }

    public function test_boot_method_exists(): void
    {
        $ref = new ReflectionClass(ScoltaServiceProvider::class);
        $this->assertTrue($ref->hasMethod('boot'),
            'ScoltaServiceProvider should have a boot() method.');
    }

    public function test_register_is_public(): void
    {
        $method = $ref = new \ReflectionMethod(ScoltaServiceProvider::class, 'register');
        $this->assertTrue($method->isPublic(), 'register() should be public.');
    }

    public function test_boot_is_public(): void
    {
        $method = new \ReflectionMethod(ScoltaServiceProvider::class, 'boot');
        $this->assertTrue($method->isPublic(), 'boot() should be public.');
    }

    // -------------------------------------------------------------------
    // All 7 commands are in the commands array
    // -------------------------------------------------------------------

    public function test_registers_build_command(): void
    {
        $this->assertStringContainsString('BuildCommand::class', $this->providerSource);
    }

    public function test_registers_export_command(): void
    {
        $this->assertStringContainsString('ExportCommand::class', $this->providerSource);
    }

    public function test_registers_rebuild_index_command(): void
    {
        $this->assertStringContainsString('RebuildIndexCommand::class', $this->providerSource);
    }

    public function test_registers_status_command(): void
    {
        $this->assertStringContainsString('StatusCommand::class', $this->providerSource);
    }

    public function test_registers_clear_cache_command(): void
    {
        $this->assertStringContainsString('ClearCacheCommand::class', $this->providerSource);
    }

    public function test_registers_download_pagefind_command(): void
    {
        $this->assertStringContainsString('DownloadPagefindCommand::class', $this->providerSource);
    }

    public function test_registers_check_setup_command(): void
    {
        $this->assertStringContainsString('CheckSetupCommand::class', $this->providerSource);
    }

    // -------------------------------------------------------------------
    // Published config
    // -------------------------------------------------------------------

    public function test_publishes_config(): void
    {
        $this->assertStringContainsString("'scolta-config'", $this->providerSource,
            'Provider should publish config with scolta-config tag.');
    }

    public function test_merges_config(): void
    {
        $this->assertStringContainsString('mergeConfigFrom', $this->providerSource,
            'Provider should merge default config.');
    }

    public function test_publishes_config_file(): void
    {
        $this->assertStringContainsString('config/scolta.php', $this->providerSource,
            'Provider should reference config/scolta.php for publishing.');
    }

    // -------------------------------------------------------------------
    // Published migrations
    // -------------------------------------------------------------------

    public function test_publishes_migrations(): void
    {
        $this->assertStringContainsString("'scolta-migrations'", $this->providerSource,
            'Provider should publish migrations with scolta-migrations tag.');
    }

    public function test_publishes_migrations_directory(): void
    {
        $this->assertStringContainsString('database/migrations', $this->providerSource,
            'Provider should reference database/migrations for publishing.');
    }

    // -------------------------------------------------------------------
    // Published assets
    // -------------------------------------------------------------------

    public function test_publishes_assets(): void
    {
        $this->assertStringContainsString("'scolta-assets'", $this->providerSource,
            'Provider should publish assets with scolta-assets tag.');
    }

    public function test_publishes_views(): void
    {
        $this->assertStringContainsString("'scolta-views'", $this->providerSource,
            'Provider should publish views with scolta-views tag.');
    }

    // -------------------------------------------------------------------
    // Model observer registration
    // -------------------------------------------------------------------

    public function test_registers_model_observers(): void
    {
        $this->assertStringContainsString('registerModelObservers', $this->providerSource,
            'Provider should call registerModelObservers().');
    }

    public function test_observer_registration_uses_scolta_observer(): void
    {
        $this->assertStringContainsString('ScoltaObserver::class', $this->providerSource,
            'Provider should reference ScoltaObserver for observation.');
    }

    public function test_observer_registration_checks_searchable_trait(): void
    {
        $this->assertStringContainsString('Searchable::class', $this->providerSource,
            'Observer registration should validate Searchable trait usage.');
    }

    public function test_observer_registration_reads_config_models(): void
    {
        $this->assertStringContainsString("config('scolta.models'", $this->providerSource,
            'Observer registration should read models from config.');
    }

    // -------------------------------------------------------------------
    // Rate limiter registration
    // -------------------------------------------------------------------

    public function test_registers_rate_limiter(): void
    {
        $this->assertStringContainsString('registerRateLimiter', $this->providerSource,
            'Provider should call registerRateLimiter().');
    }

    public function test_rate_limiter_uses_rate_limiter_facade(): void
    {
        $this->assertStringContainsString('RateLimiter::for', $this->providerSource,
            'Rate limiter should use RateLimiter::for().');
    }

    public function test_rate_limiter_uses_scolta_key(): void
    {
        $this->assertStringContainsString("'scolta'", $this->providerSource,
            'Rate limiter should use "scolta" as the limiter key.');
    }

    // -------------------------------------------------------------------
    // Blade component registration
    // -------------------------------------------------------------------

    public function test_registers_blade_components(): void
    {
        $this->assertStringContainsString('registerBladeComponents', $this->providerSource,
            'Provider should call registerBladeComponents().');
    }

    public function test_loads_views_from_package(): void
    {
        $this->assertStringContainsString('loadViewsFrom', $this->providerSource,
            'Provider should load views from the package.');
    }

    // -------------------------------------------------------------------
    // Route registration
    // -------------------------------------------------------------------

    public function test_registers_routes(): void
    {
        $this->assertStringContainsString('registerRoutes', $this->providerSource,
            'Provider should call registerRoutes().');
    }

    public function test_loads_routes_from_file(): void
    {
        $this->assertStringContainsString('loadRoutesFrom', $this->providerSource,
            'Provider should load routes from a file.');
    }

    // -------------------------------------------------------------------
    // Singleton bindings
    // -------------------------------------------------------------------

    public function test_binds_ai_service_as_singleton(): void
    {
        $this->assertStringContainsString('ScoltaAiService::class', $this->providerSource,
            'Provider should bind ScoltaAiService.');
        $this->assertStringContainsString('singleton', $this->providerSource,
            'Provider should use singleton bindings.');
    }

    public function test_binds_content_source_as_singleton(): void
    {
        $this->assertStringContainsString('ContentSource::class', $this->providerSource,
            'Provider should bind ContentSource.');
    }

    // -------------------------------------------------------------------
    // Commands only loaded in console
    // -------------------------------------------------------------------

    public function test_commands_conditional_on_console(): void
    {
        $this->assertStringContainsString('runningInConsole', $this->providerSource,
            'Commands should be conditionally loaded when running in console.');
    }
}
