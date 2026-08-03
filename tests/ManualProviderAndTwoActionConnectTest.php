<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\AiProvider\Amazee\AmazeeConnectionSource;
use Tag1\Scolta\Config\AmazeeCredentials;
use Tag1\Scolta\Config\ApiKeyResolver;
use Tag1\Scolta\Config\ApiKeySource;

/**
 * Provider selection is manual, and connecting Amazee.ai takes two actions.
 *
 * The policy this pins:
 *
 * - **No default provider.** The shipped config selects none, and no surface
 *   substitutes 'anthropic' for an empty value. While none is selected AI is
 *   off and search is unaffected.
 * - **Amazee is never auto-enabled.** Nothing establishes a connection except
 *   an explicit operator action: the "Try the demo" control (no email, no
 *   other input) or the artisan command, and the email → code → region flow
 *   for an amazee.ai account. There is no paste-your-API-key path, matching
 *   amazee.ai's own ai_provider_amazeeio module.
 * - **Provenance is recorded, not guessed.** Which action ran is written to
 *   the credential store when it runs.
 */
class ManualProviderAndTwoActionConnectTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__);
    }

    // -------------------------------------------------------------------
    // No default provider
    // -------------------------------------------------------------------

    public function test_the_shipped_config_selects_no_provider(): void
    {
        $config = $this->file('config/scolta.php');

        $this->assertStringContainsString(
            "'ai_provider' => env('SCOLTA_AI_PROVIDER', ''),",
            $config,
            'The shipped config must select no provider.',
        );
        $this->assertStringNotContainsString(
            "env('SCOLTA_AI_PROVIDER', 'anthropic')",
            $config,
            'Defaulting to anthropic is exactly the assumption being removed.',
        );
    }

    public function test_no_surface_coalesces_an_empty_provider_to_anthropic(): void
    {
        $offenders = [];
        foreach ($this->providerReadingFiles() as $relative => $contents) {
            if (preg_match("/(\?\?|\?:)\s*'anthropic'/", $contents)) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These files substitute 'anthropic' for an unselected provider; report the empty value instead:\n"
            .implode("\n", $offenders),
        );
    }

    public function test_the_status_command_reports_ai_off_rather_than_a_provider(): void
    {
        $status = $this->file('src/Commands/StatusCommand.php');

        $this->assertStringContainsString('none selected — AI features are off', $status);
    }

    /**
     * A key with no provider selected is not reported as a working setup.
     */
    public function test_key_without_a_provider_resolves_as_ai_off(): void
    {
        $resolved = ApiKeyResolver::resolve(['env' => 'sk-env'], null, '');

        $this->assertFalse($resolved->providerSelected());
        $this->assertFalse($resolved->aiEnabled());
        $this->assertSame('warning', $resolved->severity());
    }

    // -------------------------------------------------------------------
    // Two actions, and nothing before one of them
    // -------------------------------------------------------------------

    public function test_the_demo_takes_no_email_on_either_surface(): void
    {
        $controller = $this->file('src/Http/Controllers/AmazeeSettingsController.php');
        $view = $this->file('resources/views/amazee-settings.blade.php');
        $command = $this->file('src/Commands/AmazeeProvisionCommand.php');

        // The HTTP path neither validates nor forwards an address.
        $this->assertStringContainsString('->provision()', $controller);
        $this->assertStringNotContainsString("->provision(\$validated['email'])", $controller);

        // Nor does the browser send one for that action.
        $this->assertStringContainsString('this.post(this.routes.trial, {})', $view);
        $this->assertStringContainsString('Try the demo', $view);
        $this->assertStringContainsString('Enter your Amazee credentials', $view);

        // And the command's email argument is optional.
        $this->assertStringContainsString('{email? :', $command);
    }

    public function test_there_is_no_manual_api_key_path(): void
    {
        // Email-only, matching amazee.ai's own module: the account flow returns
        // the credentials and Scolta stores them.
        $view = $this->file('resources/views/amazee-settings.blade.php');

        $this->assertStringContainsString('never generate or paste an API key', $view);
        $this->assertStringNotContainsString('id="amazee-token"', $view);
    }

    public function test_a_consumed_demo_points_at_the_account_path(): void
    {
        // The demo is one-time. A refusal must route the operator somewhere,
        // not leave them with an API error.
        foreach (['src/Commands/AmazeeProvisionCommand.php'] as $relative) {
            $this->assertStringContainsString(
                'only be used once per site',
                $this->file($relative),
                "{$relative} must point at the account path when the demo is spent",
            );
        }
    }

    // -------------------------------------------------------------------
    // Provenance
    // -------------------------------------------------------------------

    public function test_the_credential_store_records_and_clears_the_connection_source(): void
    {
        $storage = $this->file('src/AiProvider/Amazee/LaravelConfigStorage.php');

        $this->assertStringContainsString('implements ProvenanceAwareConfigStorageInterface', $storage);
        $this->assertStringContainsString('public function storeConnectionSource(', $storage);
        $this->assertStringContainsString('public function loadConnectionSource(', $storage);
        // A stale record would be paired with whatever connection comes next.
        $this->assertMatchesRegularExpression(
            '/function clear\(\).*?SOURCE_KEY/s',
            $storage,
            'clear() must delete the recorded connection source.',
        );
    }

    /**
     * Each recorded source produces its own reported source, and none is guessed.
     */
    public function test_recorded_provenance_drives_the_reported_source(): void
    {
        $cases = [
            [AmazeeConnectionSource::Demo, ApiKeySource::AmazeeDemo],
            [AmazeeConnectionSource::Account, ApiKeySource::AmazeeAccount],
            [null, ApiKeySource::Amazee],
        ];

        foreach ($cases as [$recorded, $expected]) {
            $resolved = ApiKeyResolver::resolve(
                [],
                AmazeeCredentials::fromArray(
                    ['litellm_token' => 'tok', 'litellm_api_url' => 'https://gw.amazee.ai'],
                    true,
                    $recorded,
                ),
                'amazee',
            );

            $this->assertSame($expected, $resolved->source);
            $this->assertTrue($resolved->source->isAmazee());
        }
    }

    public function test_no_operator_facing_wording_claims_an_automatic_trial(): void
    {
        $offenders = [];
        foreach ($this->operatorFacingFiles() as $relative => $contents) {
            foreach (['auto-provisioned', 'auto provisioned'] as $banned) {
                if (stripos($contents, $banned) !== false) {
                    $offenders[] = "{$relative}: {$banned}";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "No connection is provisioned automatically, so nothing may describe one:\n"
            .implode("\n", $offenders),
        );
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function file(string $relative): string
    {
        $path = $this->root.'/'.$relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * @return array<string, string>
     */
    private function providerReadingFiles(): array
    {
        $out = [];
        foreach (['config/scolta.php', 'src/Commands/StatusCommand.php', 'src/ScoltaServiceProvider.php'] as $relative) {
            if (is_file($this->root.'/'.$relative)) {
                $out[$relative] = (string) file_get_contents($this->root.'/'.$relative);
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function operatorFacingFiles(): array
    {
        $out = [];
        foreach (['src/Commands', 'src/Http/Controllers', 'resources/views'] as $dir) {
            foreach ((array) glob($this->root.'/'.$dir.'/*.*') as $file) {
                $out[$dir.'/'.basename((string) $file)] = (string) file_get_contents((string) $file);
            }
        }

        return $out;
    }
}
