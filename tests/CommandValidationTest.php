<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Console\Command;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tag1\ScoltaLaravel\Commands\BuildCommand;
use Tag1\ScoltaLaravel\Commands\CheckSetupCommand;
use Tag1\ScoltaLaravel\Commands\CleanupCommand;
use Tag1\ScoltaLaravel\Commands\ClearCacheCommand;
use Tag1\ScoltaLaravel\Commands\DiscoverCommand;
use Tag1\ScoltaLaravel\Commands\DownloadPagefindCommand;
use Tag1\ScoltaLaravel\Commands\ExportCommand;
use Tag1\ScoltaLaravel\Commands\RebuildIndexCommand;
use Tag1\ScoltaLaravel\Commands\StatusCommand;
use Tag1\ScoltaLaravel\Jobs\ProcessIndexChunk;

/**
 * Validate all 9 Artisan commands: existence, signatures, options, registration.
 */
class CommandValidationTest extends TestCase
{
    private const COMMAND_CLASSES = [
        'BuildCommand' => BuildCommand::class,
        'ExportCommand' => ExportCommand::class,
        'RebuildIndexCommand' => RebuildIndexCommand::class,
        'StatusCommand' => StatusCommand::class,
        'ClearCacheCommand' => ClearCacheCommand::class,
        'CleanupCommand' => CleanupCommand::class,
        'DiscoverCommand' => DiscoverCommand::class,
        'DownloadPagefindCommand' => DownloadPagefindCommand::class,
        'CheckSetupCommand' => CheckSetupCommand::class,
    ];

    private const EXPECTED_SIGNATURES = [
        BuildCommand::class => 'scolta:build',
        ExportCommand::class => 'scolta:export',
        RebuildIndexCommand::class => 'scolta:rebuild-index',
        StatusCommand::class => 'scolta:status',
        ClearCacheCommand::class => 'scolta:clear-cache',
        CleanupCommand::class => 'scolta:cleanup',
        DiscoverCommand::class => 'scolta:discover',
        DownloadPagefindCommand::class => 'scolta:download-pagefind',
        CheckSetupCommand::class => 'scolta:check-setup',
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
        $ref = new ReflectionClass(BuildCommand::class);
        $prop = $ref->getProperty('signature');
        $signature = $prop->getDefaultValue();

        $this->assertStringContainsString('--incremental', $signature,
            'BuildCommand signature should include --incremental option.');
    }

    public function test_build_command_has_skip_pagefind_option(): void
    {
        $ref = new ReflectionClass(BuildCommand::class);
        $prop = $ref->getProperty('signature');
        $signature = $prop->getDefaultValue();

        $this->assertStringContainsString('--skip-pagefind', $signature,
            'BuildCommand signature should include --skip-pagefind option.');
    }

    public function test_build_command_has_memory_budget_option(): void
    {
        $ref = new ReflectionClass(BuildCommand::class);
        $signature = $ref->getProperty('signature')->getDefaultValue();

        $this->assertStringContainsString('--memory-budget=', $signature,
            'BuildCommand signature should include --memory-budget option.');
    }

    public function test_build_command_has_chunk_size_option(): void
    {
        $ref = new ReflectionClass(BuildCommand::class);
        $signature = $ref->getProperty('signature')->getDefaultValue();

        $this->assertStringContainsString('--chunk-size=', $signature,
            'BuildCommand signature should include --chunk-size option.');
    }

    public function test_build_command_uses_from_options(): void
    {
        $source = file_get_contents(dirname(__DIR__).'/src/Commands/BuildCommand.php');

        $this->assertStringContainsString('MemoryBudget::fromOptions(', $source,
            'BuildCommand must call MemoryBudget::fromOptions() rather than fromString() + withChunkSize().');
    }

    public function test_process_index_chunk_accepts_chunk_size(): void
    {
        $ref = new ReflectionClass(ProcessIndexChunk::class);
        $constructor = $ref->getConstructor();
        $params = array_column(
            array_map(fn ($p) => ['name' => $p->getName()], $constructor->getParameters()),
            'name'
        );

        $this->assertContains('chunkSize', $params,
            'ProcessIndexChunk constructor must accept $chunkSize parameter.');
    }

    public function test_process_index_chunk_uses_from_options(): void
    {
        $source = file_get_contents(
            dirname(__DIR__).'/src/Jobs/ProcessIndexChunk.php'
        );

        $this->assertStringContainsString('MemoryBudget::fromOptions(', $source,
            'ProcessIndexChunk::handle() must use MemoryBudget::fromOptions().');
    }

    // -------------------------------------------------------------------
    // ExportCommand options
    // -------------------------------------------------------------------

    public function test_export_command_has_incremental_option(): void
    {
        $ref = new ReflectionClass(ExportCommand::class);
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

    public function test_service_provider_registers_exactly_eight_commands(): void
    {
        $providerSource = file_get_contents(
            dirname(__DIR__).'/src/ScoltaServiceProvider.php'
        );

        // Count Command::class references in the $this->commands([...]) block.
        preg_match_all('/Command::class/', $providerSource, $matches);
        $this->assertCount(10, $matches[0],
            'ServiceProvider should register exactly 10 commands.');
    }

    // -------------------------------------------------------------------
    // All commands extend Illuminate\Console\Command
    // -------------------------------------------------------------------

    #[DataProvider('commandClassProvider')]
    public function test_command_extends_illuminate_command(string $class): void
    {
        $ref = new ReflectionClass($class);
        $this->assertTrue(
            $ref->isSubclassOf(Command::class),
            "{$class} should extend Illuminate\\Console\\Command."
        );
    }
}
