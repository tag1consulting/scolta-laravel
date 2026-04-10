<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tag1\ScoltaLaravel\Http\Controllers\ExpandQueryController;
use Tag1\ScoltaLaravel\Http\Controllers\FollowUpController;
use Tag1\ScoltaLaravel\Http\Controllers\HealthController;
use Tag1\ScoltaLaravel\Http\Controllers\SummarizeController;

/**
 * Validate controller structure: existence, invokable pattern, parameter/return types.
 */
class ControllerStructureTest extends TestCase
{
    private const CONTROLLERS = [
        'ExpandQuery' => ExpandQueryController::class,
        'Summarize' => SummarizeController::class,
        'FollowUp' => FollowUpController::class,
        'Health' => HealthController::class,
    ];

    // -------------------------------------------------------------------
    // Controller class existence
    // -------------------------------------------------------------------

    #[DataProvider('controllerProvider')]
    public function test_controller_class_exists(string $class): void
    {
        $this->assertTrue(class_exists($class), "Controller {$class} does not exist.");
    }

    public static function controllerProvider(): array
    {
        $data = [];
        foreach (self::CONTROLLERS as $name => $class) {
            $data[$name] = [$class];
        }

        return $data;
    }

    // -------------------------------------------------------------------
    // Invokable controllers have __invoke method
    // -------------------------------------------------------------------

    #[DataProvider('controllerProvider')]
    public function test_controller_has_invoke_method(string $class): void
    {
        $ref = new ReflectionClass($class);
        $this->assertTrue(
            $ref->hasMethod('__invoke'),
            "{$class} should be an invokable controller with __invoke()."
        );
    }

    // -------------------------------------------------------------------
    // __invoke returns JsonResponse
    // -------------------------------------------------------------------

    #[DataProvider('controllerProvider')]
    public function test_invoke_returns_json_response(string $class): void
    {
        $method = new ReflectionMethod($class, '__invoke');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType, "{$class}::__invoke() should have a return type.");
        $this->assertInstanceOf(
            ReflectionNamedType::class,
            $returnType,
            "{$class}::__invoke() return type should be a named type."
        );
        $this->assertEquals(
            JsonResponse::class,
            $returnType->getName(),
            "{$class}::__invoke() should return JsonResponse."
        );
    }

    // -------------------------------------------------------------------
    // __invoke accepts Request for POST controllers
    // -------------------------------------------------------------------

    #[DataProvider('postControllerProvider')]
    public function test_invoke_accepts_request_parameter(string $class): void
    {
        $method = new ReflectionMethod($class, '__invoke');
        $params = $method->getParameters();

        $this->assertGreaterThanOrEqual(1, count($params),
            "{$class}::__invoke() should accept at least one parameter.");

        $firstParam = $params[0];
        $type = $firstParam->getType();
        $this->assertNotNull($type, "First parameter of {$class}::__invoke() should be typed.");
        $this->assertEquals(
            Request::class,
            $type->getName(),
            "First parameter of {$class}::__invoke() should be Request."
        );
    }

    public static function postControllerProvider(): array
    {
        return [
            'ExpandQuery' => [ExpandQueryController::class],
            'Summarize' => [SummarizeController::class],
            'FollowUp' => [FollowUpController::class],
        ];
    }

    // -------------------------------------------------------------------
    // Route definitions match controllers
    // -------------------------------------------------------------------

    public function test_routes_file_references_all_controllers(): void
    {
        $routesContent = file_get_contents(dirname(__DIR__).'/routes/api.php');

        $this->assertStringContainsString('ExpandQueryController::class', $routesContent,
            'Routes should reference ExpandQueryController.');
        $this->assertStringContainsString('SummarizeController::class', $routesContent,
            'Routes should reference SummarizeController.');
        $this->assertStringContainsString('FollowUpController::class', $routesContent,
            'Routes should reference FollowUpController.');
        $this->assertStringContainsString('HealthController::class', $routesContent,
            'Routes should reference HealthController.');
    }

    public function test_routes_define_correct_http_methods(): void
    {
        $routesContent = file_get_contents(dirname(__DIR__).'/routes/api.php');

        // POST routes for AI endpoints.
        $this->assertStringContainsString("Route::post('/expand-query'", $routesContent);
        $this->assertStringContainsString("Route::post('/summarize'", $routesContent);
        $this->assertStringContainsString("Route::post('/followup'", $routesContent);

        // GET route for health.
        $this->assertStringContainsString("Route::get('/health'", $routesContent);
    }

    public function test_routes_use_controller_class_references(): void
    {
        $routesContent = file_get_contents(dirname(__DIR__).'/routes/api.php');

        // Verify use statements import all controllers.
        $this->assertStringContainsString(
            'use Tag1\ScoltaLaravel\Http\Controllers\ExpandQueryController;',
            $routesContent
        );
        $this->assertStringContainsString(
            'use Tag1\ScoltaLaravel\Http\Controllers\SummarizeController;',
            $routesContent
        );
        $this->assertStringContainsString(
            'use Tag1\ScoltaLaravel\Http\Controllers\FollowUpController;',
            $routesContent
        );
        $this->assertStringContainsString(
            'use Tag1\ScoltaLaravel\Http\Controllers\HealthController;',
            $routesContent
        );
    }

    // -------------------------------------------------------------------
    // Health controller does not require AI for basic status
    // -------------------------------------------------------------------

    public function test_health_controller_does_not_require_request(): void
    {
        $method = new ReflectionMethod(
            HealthController::class,
            '__invoke'
        );
        $params = $method->getParameters();

        // HealthController should accept ScoltaAiService, not Request.
        // It reads config status — it doesn't process user input.
        $hasRequestParam = false;
        foreach ($params as $param) {
            $type = $param->getType();
            if ($type !== null && $type->getName() === Request::class) {
                $hasRequestParam = true;
            }
        }

        $this->assertFalse($hasRequestParam,
            'HealthController should not require a Request parameter for basic status.');
    }

    // -------------------------------------------------------------------
    // Controllers extend Illuminate\Routing\Controller
    // -------------------------------------------------------------------

    #[DataProvider('controllerProvider')]
    public function test_controller_extends_base_controller(string $class): void
    {
        $ref = new ReflectionClass($class);
        $this->assertTrue(
            $ref->isSubclassOf(Controller::class),
            "{$class} should extend Illuminate\\Routing\\Controller."
        );
    }
}
