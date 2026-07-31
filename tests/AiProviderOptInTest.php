<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tag1\ScoltaLaravel\Commands\AmazeeProvisionCommand;
use Tag1\ScoltaLaravel\Http\Controllers\AmazeeSettingsController;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;

/**
 * The managed Amazee.ai gateway is enabled explicitly, never by a request.
 *
 * Two surfaces enable it, each from an operator action: the settings page
 * (AmazeeSettingsController) and `artisan scolta:amazee:provision`. The
 * ScoltaAiService factory in ScoltaServiceProvider reads what those surfaces
 * stored and builds a client from it; it establishes nothing itself, so
 * serving a search request can never turn AI on.
 *
 * Structural coverage — the behavior these shapes produce is asserted in
 * AiServiceOptInResolutionTest. Source inspection is what pins "no code path
 * here can enable it", which no single behavioral case can show.
 */
class AiProviderOptInTest extends TestCase
{
    private string $providerSource;

    protected function setUp(): void
    {
        $this->providerSource = file_get_contents(
            dirname(__DIR__).'/src/ScoltaServiceProvider.php'
        );
    }

    // -------------------------------------------------------------------
    // The provider establishes no connection, from any entry point.
    // -------------------------------------------------------------------

    public function test_service_provider_never_establishes_a_connection(): void
    {
        // AmazeeTrialProvisioner::provision() is the only call that establishes
        // the connection. It belongs to the two explicit surfaces; the provider
        // must not reference it at all — not in the singleton factory, not in
        // boot(), not behind a flag.
        $this->assertStringNotContainsString(
            'AmazeeTrialProvisioner',
            $this->providerSource,
            'ScoltaServiceProvider must never reach the provisioner — enabling the gateway is an operator action'
        );
        $this->assertStringNotContainsString(
            'AutoProvisioner',
            $this->providerSource,
            'ScoltaServiceProvider must not run any provisioning helper on a request path'
        );
    }

    public function test_service_provider_declares_no_provisioning_method(): void
    {
        $ref = new ReflectionClass(ScoltaServiceProvider::class);

        foreach ($ref->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== ScoltaServiceProvider::class) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/provision/i',
                $method->getName(),
                "ScoltaServiceProvider::{$method->getName()}() must not exist — the provider reads the connection, it does not create one"
            );
        }
    }

    public function test_boot_does_not_establish_the_connection(): void
    {
        preg_match(
            '/public function boot\(\): void\s*\{(.*?)\n    \}/s',
            $this->providerSource,
            $matches
        );

        $this->assertNotEmpty($matches[1] ?? '', 'Could not locate boot() body in ScoltaServiceProvider.');

        // Comments stripped: what boot() *says* about the gateway is not what
        // boot() *does*, and only the latter is under test here.
        $body = preg_replace('~//.*~', '', $matches[1]);

        foreach (['AmazeeTrialProvisioner', 'AutoProvisioner', 'provision'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $body,
                "boot() must not reference {$needle} — a page load that needs no AI must pay nothing for it"
            );
        }
    }

    // -------------------------------------------------------------------
    // The factory builds from stored credentials, and only those.
    // -------------------------------------------------------------------

    public function test_factory_reads_the_stored_connection(): void
    {
        $this->assertStringContainsString(
            'new LaravelConfigStorage',
            $this->providerSource,
            'The factory must build the client from the stored connection'
        );
        $this->assertStringContainsString(
            '$storage->load()',
            $this->providerSource,
            'The factory must read stored credentials rather than requesting new ones'
        );
    }

    public function test_factory_guards_the_stored_connection_with_the_explicit_key(): void
    {
        // Regression: the singleton in register() must check for an explicit API
        // key before loading stored credentials so users who configured their own
        // key are never silently rerouted through the managed gateway.
        $this->assertStringContainsString(
            "\$explicitKey = \$config['ai_api_key']",
            $this->providerSource,
            "register() singleton must read \$config['ai_api_key'] before checking for stored credentials"
        );
        $this->assertStringContainsString(
            "if (\$explicitKey !== '') {",
            $this->providerSource,
            'register() singleton must branch on the explicit key'
        );
    }

    public function test_explicit_key_clears_the_stored_connection(): void
    {
        $ref = new ReflectionClass(ScoltaServiceProvider::class);
        $this->assertTrue(
            $ref->hasMethod('clearAmazeeConnection'),
            'ScoltaServiceProvider must clear a stored connection that an explicit key supersedes'
        );
        $this->assertTrue(
            $ref->getMethod('clearAmazeeConnection')->isPrivate(),
            'clearAmazeeConnection() must be private'
        );

        $this->assertStringContainsString('$storage->clear()', $this->providerSource);
        $this->assertStringContainsString('clearUpgradeNeeded()', $this->providerSource);
        $this->assertStringContainsString('clearAuthFailure()', $this->providerSource);
    }

    public function test_factory_tolerates_an_unmigrated_database(): void
    {
        $this->assertStringContainsString(
            'catch (\Exception $e)',
            $this->providerSource,
            'The factory must catch DB exceptions (DB not migrated) and degrade'
        );
    }

    // -------------------------------------------------------------------
    // The two explicit surfaces keep the enable path.
    // -------------------------------------------------------------------

    public function test_provision_command_establishes_the_connection(): void
    {
        $src = file_get_contents(dirname(__DIR__).'/src/Commands/AmazeeProvisionCommand.php');

        $this->assertStringContainsString('AmazeeTrialProvisioner', $src);
        $this->assertStringContainsString('->provision($email)', $src);
        $this->assertTrue((new ReflectionClass(AmazeeProvisionCommand::class))->hasMethod('handle'));
    }

    public function test_settings_controller_establishes_the_connection(): void
    {
        $src = file_get_contents(
            dirname(__DIR__).'/src/Http/Controllers/AmazeeSettingsController.php'
        );

        $this->assertStringContainsString('AmazeeTrialProvisioner', $src);
        $this->assertStringContainsString("->provision(\$validated['email'])", $src);
        $this->assertTrue((new ReflectionClass(AmazeeSettingsController::class))->hasMethod('startTrial'));
    }

    public function test_both_enable_surfaces_state_the_same_offer(): void
    {
        $view = file_get_contents(dirname(__DIR__).'/resources/views/amazee-settings.blade.php');

        $this->assertStringContainsString(
            AmazeeProvisionCommand::OFFER_LINE,
            $view,
            'The settings page must state the offer in the same words the command does'
        );
    }
}
