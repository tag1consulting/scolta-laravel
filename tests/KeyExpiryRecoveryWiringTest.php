<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Health\HealthChecker;
use Tag1\ScoltaLaravel\Cache\LaravelCacheDriver;

/**
 * Behavioral coverage for the Amazee credential auth-failure wiring.
 *
 * Regression (django demo, 2026-06-09): a no-longer-accepted Amazee key
 * returned an auth error while the adapter no-opped on the stored dead
 * credentials — expand echoed the query and /health still reported
 * ai_configured: true. The current contract detects the auth failure and
 * degrades cleanly: it records a health marker, raises a persistent
 * re-authentication signal that admin surfaces read, and leaves the stored
 * credentials untouched. It never re-requests credentials on this path;
 * reconnection is an explicit, operator-initiated step.
 *
 * ScoltaServiceProvider wires scolta-php's KeyExpiryRecovery on the
 * auto-provisioned path, and HealthController hands the same cache to
 * HealthChecker. These tests prove the Laravel-specific wiring: the Illuminate
 * cache bridge satisfies the marker contract, health stays truthful, the
 * upgrade signal round-trips, and the provider wires recovery only on the
 * Amazee path.
 */
class KeyExpiryRecoveryWiringTest extends TestCase
{
    private const EXPIRED_CREDS = [
        'litellm_token' => 'sk-expired-token',
        'litellm_api_url' => 'https://llm.test.amazee.ai',
        'region' => 'test-region',
    ];

    protected function setUp(): void
    {
        $app = new Application(dirname(__DIR__));
        Container::setInstance($app);
        // In-memory cache so tests run without a real database or Redis.
        Cache::swap(new Repository(new ArrayStore));
    }

    protected function tearDown(): void
    {
        Cache::clearResolvedInstances();
        Container::setInstance(null);
    }

    // -------------------------------------------------------------------
    // Auth failure degrades and raises the upgrade signal — no new creds
    // -------------------------------------------------------------------

    public function test_auth_failure_degrades_and_flags_upgrade_without_reissuing_credentials(): void
    {
        $storage = new InMemoryRecoveryStorage(self::EXPIRED_CREDS);
        $recovery = new KeyExpiryRecovery($storage, new LaravelCacheDriver);

        $handled = $recovery->handleAuthFailure(new \RuntimeException('code: expired_key'));

        $this->assertFalse($handled, 'There is nothing to retry — the caller degrades gracefully');
        $this->assertTrue($recovery->isAuthFailing(), 'Health marker is recorded via the Laravel cache');
        $this->assertTrue($recovery->isUpgradeNeeded(), 'The persistent re-authentication signal is raised');
        $this->assertSame(
            'sk-expired-token',
            $recovery->credentials()['litellm_token'],
            'Stored credentials are left untouched — none are re-requested on this path'
        );
    }

    public function test_non_auth_failure_is_ignored(): void
    {
        $recovery = new KeyExpiryRecovery(new InMemoryRecoveryStorage(self::EXPIRED_CREDS), new LaravelCacheDriver);

        $handled = $recovery->handleAuthFailure(new \RuntimeException('connection timed out'));

        $this->assertFalse($handled);
        $this->assertFalse($recovery->isAuthFailing(), 'A transient network error is not an auth failure');
        $this->assertFalse($recovery->isUpgradeNeeded());
    }

    public function test_record_auth_failure_visible_through_laravel_bridge(): void
    {
        $recovery = new KeyExpiryRecovery(new InMemoryRecoveryStorage(self::EXPIRED_CREDS), new LaravelCacheDriver);

        $this->assertFalse($recovery->isAuthFailing());

        $recovery->recordAuthFailure();

        $this->assertTrue($recovery->isAuthFailing(), 'Marker round-trips through the Laravel cache store');
    }

    public function test_upgrade_signal_round_trips_and_clears_through_laravel_bridge(): void
    {
        $recovery = new KeyExpiryRecovery(new InMemoryRecoveryStorage(self::EXPIRED_CREDS), new LaravelCacheDriver);

        $this->assertFalse($recovery->isUpgradeNeeded());

        $recovery->flagUpgradeNeeded();
        $this->assertTrue($recovery->isUpgradeNeeded(), 'The re-authentication signal round-trips through the cache');

        // A successful reconnect clears it — the state admin surfaces read.
        $recovery->clearUpgradeNeeded();
        $this->assertFalse($recovery->isUpgradeNeeded(), 'Clearing the signal after reconnect is visible immediately');
    }

