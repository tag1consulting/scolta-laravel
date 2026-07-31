<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\Scolta\Exception\ApiKeyMissingException;
use Tag1\ScoltaLaravel\AiProvider\Amazee\LaravelConfigStorage;
use Tag1\ScoltaLaravel\Cache\LaravelCacheDriver;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * Resolving the AI service reads the connection; it never creates one.
 *
 * The managed Amazee.ai gateway is enabled explicitly, through the settings
 * page or `artisan scolta:amazee:provision`. These cases boot the package
 * against a migrated database and resolve ScoltaAiService the way an AI
 * endpoint does, asserting what comes out of the container in each state:
 *
 *   - nothing configured and nothing stored → a key-less client, so the AI
 *     endpoints return an unexpanded, no-summary HTTP 200, and the credential
 *     store is still empty afterwards;
 *   - a stored connection → a client driving the gateway with it;
 *   - an explicit key → the stored connection and both recovery markers are
 *     cleared, so no leftover state can shadow the key.
 */
class AiServiceOptInResolutionTest extends TestCase
{
    private const CREDS = [
        'litellm_token' => 'sk-stored-token',
        'litellm_api_url' => 'https://llm.test.amazee.ai',
        'region' => 'test-region',
    ];

    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('scolta.models', []);
        $app['config']->set('scolta.ai_api_key', '');
    }

    protected function defineDatabaseMigrations(): void
    {
        // The package's own migrations, so the credential store is real: an
        // enable attempt would leave a row behind, and its absence is the
        // assertion.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    // -------------------------------------------------------------------
    // Nothing configured, nothing stored: degrade, and store nothing.
    // -------------------------------------------------------------------

    public function test_resolving_without_a_key_or_a_stored_connection_stores_nothing(): void
    {
        $this->assertSame(0, DB::table('scolta_config')->count(), 'Precondition: nothing stored');

        $service = $this->app->make(ScoltaAiService::class);

        $this->assertFalse(
            $service->isAmazeeActive(),
            'Resolving the AI service must not put the site on the managed gateway'
        );
        $this->assertSame(
            0,
            DB::table('scolta_config')->count(),
            'Resolving the AI service must not create a connection — only an operator action does that'
        );
        $this->assertNull(
            (new LaravelConfigStorage)->load(),
            'No credentials may exist after a plain resolution'
        );
    }

    public function test_resolving_without_a_key_yields_a_degrading_client(): void
    {
        $service = $this->app->make(ScoltaAiService::class);

        // A key-less client throws before any HTTP call. The AI controllers
        // catch this and return an unexpanded, no-summary HTTP 200.
        $this->expectException(ApiKeyMissingException::class);
        $service->message('system', 'user');
    }

    // -------------------------------------------------------------------
    // A stored connection is used.
    // -------------------------------------------------------------------

    public function test_a_stored_connection_drives_the_gateway(): void
    {
        $storage = new LaravelConfigStorage;
        $storage->store(...array_values(self::CREDS));
        $storage->storeModels('claude-sonnet-4-5', 'claude-haiku-4-5');

        $service = $this->app->make(ScoltaAiService::class);

        $this->assertTrue($service->isAmazeeActive());
        $this->assertSame(self::CREDS['litellm_token'], $service->getConfig()->aiApiKey);
        $this->assertSame(self::CREDS['litellm_api_url'], $service->getConfig()->aiBaseUrl);
        $this->assertSame('claude-sonnet-4-5', $service->getConfig()->aiModel);
    }

    public function test_a_stored_connection_without_resolved_models_degrades(): void
    {
        // Credentials stored while model resolution failed. Sending the gateway
        // the shipped dated default gets an HTTP 400, so no key is injected and
        // the client degrades instead; re-running an explicit enable resolves
        // the models.
        (new LaravelConfigStorage)->store(...array_values(self::CREDS));

        $service = $this->app->make(ScoltaAiService::class);

        $this->assertTrue($service->isAmazeeActive(), 'A stored connection is still the active path for health');
        $this->assertSame('', $service->getConfig()->aiApiKey, 'No key may be injected without a resolved model');
    }

    // -------------------------------------------------------------------
    // An explicit key supersedes and clears the stored connection.
    // -------------------------------------------------------------------

    public function test_an_explicit_key_clears_the_stored_connection_and_markers(): void
    {
        $storage = new LaravelConfigStorage;
        $storage->store(...array_values(self::CREDS));
        $storage->storeModels('claude-sonnet-4-5', 'claude-haiku-4-5');

        $recovery = new KeyExpiryRecovery($storage, new LaravelCacheDriver);
        $recovery->flagUpgradeNeeded();
        $recovery->recordAuthFailure();

        config(['scolta.ai_api_key' => 'sk-operators-own-key']);

        $service = $this->app->make(ScoltaAiService::class);

        $this->assertFalse($service->isAmazeeActive(), 'The explicit key wins');
        $this->assertSame('sk-operators-own-key', $service->getConfig()->aiApiKey);
        $this->assertNull($storage->load(), 'The superseded connection must be cleared');
        $this->assertFalse($recovery->isUpgradeNeeded(), 'The re-authentication marker must be cleared with it');
        $this->assertFalse($recovery->isAuthFailing(), 'The auth-failure marker must be cleared with it');
    }

    public function test_an_explicit_key_with_nothing_stored_is_a_no_op(): void
    {
        config(['scolta.ai_api_key' => 'sk-operators-own-key']);

        $service = $this->app->make(ScoltaAiService::class);

        $this->assertFalse($service->isAmazeeActive());
        $this->assertSame('sk-operators-own-key', $service->getConfig()->aiApiKey);
        $this->assertSame(0, DB::table('scolta_config')->count());
    }
}
