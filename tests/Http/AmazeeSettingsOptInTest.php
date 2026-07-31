<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\ScoltaLaravel\AiProvider\Amazee\LaravelConfigStorage;
use Tag1\ScoltaLaravel\Cache\LaravelCacheDriver;
use Tag1\ScoltaLaravel\Commands\AmazeeProvisionCommand;
use Tag1\ScoltaLaravel\Http\Controllers\AmazeeSettingsController;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;

/**
 * The settings page is an explicit enable surface, and an explicit off switch.
 *
 * It is one of the two ways the managed Amazee.ai gateway is turned on (the
 * other is `artisan scolta:amazee:provision`), and the way an operator turns it
 * back off. Switching off clears the stored connection together with both
 * recovery markers, so `/health` and this page stop describing a connection the
 * site no longer has.
 */
class AmazeeSettingsOptInTest extends TestCase
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
        $app['config']->set('session.driver', 'array');
        $app['config']->set('scolta.models', []);
        // The settings routes are only registered with middleware beyond the
        // bare ['web'] group; the view resolves route() names from them.
        $app['config']->set('scolta.amazee_middleware', ['web', 'auth']);
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

    private function request(string $method = 'GET'): Request
    {
        $request = Request::create('/scolta/amazee', $method);
        $request->setLaravelSession($this->app['session']->driver());

        return $request;
    }

    private function recovery(): KeyExpiryRecovery
    {
        return new KeyExpiryRecovery(new LaravelConfigStorage, new LaravelCacheDriver);
    }

    // -------------------------------------------------------------------
    // Switching off clears the connection and the markers with it.
    // -------------------------------------------------------------------

    public function test_switching_off_clears_the_stored_connection_and_markers(): void
    {
        $storage = new LaravelConfigStorage;
        $storage->store(...array_values(self::CREDS));

        $recovery = $this->recovery();
        $recovery->flagUpgradeNeeded();
        $recovery->recordAuthFailure();

        $response = (new AmazeeSettingsController)->disconnect($this->request('DELETE'));

        $this->assertSame(['step' => 'start'], $response->getData(true));
        $this->assertNull($storage->load(), 'The stored connection must be gone');
        $this->assertFalse(
            $recovery->isUpgradeNeeded(),
            'A reconnect prompt for a connection the operator ended is noise'
        );
        $this->assertFalse(
            $recovery->isAuthFailing(),
            'The auth-failure marker describes credentials that no longer exist'
        );
    }

    // -------------------------------------------------------------------
    // The page offers the connection in plain terms.
    // -------------------------------------------------------------------

    public function test_settings_page_states_the_offer(): void
    {
        $html = (new AmazeeSettingsController)->show($this->request())->render();

        $this->assertStringContainsString(AmazeeProvisionCommand::OFFER_LINE, $html);
    }

    public function test_settings_page_starts_at_the_enable_step_when_nothing_is_stored(): void
    {
        $view = (new AmazeeSettingsController)->show($this->request());

        $this->assertFalse($view->getData()['connected']);
        $this->assertSame('start', $view->getData()['step'], 'Nothing is connected until an operator connects it');
    }

    public function test_settings_page_reports_a_stored_connection(): void
    {
        (new LaravelConfigStorage)->store(...array_values(self::CREDS));

        $view = (new AmazeeSettingsController)->show($this->request());

        $this->assertTrue($view->getData()['connected']);
        $this->assertSame('connected', $view->getData()['step']);
        $this->assertSame('test-region', $view->getData()['region']);
    }

    public function test_settings_page_defers_to_an_explicit_key(): void
    {
        config(['scolta.ai_api_key' => 'sk-operators-own-key']);

        $view = (new AmazeeSettingsController)->show($this->request());

        $this->assertTrue($view->getData()['hasExistingProvider']);
        $this->assertSame('provider-configured', $view->getData()['step']);
    }
}