    // -------------------------------------------------------------------
    // Health truthfulness through the Laravel cache bridge
    // -------------------------------------------------------------------

    public function test_health_reports_auth_failing_when_marker_set(): void
    {
        (new KeyExpiryRecovery(new InMemoryRecoveryStorage(self::EXPIRED_CREDS), new LaravelCacheDriver))->recordAuthFailure();

        $result = $this->runHealthCheck(new LaravelCacheDriver);

        $this->assertTrue($result['ai_configured'], 'Credentials are still present');
        $this->assertTrue($result['ai_auth_failing'], 'The recorded marker must surface');
        $this->assertFalse($result['ai_usable'], 'Known-bad credentials must not report usable');
        $this->assertSame('degraded', $result['status']);
    }

    public function test_health_reports_usable_when_no_marker(): void
    {
        $result = $this->runHealthCheck(new LaravelCacheDriver);

        $this->assertTrue($result['ai_configured']);
        $this->assertFalse($result['ai_auth_failing']);
        $this->assertTrue($result['ai_usable'], 'A configured, non-failing key is usable');
    }

    // -------------------------------------------------------------------
    // Structural: the wiring is present with the correct path gate
    // -------------------------------------------------------------------

    public function test_service_provider_wires_recovery_gated_on_amazee_path(): void
    {
        $src = $this->source('src/ScoltaServiceProvider.php');

        $this->assertStringContainsString('use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;', $src);
        $this->assertStringContainsString('$service->setKeyExpiryRecovery(', $src);
        // The wiring must sit inside the Amazee-path guard, after the
        // explicit-key branch leaves $amazeeActive false.
        $gatePos = strpos($src, 'if ($amazeeActive) {');
        $wirePos = strpos($src, '$service->setKeyExpiryRecovery(');
        $this->assertNotFalse($gatePos, 'Recovery must be guarded by if ($amazeeActive)');
        $this->assertNotFalse($wirePos);
        $this->assertGreaterThan($gatePos, $wirePos, 'setKeyExpiryRecovery must be inside the amazee-active guard');
    }

    public function test_health_controller_passes_cache_to_health_checker(): void
    {
        $src = $this->source('src/Http/Controllers/HealthController.php');

        $this->assertStringContainsString('use Tag1\ScoltaLaravel\Cache\LaravelCacheDriver;', $src);
        $this->assertStringContainsString('cache: new LaravelCacheDriver', $src);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function source(string $relativePath): string
    {
        return file_get_contents(dirname(__DIR__).'/'.$relativePath);
    }

    /**
     * Run a HealthChecker for a configured Amazee install with the cache.
     *
     * @return array<string, mixed>
     */
    private function runHealthCheck(LaravelCacheDriver $cache): array
    {
        $config = ScoltaConfig::fromArray([
            'ai_provider' => 'openai',
            'ai_api_key' => 'sk-amazee-litellm-token',
        ]);
        $checker = new HealthChecker(
            config: $config,
            indexOutputDir: sys_get_temp_dir(),
            pagefindBinaryPath: null,
            projectDir: null,
            cache: $cache,
        );

        return $checker->check();
    }
}

/**
 * Minimal in-memory ConfigStorageInterface for the recovery store.
 *
 * Avoids LaravelConfigStorage's DB + Crypt dependencies in plain PHPUnit.
 */
class InMemoryRecoveryStorage implements ConfigStorageInterface
{
    /** @param array{litellm_token: string, litellm_api_url: string, region: string}|null $stored */
    public function __construct(private ?array $stored = null) {}

    public function store(string $litellmToken, string $litellmApiUrl, string $region): void
    {
        $this->stored = [
            'litellm_token' => $litellmToken,
            'litellm_api_url' => $litellmApiUrl,
            'region' => $region,
        ];
    }

    public function load(): ?array
    {
        return $this->stored;
    }

    public function clear(): void
    {
        $this->stored = null;
    }
}
