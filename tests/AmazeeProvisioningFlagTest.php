<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;
use ReflectionMethod;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;

/**
 * Cache-flag behavior of Amazee.ai auto-provisioning.
 *
 * Regression: with SCOLTA_API_KEY set, the explicit-key branch never wrote
 * the 'scolta_amazee_provisioned' cache flag, so the provisioning check
 * (including its DB lookup) re-ran on every request. The flag must now be
 * cached for every settled outcome, and the unmigrated-DB path must defer
 * (and log once) without writing the settled flag.
 */
class AmazeeProvisioningFlagTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    private function invokeProvisioning(): void
    {
        $provider = new ScoltaServiceProvider($this->app);
        (new ReflectionMethod($provider, 'attemptAmazeeAutoProvisioning'))->invoke($provider);
    }

    public function test_explicit_api_key_caches_settled_flag(): void
    {
        config(['scolta.ai_api_key' => 'explicit-key']);

        $this->assertFalse(Cache::has('scolta_amazee_provisioned'));

        // With an explicit key, AutoProvisioner::ensureAiAvailable() is a
        // no-op (no HTTP, no DB) — but the outcome is settled, so the flag
        // must be cached to stop the check re-running on every request.
        $this->invokeProvisioning();

        $this->assertTrue(Cache::has('scolta_amazee_provisioned'),
            'The explicit-key branch must cache the settled flag.');
    }

    public function test_settled_flag_short_circuits_later_attempts(): void
    {
        config(['scolta.ai_api_key' => '']);
        Cache::put('scolta_amazee_provisioned', true, 3600);

        // With no explicit key and an unmigrated DB, a real attempt would
        // take the deferred path and write the deferred marker. The settled
        // flag must short-circuit before any storage access happens.
        $this->invokeProvisioning();

        $this->assertFalse(Cache::has('scolta_amazee_provision_deferred'),
            'A settled flag must short-circuit the attempt entirely.');
    }

    public function test_unmigrated_db_defers_without_settling(): void
    {
        config(['scolta.ai_api_key' => '']);

        // The testbench DB has no scolta tables, so the credential lookup
        // throws: the attempt must defer (log-once marker) and NOT settle,
        // so provisioning retries after the migration runs.
        $this->invokeProvisioning();

        $this->assertFalse(Cache::has('scolta_amazee_provisioned'),
            'An unmigrated DB must not settle the provisioning flag.');
        $this->assertTrue(Cache::has('scolta_amazee_provision_deferred'),
            'The unmigrated-DB path must record the log-once deferred marker.');
    }
}
