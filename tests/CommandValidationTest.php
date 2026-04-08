<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Validate all 7 Artisan commands: existence, signatures, options, registration.
 */
class CommandValidationTest extends TestCase
{
    private const COMMAND_CLASSES = [
        'BuildCommand' => \Tag1\ScoltaLaravel\Commands\BuildCommand::class,
        'ExportCommand' => \Tag1\ScoltaLaravel\Commands\ExportCommand::class,
        'RebuildIndexCommand' => \Tag1\ScoltaLaravel\Commands\RebuildIndexCommand::class,
        'StatusCommand' => \Tag1\ScoltaLaravel\Commands\StatusCommand::class,
        'ClearCacheCommand' => \Tag1\ScoltaLaravel\Commands\ClearCacheCommand::class,
        'DownloadPagefindCommand' => \Tag1\ScoltaLaravel\Commands\DownloadPagefindCommand::class,
        'CheckSetupCommand' => \Tag1\ScoltaLaravel\Commands\CheckSetupCommand::class,
    ];

    private const EXPECTED_SIGNATURES = [
        \Tag1\ScoltaLaravel\Commands\BuildCommand::class => 'scolta:build',
        \Tag1\ScoltaLaravel\Commands\ExportCommand::class => 'scolta:export',
        \Tag1\ScoltaLaravel\Commands\RebuildIndexCommand::class => 'scolta:rebuild-index',
        \Tag1\ScoltaLaravel\Commands\StatusCommand::class => 'scolta:status',
        \Tag1\ScoltaLaravel\Commands\ClearCacheCommand::class => 'scolta:clear-cache',
        \Tag1\ScoltaLaravel\Commands\DownloadPagefindCommand::class => 'scolta:download-pagefind',
        \Tag1\ScoltaLaravel\Commands\CheckSetupCommand::class => 'scolta:check-setup',
    ];

    // -------------------------------------------------------------------
    // Command class existence
    // -------------------------------------------------------------------

    #[DataProvider('commandClassProvider')]
    public function test_command_class_exists(string $class): void
    {
        $this->assertTrue(class_exists($class), "Command class {$class} does not exist.");
    }

    public static function commandClassProvider(): array
    {
        $data = [];
        foreach (self::COMMAND_CLASSES as $name => $class) {
            $data[$name] = [$class];
        }

        return $data;
    }

    // -------------------------------------------------------------------
    // $signature and $description properties
    // -------------------------------------------------------------------

    #[DataProvider('commandClassProvider')]
    public function test_command_has_signature_property(string $class): void
    {
        $ref = new ReflectionClass($class);
        $this->assertTrue(
            $ref->hasProperty('signature'),
            "{$class} is missing \$signature property."
        );

        $prop = $ref->getProperty('signature');

        // Read the default value without instantiating the class.
        $defaultValue = $prop->getDefaultValue();
        $this->assertNotEmpty($defaultValue, "{$class} \$signature should not be empty.");
    }

    #[DataProvider('commandClassProvider')]
    public function test_command_has_description_property(string $class): void
    {
        $ref = new ReflectionClass($class);
        $this->assertTrue(
            $ref->hasProperty('description'),
            "{$class} is missing \$description property."
        );

        $prop = $ref->getProperty('description');

        $defaultValue = $prop->getDefaultValue();
        $this->assertNotEmpty($defaultValue, "{$class} \$description should not be empty.");
    }

    // -------------------------------------------------------------------
    // Signatures match expected command names
    // -------------------------------------------------------------------

    #[DataProvider('signatureProvider')]
    public function test_signature_matches_expected_command_name(string $class, string $expectedName): void
    {
        $ref = new ReflectionClass($class);
        $prop = $ref->getProperty('signature');
        $signature = $prop->getDefaultValue();

        // The command name is the first word/token in the signature.
        $commandName = preg_split('/\s/', trim($signature), 2)[0];
        $this->assertEquals(
            $expectedName,
            $commandName,
            "Signature for {$class} should start with '{$expectedName}'."
        );
    }

    public static function signatureProvider(): array
    {
        $data = [];
        foreach (self::EXPECTED_SIGNATURES as $class => $name) {
            $data[$name] = [$class, $name];
        }

        return $data;
    }

    // -------------------------------------------------------------------
    // handle() method exists with int return type
    // -------------------------------------------------------------------

    #[DataProvider('commandClassProvider')]
    public function test_command_has_handle_method(string $class): void
    {
        $ref = new ReflectionClass($class);
        $this->assertTrue(
            $ref->hasMethod('handle'),
            "{$class} is missing handle() method."
        );
    }

    #[DataProvider('commandClassProvider')]
    public function test_handle_returns_int(string $class): void
    {
        $method = new ReflectionMethod($class, 'handle');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType, "{$class}::handle() should have a return type.");
        $this->assertEquals('int', (string) $returnType, "{$class}::handle() should return int.");
    }

    // -------------------------------------------------------------------
    // BuildCommand options
    // -------------------------------------------------------------------

    public function test_build_command_has_incremental_option(): void
    {
        $ref = new ReflectionClass(\Tag1\ScoltaLaravel\Commands\BuildCommand::class);
        $prop = $ref->getProperty('signature');
        $signature = $prop->getDefaultValue();

        $this->assertStringContainsString('--incremental', $signature,
            'BuildCommand signature should include --incremental option.');
    }

    public function test_build_command_has_skip_pagefind_option(): void
    {
        $ref = new ReflectionClass(\Tag1\ScoltaLaravel\Commands\BuildCommand::class);
        $prop = $ref->getProperty('signature');
        $signature = $prop->getDefaultValue();

        $this->assertStringContainsString('--skip-pagefind', $signature,
            'BuildCommand signature should include --skip-pagefind option.');
    }

    // -------------------------------------------------------------------
    // ExportCommand options
    // -------------------------------------------------------------------

    public function test_export_command_has_incremental_option(): void
    {
        $ref = new ReflectionClass(\Tag1\ScoltaLaravel\Commands\ExportCommand::class);
        $prop = $ref->getProperty('signature');
        $signature = $prop->getDefaultValue();

        $this->assertStringContainsString('--incremental', $signature,
            'ExportCommand signature should include --incremental option.');
    }

    // -------------------------------------------------------------------
    // All commands registered in ServiceProvider
    // -------------------------------------------------------------------

    public function test_all_commands_registered_in_service_provider(): void
    {
        $providerSource = file_get_contents(
            dirname(__DIR__).'/src/ScoltaServiceProvider.php'
        );

        foreach (self::COMMAND_CLASSES as $name => $class) {
            $shortClass = $name.'::class';
            $this->assertStringContainsString(
                $shortClass,
                $providerSource,
                "ServiceProvider should register {$name}."
            );
        }
    }

    public function test_service_provider_registers_exactly_seven_commands(): void
    {
        $providerSource = file_get_contents(
            dirname(__DIR__).'/src/ScoltaServiceProvider.php'
        );

        // Count Command::class references in the $this->commands([...]) block.
        preg_match_all('/Command::class/', $providerSource, $matches);
        $this->assertCount(7, $matches[0],
            'ServiceProvider should register exactly 7 commands.');
    }

    // -------------------------------------------------------------------
    // All commands extend Illuminate\Console\Command
    // -------------------------------------------------------------------

    #[DataProvider('commandClassProvider')]
    public function test_command_extends_illuminate_command(string $class): void
    {
        $ref = new ReflectionClass($class);
        $this->assertTrue(
            $ref->isSubclassOf(\Illuminate\Console\Command::class),
            "{$class} should extend Illuminate\\Console\\Command."
        );
    }
}
