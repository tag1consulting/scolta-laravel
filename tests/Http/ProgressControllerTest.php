<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Structural tests for ProgressController.
 *
 * Verifies the controller class is invokable, imports BuildState, and
 * is referenced by the build-progress route.
 */
class ProgressControllerTest extends TestCase {

    public function test_progress_controller_class_exists(): void {
        $this->assertTrue(
            class_exists(\Tag1\ScoltaLaravel\Http\Controllers\ProgressController::class),
            'ProgressController class must exist'
        );
    }

    public function test_progress_controller_is_invokable(): void {
        $reflection = new ReflectionClass(\Tag1\ScoltaLaravel\Http\Controllers\ProgressController::class);

        $this->assertTrue(
            $reflection->hasMethod('__invoke'),
            'ProgressController must have __invoke() method'
        );
    }

    public function test_progress_controller_uses_build_state(): void {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/src/Http/Controllers/ProgressController.php'
        );

        $this->assertStringContainsString(
            'BuildState',
            $source,
            'ProgressController must use BuildState'
        );
    }

    public function test_route_uses_progress_controller(): void {
        $routes = file_get_contents(
            dirname(__DIR__, 2) . '/routes/api.php'
        );

        $this->assertStringContainsString(
            'ProgressController::class',
            $routes,
            'routes/api.php must reference ProgressController for the build-progress route'
        );
    }

    public function test_route_is_registered_as_get(): void {
        $routes = file_get_contents(
            dirname(__DIR__, 2) . '/routes/api.php'
        );

        $this->assertMatchesRegularExpression(
            '/Route::get\s*\(\s*[\'"]\/build-progress[\'"]/',
            $routes,
            'build-progress must be a GET route'
        );
    }

    public function test_cleanup_command_class_exists(): void {
        $this->assertTrue(
            class_exists(\Tag1\ScoltaLaravel\Commands\CleanupCommand::class),
            'CleanupCommand class must exist'
        );
    }

    public function test_cleanup_command_has_dry_run_option(): void {
        $reflection = new ReflectionClass(\Tag1\ScoltaLaravel\Commands\CleanupCommand::class);
        $prop = $reflection->getProperty('signature');
        $prop->setAccessible(true);
        $signature = $prop->getValue(new \Tag1\ScoltaLaravel\Commands\CleanupCommand());

        $this->assertStringContainsString('--dry-run', $signature,
            'CleanupCommand must have a --dry-run option');
    }

    public function test_cleanup_command_registered_in_service_provider(): void {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/src/ScoltaServiceProvider.php'
        );

        $this->assertStringContainsString(
            'CleanupCommand::class',
            $source,
            'CleanupCommand must be registered in ScoltaServiceProvider'
        );
    }
}
