<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiProvider\Amazee\AmazeeClient;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\Scolta\Config\ScoltaConfig;
use Tag1\Scolta\Health\HealthChecker;
use Tag1\ScoltaLaravel\Cache\LaravelCacheDriver;

/**
 * Behavioral coverage for the Amazee key-expiry recovery wiring.
 *
 * Regression (django demo, 2026-06-09): an expired Amazee trial key returned
 * 400 expired_key while the adapter no-opped on the stored dead credentials —
 * expand echoed the query and /health still reported ai_configured: true.
 * ScoltaServiceProvider now wires scolta-php's KeyExpiryRecovery on the
 * auto-provisioned path, and HealthController hands the same cache to
 * HealthChecker.
 *
 * scolta-php's AiServiceAdapterTest proves the base recover-and-retry loop;
 * these tests prove the Laravel-specific wiring: the Illuminate cache bridge
 * satisfies KeyExpiryRecovery's marker contract, health stays truthful, and
 * the provider wires recovery only on the Amazee path.
 */
class KeyExpiryRecoveryWiringTest extends TestCase
{
    private const FRESH_TRIAL_RESPONSE = '{"key": {"litellm_token": "sk-fresh-token", "litellm_api_url": "https://llm.test.amazee.ai", "region": "test-region"}}';

    private const MODEL_INFO_RESPONSE = '{"data": [{"model_name": "claude-sonnet-4-5"}, {"model_name": "claude-haiku-4-5"}]}';

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
    // Recovery once-per-window through the Laravel cache bridge
    // -------------------------------------------------------------------

    public function test_recovery_reprovisions_once_per_window_through_laravel_bridge(): void
    {
        $storage = new InMemoryRecoveryStorage(self::EXPIRED_CREDS);
        $mock = new MockHandler([
            new Response(200, [], self::FRESH_TRIAL_RESPONSE),
            new Response(200, [], self::MODEL_INFO_RESPONSE),
        ]);
        $recovery = new KeyExpiryRecovery(
            storage: $storage,
            cache: new LaravelCacheDriver,
            client: $this->makeAmazeeClient($mock),
        );

        $first = $recovery->handleAuthFailure(new \RuntimeException('code: expired_key'));

        $this->assertTrue($first, 'An expired key triggers a re-provision');
        $this->assertSame('sk-fresh-token', $recovery->credentials()['litellm_token'], 'Fresh credentials stored');
        $this->assertFalse($recovery->isAuthFailing(), 'Successful recovery clears the marker via the Laravel cache');
        $this->assertSame(0, $mock->count(), 'Both provisioning calls (trial + models) ran');

        // A second failure inside the window must not hit the provisioning API
        // again — the MockHandler queue is empty, so any call would throw.
        $second = $recovery->handleAuthFailure(new \RuntimeException('code: expired_key'));
        $this->assertFalse($second, 'The window guard (read through the Laravel cache) blocks a second attempt');
    }

    public function test_record_auth_failure_visible_through_laravel_bridge(): void
    {
        $recovery = new KeyExpiryRecovery(new InMemoryRecoveryStorage(self::EXPIRED_CREDS), new LaravelCacheDriver);

        $this->assertFalse($recovery->isAuthFailing());

        $recovery->recordAuthFailure();

        $this->assertTrue($recovery->isAuthFailing(), 'Marker round-trips through the Laravel cache store');
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
        $this->assertFalse($result['ai_usable'], 'Known-expired credentials must not report usable');
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

    /**
     * Build an AmazeeClient driven by the given MockHandler queue.
     */
    private function makeAmazeeClient(MockHandler $mock): AmazeeClient
    {
        return new AmazeeClient(
            'https://api.amazee.ai',
            new Client(['handler' => HandlerStack::create($mock)]),
        );
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
