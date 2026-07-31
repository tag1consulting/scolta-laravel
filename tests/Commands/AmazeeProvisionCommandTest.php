<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Commands;

use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;
use Tag1\ScoltaLaravel\AiProvider\Amazee\LaravelConfigStorage;
use Tag1\ScoltaLaravel\Commands\AmazeeProvisionCommand;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;

/**
 * `scolta:amazee:provision` is the CLI enable surface.
 *
 * It is one of the two ways the managed Amazee.ai gateway is turned on, and
 * the only one on a host with no browser session. Running it is the operator
 * action; these cases cover what it does before it reaches the network — state
 * the offer, refuse a malformed address, and stand aside when the operator
 * already has their own provider configured.
 */
class AmazeeProvisionCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('scolta.models', []);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_command_states_the_offer(): void
    {
        // An already-configured provider stops the command before any network
        // call, which is what keeps this case offline.
        config(['scolta.ai_api_key' => 'sk-operators-own-key']);

        $this->artisan('scolta:amazee:provision', ['email' => 'operator@example.test'])
            ->expectsOutputToContain(AmazeeProvisionCommand::OFFER_LINE)
            ->assertExitCode(0);
    }

    public function test_command_stands_aside_for_an_explicitly_configured_provider(): void
    {
        config(['scolta.ai_api_key' => 'sk-operators-own-key']);

        $this->artisan('scolta:amazee:provision', ['email' => 'operator@example.test'])
            ->expectsOutputToContain('AI provider already configured')
            ->assertExitCode(0);

        $this->assertNull(
            (new LaravelConfigStorage)->load(),
            'No connection may be established while the operator has their own key'
        );
    }

    public function test_command_rejects_a_malformed_address(): void
    {
        $this->artisan('scolta:amazee:provision', ['email' => 'not-an-email'])
            ->expectsOutputToContain('Invalid email address')
            ->assertExitCode(1);

        $this->assertNull((new LaravelConfigStorage)->load());
    }
}
