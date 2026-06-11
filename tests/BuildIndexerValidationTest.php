<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Orchestra\Testbench\TestCase;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;

/**
 * `scolta:build --indexer` rejects unknown backends.
 *
 * Regression: any typo (e.g. --indexer=pphp) silently fell through to the
 * binary pipeline instead of erroring, the opposite of how
 * scolta:memory-budget validates --set.
 */
class BuildIndexerValidationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    public function test_invalid_indexer_option_fails_with_error(): void
    {
        $this->artisan('scolta:build', ['--indexer' => 'pphp'])
            ->expectsOutputToContain('Invalid indexer "pphp". Must be one of: auto, php, binary.')
            ->assertExitCode(1);
    }

    public function test_invalid_config_indexer_fails_with_error(): void
    {
        config(['scolta.indexer' => 'nodejs']);

        $this->artisan('scolta:build')
            ->expectsOutputToContain('Invalid indexer "nodejs". Must be one of: auto, php, binary.')
            ->assertExitCode(1);
    }

    public function test_valid_indexer_option_is_accepted(): void
    {
        // No models configured: the php queue path warns and exits 0 —
        // reaching that branch proves the option passed validation.
        $this->artisan('scolta:build', ['--indexer' => 'php'])
            ->expectsOutputToContain('No searchable content found.')
            ->assertExitCode(0);
    }
}
