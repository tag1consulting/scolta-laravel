<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Orchestra\Testbench\TestCase;
use ReflectionMethod;
use Tag1\Scolta\AiProvider\Amazee\KeyExpiryRecovery;
use Tag1\ScoltaLaravel\AiProvider\Amazee\LaravelConfigStorage;
use Tag1\ScoltaLaravel\Cache\LaravelCacheDriver;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * The re-authentication prompt is surfaced on every Laravel surface it can be.
 *
 * When the stored Amazee.ai credentials are no longer accepted, scolta-php's
 * KeyExpiryRecovery raises a persistent signal (isUpgradeNeeded). This adapter
 * has no full CMS admin, so it surfaces that signal wherever it reasonably can
 * — the settings page, the status command, and an operator log line — so the
 * degraded state is not swallowed. These tests boot the package and assert the
 * prompt appears when the signal is set and is absent otherwise.
 */
class AmazeeReauthPromptTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('cache.default', 'array');
        // Registering the Amazee settings routes (needed for the view's
        // route() calls) requires middleware beyond the bare ['web'] group.
        $app['config']->set('scolta.amazee_middleware', ['web', 'auth']);
        $app['config']->set('scolta.models', []);
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    private function flagUpgradeNeeded(): void
    {
        (new KeyExpiryRecovery(new LaravelConfigStorage, new LaravelCacheDriver))->flagUpgradeNeeded();
    }

    // -------------------------------------------------------------------
    // Settings view
    // -------------------------------------------------------------------

    public function test_settings_view_renders_reconnect_banner_when_signal_set(): void
    {
        $html = View::make('scolta::amazee-settings', [
            'step' => 'connected',
            'email' => '',
            'region' => 'test-region',
            'upgradeNeeded' => true,
        ])->render();

        $this->assertStringContainsString('needs to be re-authenticated', $html);
        $this->assertStringContainsString('Continue with Amazee.ai', $html);
        $this->assertStringContainsString('continueWithAmazee()', $html);
    }

    public function test_settings_view_omits_banner_when_signal_absent(): void
    {
        $html = View::make('scolta::amazee-settings', [
            'step' => 'connected',
            'email' => '',
            'region' => 'test-region',
            'upgradeNeeded' => false,
        ])->render();

        $this->assertStringNotContainsString('needs to be re-authenticated', $html);
        $this->assertStringNotContainsString('Continue with Amazee.ai', $html);
    }

    // -------------------------------------------------------------------
    // Status command
    // -------------------------------------------------------------------

    public function test_status_command_reports_reauthentication_state_and_path(): void
    {
        $this->app->instance(ScoltaAiService::class, new ScoltaAiService([], amazeeActive: true));
        $this->flagUpgradeNeeded();

        $this->artisan('scolta:status')
            ->expectsOutputToContain('NEEDS RE-AUTHENTICATION')
            ->expectsOutputToContain('scolta:amazee:provision')
            ->assertExitCode(0);
    }

    public function test_status_command_reports_connected_when_signal_absent(): void
    {
        $this->app->instance(ScoltaAiService::class, new ScoltaAiService([], amazeeActive: true));

        $this->artisan('scolta:status')
            ->doesntExpectOutputToContain('NEEDS RE-AUTHENTICATION')
            ->assertExitCode(0);
    }

    // -------------------------------------------------------------------
    // Operator notice — emitted once per window, not per request
    // -------------------------------------------------------------------

    public function test_degraded_ai_records_reconnect_notice_once_per_window(): void
    {
        $this->assertFalse(Cache::has(ScoltaAiService::REAUTH_NOTICE_KEY));

        $service = new ScoltaAiService([], amazeeActive: true);
        $method = new ReflectionMethod($service, 'handlePossibleBudgetException');

        // An auth-class failure records the once-per-window notice guard that
        // gates the operator warning.
        $method->invoke($service, new \RuntimeException('code: expired_key'));
        $this->assertTrue(Cache::has(ScoltaAiService::REAUTH_NOTICE_KEY));

        // A second failure in the same window is collapsed by the guard, so the
        // operator is not warned again on every request. Cache::add is a no-op
        // once the key exists — the guard's value is unchanged.
        $before = Cache::get(ScoltaAiService::REAUTH_NOTICE_KEY);
        $method->invoke($service, new \RuntimeException('code: expired_key'));
        $this->assertSame($before, Cache::get(ScoltaAiService::REAUTH_NOTICE_KEY));
    }

    public function test_non_auth_failure_does_not_record_reconnect_notice(): void
    {
        $service = new ScoltaAiService([], amazeeActive: true);
        $method = new ReflectionMethod($service, 'handlePossibleBudgetException');

        $method->invoke($service, new \RuntimeException('connection timed out'));

        $this->assertFalse(
            Cache::has(ScoltaAiService::REAUTH_NOTICE_KEY),
            'A transient network error is not an auth failure and must not notify'
        );
    }
}
