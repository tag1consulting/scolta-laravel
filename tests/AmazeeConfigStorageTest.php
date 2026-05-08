<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tag1\Scolta\AiProvider\Amazee\ConfigStorageInterface;
use Tag1\ScoltaLaravel\AiProvider\Amazee\LaravelConfigStorage;

/**
 * Tests for LaravelConfigStorage.
 */
class AmazeeConfigStorageTest extends TestCase
{
    public function test_class_exists(): void
    {
        $this->assertTrue(
            class_exists(LaravelConfigStorage::class),
            'LaravelConfigStorage class must exist.'
        );
    }

    public function test_implements_config_storage_interface(): void
    {
        $ref = new ReflectionClass(LaravelConfigStorage::class);
        $this->assertTrue(
            $ref->implementsInterface(ConfigStorageInterface::class),
            'LaravelConfigStorage must implement ConfigStorageInterface.'
        );
    }

    public function test_has_store_method(): void
    {
        $ref = new ReflectionClass(LaravelConfigStorage::class);
        $this->assertTrue($ref->hasMethod('store'));
        $method = $ref->getMethod('store');
        $params = $method->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame('litellmToken', $params[0]->getName());
        $this->assertSame('litellmApiUrl', $params[1]->getName());
        $this->assertSame('region', $params[2]->getName());
    }

    public function test_has_load_method(): void
    {
        $ref = new ReflectionClass(LaravelConfigStorage::class);
        $this->assertTrue($ref->hasMethod('load'));
        $method = $ref->getMethod('load');
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertTrue($returnType->allowsNull(), 'load() must return nullable type');
    }

    public function test_has_clear_method(): void
    {
        $ref = new ReflectionClass(LaravelConfigStorage::class);
        $this->assertTrue($ref->hasMethod('clear'));
    }

    public function test_source_uses_crypt_facade(): void
    {
        $src = file_get_contents(
            dirname(__DIR__).'/src/AiProvider/Amazee/LaravelConfigStorage.php'
        );
        $this->assertStringContainsString('Crypt::encryptString', $src);
        $this->assertStringContainsString('Crypt::decryptString', $src);
    }

    public function test_source_uses_db_facade(): void
    {
        $src = file_get_contents(
            dirname(__DIR__).'/src/AiProvider/Amazee/LaravelConfigStorage.php'
        );
        $this->assertStringContainsString('DB::table', $src);
        $this->assertStringContainsString('scolta_config', $src);
    }

    public function test_migration_file_exists(): void
    {
        $this->assertFileExists(
            dirname(__DIR__).'/database/migrations/2026_05_08_000001_create_scolta_config_table.php'
        );
    }

    public function test_migration_creates_scolta_config_table(): void
    {
        $src = file_get_contents(
            dirname(__DIR__).'/database/migrations/2026_05_08_000001_create_scolta_config_table.php'
        );
        $this->assertStringContainsString("'scolta_config'", $src);
        $this->assertStringContainsString('dropIfExists', $src);
    }

    public function test_scolta_php_amazee_classes_present(): void
    {
        $dir = dirname(__DIR__).'/vendor/tag1/scolta-php/src/AiProvider/Amazee';
        $this->assertDirectoryExists($dir);
        $this->assertFileExists($dir.'/ConfigStorageInterface.php');
        $this->assertFileExists($dir.'/AmazeeBudgetExceededException.php');
        $this->assertFileExists($dir.'/AmazeeClient.php');
        $this->assertFileExists($dir.'/AmazeeTrialProvisioner.php');
        $this->assertFileExists($dir.'/AmazeeAccountUpgrader.php');
    }
}
