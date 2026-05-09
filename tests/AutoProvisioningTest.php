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
    // boot() calls attemptAmazeeAutoProvisioning().
    // -------------------------------------------------------------------

    public function test_boot_calls_attempt_auto_provisioning(): void
    {
        $this->assertStringContainsString(
            'attemptAmazeeAutoProvisioning()',
            $this->providerSource,
            'boot() must call $this->attemptAmazeeAutoProvisioning()'
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
        $ref    = new ReflectionClass(ScoltaServiceProvider::class);
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
            '} catch (\Exception) {',
            $this->providerSource,
            'attemptAmazeeAutoProvisioning() must catch DB exceptions (DB not migrated)'
        );
    }

}
