<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tag1\ScoltaLaravel\ScoltaServiceProvider;

/**
 * Every Artisan command the package documents is registered under that name.
 *
 * Asserted against the console kernel of a booted application rather than
 * against the $signature source, so a command class that exists but never
 * reaches the kernel fails here.
 */
class CommandValidationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ScoltaServiceProvider::class];
    }

    #[DataProvider('commandNameProvider')]
    public function test_command_is_registered(string $name): void
    {
        $registered = $this->app->make(Kernel::class)->all();

        $this->assertArrayHasKey($name, $registered, "Artisan command {$name} is not registered.");
        $this->assertNotEmpty(
            $registered[$name]->getDescription(),
            "Artisan command {$name} should have a description."
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function commandNameProvider(): array
    {
        $names = [
            'scolta:amazee:provision',
            'scolta:build',
            'scolta:check-setup',
            'scolta:cleanup',
            'scolta:clear-cache',
            'scolta:discover',
            'scolta:download-pagefind',
            'scolta:export',
            'scolta:rebuild-index',
            'scolta:status',
        ];

        return array_combine($names, array_map(fn (string $n) => [$n], $names));
    }
}
