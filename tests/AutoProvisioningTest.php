<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;

/**
 * Verifies the Amazee.ai auto-provisioning wired into ScoltaServiceProvider.
 *
 * File-inspection and reflection tests — no live DB or HTTP calls.
 */
class AutoProvisioningTest extends TestCase
{
    private string $providerSource;

    protected function setUp(): void
    {
        $this->providerSource = file_get_contents(
            dirname(__DIR__).'/src/ScoltaServiceProvider.php'
        );
    }

    // -------------------------------------------------------------------
    // AutoProvisioner import.
    // -------------------------------------------------------------------

    public function test_imports_auto_provisioner(): void
    {
        $this->assertStringContainsString(
            'use Tag1\Scolta\AiProvider\Amazee\AutoProvisioner',
            $this->providerSource,
            'ScoltaServiceProvider must import AutoProvisioner'
        );
    }

    // -------------------------------------------------------------------
    // Provisioning is deferred to the first AI request — NOT run in boot().
    // -------------------------------------------------------------------

    public function test_provisioning_is_called_from_singleton_factory(): void
    {
        $this->assertStringContainsString(
            'attemptAmazeeAutoProvisioning()',
            $this->providerSource,
            'The ScoltaAiService singleton factory must call $this->attemptAmazeeAutoProvisioning()'
        );
    }

    public function test_boot_does_not_call_attempt_auto_provisioning(): void
    {
        // Extract the boot() method body and assert provisioning is not
        // invoked there — a blocking provisioning HTTP call inside provider
        // boot runs on every user-facing request before any AI is needed.
        preg_match(
            '/public function boot\(\): void\s*\{(.*?)\n    \}/s',
            $this->providerSource,
            $matches
        );

        $this->assertNotEmpty($matches[1] ?? '', 'Could not locate boot() body in ScoltaServiceProvider.');
        $this->assertStringNotContainsString(
            'attemptAmazeeAutoProvisioning()',
            $matches[1],
            'boot() must not call attemptAmazeeAutoProvisioning() — provisioning is deferred to the first AI request.'
        );
    }

    // -------------------------------------------------------------------
    // attemptAmazeeAutoProvisioning() method structure.
    // -------------------------------------------------------------------

    public function test_attempt_auto_provisioning_method_exists(): void
    {
        $ref = new ReflectionClass(ScoltaServiceProvider::class);
        $this->assertTrue(
            $ref->hasMethod('attemptAmazeeAutoProvisioning'),
            'ScoltaServiceProvider must have an attemptAmazeeAutoProvisioning() method'
        );
    }

    public function test_attempt_auto_provisioning_is_private(): void
    {
        $ref = new ReflectionClass(ScoltaServiceProvider::class);
        $method = $ref->getMethod('attemptAmazeeAutoProvisioning');
        $this->assertTrue(
            $method->isPrivate(),
            'attemptAmazeeAutoProvisioning() must be private'
        );
    }

    public function test_uses_cache_guard_key(): void
    {
        $this->assertStringContainsString(
            'scolta_amazee_provisioned',
            $this->providerSource,
            'attemptAmazeeAutoProvisioning() must use a cache key to avoid re-running on every boot'
        );
    }

    public function test_checks_explicit_api_key(): void
    {
        $this->assertStringContainsString(
            "config('scolta.ai_api_key'",
            $this->providerSource,
            'attemptAmazeeAutoProvisioning() must read scolta.ai_api_key to detect explicit API keys'
        );
    }

    public function test_calls_auto_provisioner_ensure_ai_available(): void
    {
        $this->assertStringContainsString(
            'AutoProvisioner::ensureAiAvailable(',
            $this->providerSource,
            'attemptAmazeeAutoProvisioning() must call AutoProvisioner::ensureAiAvailable()'
        );
    }

    public function test_on_models_resolved_calls_store_models(): void
    {
        $this->assertStringContainsString(
            'storeModels(',
            $this->providerSource,
            'onModelsResolved callback must call $storage->storeModels() to persist model choices'
        );
    }

    public function test_uses_laravel_config_storage(): void
    {
        $this->assertStringContainsString(
            'new LaravelConfigStorage',
            $this->providerSource,
            'attemptAmazeeAutoProvisioning() must use LaravelConfigStorage'
        );
    }

    public function test_wraps_db_call_in_try_catch(): void
    {
        $this->assertStringContainsString(
            'catch (\Exception $e)',
            $this->providerSource,
            'attemptAmazeeAutoProvisioning() must catch DB exceptions (DB not migrated)'
        );
    }

    public function test_singleton_guards_amazee_with_explicit_key(): void
    {
        // Regression: the singleton in register() must check for an explicit API
        // key before loading Amazee credentials so users who configured their own
        // key are never silently rerouted through the Amazee LiteLLM proxy.
        $this->assertStringContainsString(
            "\$explicitKey = \$config['ai_api_key']",
            $this->providerSource,
            "register() singleton must read \$config['ai_api_key'] before checking for Amazee credentials"
        );
    }

    public function test_singleton_skips_amazee_when_explicit_key_set(): void
    {
        $this->assertStringContainsString(
            'if ($explicitKey === \'\') {',
            $this->providerSource,
            'register() singleton must only load Amazee credentials when $explicitKey is empty'
        );
    }
}
