<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tag1\ScoltaLaravel\Http\Controllers\AmazeeSettingsController;
use Tag1\ScoltaLaravel\Http\Middleware\HandleAmazeeBudgetExceeded;

/**
 * Tests for AmazeeSettingsController and HandleAmazeeBudgetExceeded middleware.
 */
class AmazeeSettingsControllerTest extends TestCase
{
    public function test_controller_class_exists(): void
    {
        $this->assertTrue(class_exists(AmazeeSettingsController::class));
    }

    public function test_controller_has_show_method(): void
    {
        $ref = new ReflectionClass(AmazeeSettingsController::class);
        $this->assertTrue($ref->hasMethod('show'));
    }

    public function test_controller_has_start_trial_method(): void
    {
        $ref = new ReflectionClass(AmazeeSettingsController::class);
        $this->assertTrue($ref->hasMethod('startTrial'));
    }

    public function test_controller_has_request_code_method(): void
    {
        $ref = new ReflectionClass(AmazeeSettingsController::class);
        $this->assertTrue($ref->hasMethod('requestCode'));
    }

    public function test_controller_has_verify_code_method(): void
    {
        $ref = new ReflectionClass(AmazeeSettingsController::class);
        $this->assertTrue($ref->hasMethod('verifyCode'));
    }

    public function test_controller_has_list_regions_method(): void
    {
        $ref = new ReflectionClass(AmazeeSettingsController::class);
        $this->assertTrue($ref->hasMethod('listRegions'));
    }

    public function test_controller_has_connect_method(): void
    {
        $ref = new ReflectionClass(AmazeeSettingsController::class);
        $this->assertTrue($ref->hasMethod('connect'));
    }

    public function test_controller_has_disconnect_method(): void
    {
        $ref = new ReflectionClass(AmazeeSettingsController::class);
        $this->assertTrue($ref->hasMethod('disconnect'));
    }

    public function test_controller_uses_amazee_account_upgrader(): void
    {
        $src = file_get_contents(
            dirname(__DIR__).'/src/Http/Controllers/AmazeeSettingsController.php'
        );
        $this->assertStringContainsString('AmazeeAccountUpgrader', $src);
    }

    public function test_controller_uses_amazee_trial_provisioner(): void
    {
        $src = file_get_contents(
            dirname(__DIR__).'/src/Http/Controllers/AmazeeSettingsController.php'
        );
        $this->assertStringContainsString('AmazeeTrialProvisioner', $src);
    }

    public function test_controller_stores_session_state(): void
    {
        $src = file_get_contents(
            dirname(__DIR__).'/src/Http/Controllers/AmazeeSettingsController.php'
        );
        $this->assertStringContainsString('SESSION_KEY', $src);
        $this->assertStringContainsString('session()', $src);
    }

    public function test_middleware_class_exists(): void
    {
        $this->assertTrue(class_exists(HandleAmazeeBudgetExceeded::class));
    }

    public function test_middleware_has_handle_method(): void
    {
        $ref = new ReflectionClass(HandleAmazeeBudgetExceeded::class);
        $this->assertTrue($ref->hasMethod('handle'));
        $method = $ref->getMethod('handle');
        $params = $method->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('request', $params[0]->getName());
        $this->assertSame('next', $params[1]->getName());
    }

    public function test_middleware_catches_budget_exception(): void
    {
        $src = file_get_contents(
            dirname(__DIR__).'/src/Http/Middleware/HandleAmazeeBudgetExceeded.php'
        );
        $this->assertStringContainsString('AmazeeBudgetExceededException', $src);
        $this->assertStringContainsString('503', $src);
    }

    public function test_routes_file_exists(): void
    {
        $this->assertFileExists(
            dirname(__DIR__).'/routes/scolta-amazee.php'
        );
    }

    public function test_routes_file_has_seven_routes(): void
    {
        $src = file_get_contents(
            dirname(__DIR__).'/routes/scolta-amazee.php'
        );
        preg_match_all('/Route::(get|post|delete)\(/', $src, $matches);
        $this->assertCount(7, $matches[0], 'scolta-amazee.php must define exactly 7 routes');
    }

    public function test_view_file_exists(): void
    {
        $this->assertFileExists(
            dirname(__DIR__).'/resources/views/amazee-settings.blade.php'
        );
    }

    public function test_view_has_alpine_component(): void
    {
        $src = file_get_contents(
            dirname(__DIR__).'/resources/views/amazee-settings.blade.php'
        );
        $this->assertStringContainsString('x-data', $src);
        $this->assertStringContainsString('amazeeSettings', $src);
    }
}
