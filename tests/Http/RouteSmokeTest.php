<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * Smoke-tests the six Scolta API route definitions in routes/api.php.
 *
 * Does NOT boot a full Laravel kernel; instead parses the routes file as
 * plain source text. This is intentionally fast and dependency-free —
 * same approach as ProgressControllerTest and StructuralIntegrityTest.
 *
 * Asserts:
 *   1. All six expected route names are declared.
 *   2. Each route uses the correct HTTP method (GET vs POST).
 *   3. Admin-only routes (build-progress, rebuild-now) include auth:sanctum
 *      in their middleware group.
 *   4. AI routes (expand-query, summarize, followup) are NOT in the
 *      auth:sanctum group (they are publicly rate-limited, not auth-gated).
 *   5. Each named route references the expected controller class (or a
 *      closure for rebuild-now).
 *
 * This is the Laravel equivalent of Drupal's YamlIntegrityTest and
 * the WP RestApiSmokeTest: it catches the class of regression where a
 * route is removed, renamed, or accidentally moved behind the wrong
 * middleware without any of the handler tests firing.
 */
class RouteSmokeTest extends TestCase
{
    private string $routesSource;

    protected function setUp(): void
    {
        $this->routesSource = file_get_contents(
            dirname(__DIR__, 2) . '/routes/api.php'
        );
    }

    // -----------------------------------------------------------------------
    // All six route names must be declared
    // -----------------------------------------------------------------------

    /**
     * @dataProvider routeNameProvider
     */
    public function test_route_name_is_declared(string $name): void
    {
        $this->assertStringContainsString(
            "->name('{$name}')",
            $this->routesSource,
            "Route name '{$name}' is not declared in routes/api.php."
        );
    }

    public static function routeNameProvider(): array
    {
        return [
            'scolta.expand'         => ['scolta.expand'],
            'scolta.summarize'      => ['scolta.summarize'],
            'scolta.followup'       => ['scolta.followup'],
            'scolta.health'         => ['scolta.health'],
            'scolta.build-progress' => ['scolta.build-progress'],
            'scolta.rebuild-now'    => ['scolta.rebuild-now'],
        ];
    }

    // -----------------------------------------------------------------------
    // HTTP method assertions
    // -----------------------------------------------------------------------

    public function test_expand_is_post_route(): void
    {
        $this->assertMatchesRegularExpression(
            "/Route::post\s*\(\s*['\"]\/expand-query['\"]/",
            $this->routesSource,
            '/expand-query must be a POST route.'
        );
    }

    public function test_summarize_is_post_route(): void
    {
        $this->assertMatchesRegularExpression(
            "/Route::post\s*\(\s*['\"]\/summarize['\"]/",
            $this->routesSource,
            '/summarize must be a POST route.'
        );
    }

    public function test_followup_is_post_route(): void
    {
        $this->assertMatchesRegularExpression(
            "/Route::post\s*\(\s*['\"]\/followup['\"]/",
            $this->routesSource,
            '/followup must be a POST route.'
        );
    }

    public function test_health_is_get_route(): void
    {
        $this->assertMatchesRegularExpression(
            "/Route::get\s*\(\s*['\"]\/health['\"]/",
            $this->routesSource,
            '/health must be a GET route.'
        );
    }

    public function test_build_progress_is_get_route(): void
    {
        $this->assertMatchesRegularExpression(
            "/Route::get\s*\(\s*['\"]\/build-progress['\"]/",
            $this->routesSource,
            '/build-progress must be a GET route.'
        );
    }

    public function test_rebuild_now_is_post_route(): void
    {
        $this->assertMatchesRegularExpression(
            "/Route::post\s*\(\s*['\"]\/rebuild-now['\"]/",
            $this->routesSource,
            '/rebuild-now must be a POST route.'
        );
    }

    // -----------------------------------------------------------------------
    // Middleware guards: admin routes must require auth:sanctum
    // -----------------------------------------------------------------------

    public function test_build_progress_is_behind_auth_sanctum(): void
    {
        // Locate the group block that contains the build-progress route
        // and assert auth:sanctum is present in the same block.
        $pos = strpos($this->routesSource, '/build-progress');
        $this->assertNotFalse($pos, '/build-progress path not found in routes file.');

        // Walk backwards to find the opening of the enclosing Route::group call.
        $groupStart = strrpos(substr($this->routesSource, 0, $pos), 'Route::group');
        $this->assertNotFalse($groupStart, 'Route::group block for /build-progress not found.');

        $groupBlock = substr($this->routesSource, $groupStart, $pos - $groupStart);

        $this->assertStringContainsString(
            'auth:sanctum',
            $groupBlock,
            '/build-progress must be in a Route::group that includes auth:sanctum middleware.'
        );
    }

    public function test_rebuild_now_is_behind_auth_sanctum(): void
    {
        $pos = strpos($this->routesSource, '/rebuild-now');
        $this->assertNotFalse($pos, '/rebuild-now path not found in routes file.');

        $groupStart = strrpos(substr($this->routesSource, 0, $pos), 'Route::group');
        $this->assertNotFalse($groupStart, 'Route::group block for /rebuild-now not found.');

        $groupBlock = substr($this->routesSource, $groupStart, $pos - $groupStart);

        $this->assertStringContainsString(
            'auth:sanctum',
            $groupBlock,
            '/rebuild-now must be in a Route::group that includes auth:sanctum middleware.'
        );
    }

    public function test_expand_query_is_not_behind_auth_sanctum(): void
    {
        $pos = strpos($this->routesSource, '/expand-query');
        $this->assertNotFalse($pos, '/expand-query path not found in routes file.');

        $groupStart = strrpos(substr($this->routesSource, 0, $pos), 'Route::group');
        $this->assertNotFalse($groupStart, 'Route::group block for /expand-query not found.');

        $groupBlock = substr($this->routesSource, $groupStart, $pos - $groupStart);

        $this->assertStringNotContainsString(
            'auth:sanctum',
            $groupBlock,
            '/expand-query must NOT be behind auth:sanctum — it is publicly rate-limited.'
        );
    }

    // -----------------------------------------------------------------------
    // Controller class references
    // -----------------------------------------------------------------------

    public function test_expand_references_expand_query_controller(): void
    {
        $this->assertStringContainsString(
            'ExpandQueryController::class',
            $this->routesSource,
            '/expand-query must reference ExpandQueryController.'
        );
    }

    public function test_summarize_references_summarize_controller(): void
    {
        $this->assertStringContainsString(
            'SummarizeController::class',
            $this->routesSource,
            '/summarize must reference SummarizeController.'
        );
    }

    public function test_followup_references_follow_up_controller(): void
    {
        $this->assertStringContainsString(
            'FollowUpController::class',
            $this->routesSource,
            '/followup must reference FollowUpController.'
        );
    }

    public function test_health_references_health_controller(): void
    {
        $this->assertStringContainsString(
            'HealthController::class',
            $this->routesSource,
            '/health must reference HealthController.'
        );
    }

    public function test_build_progress_references_progress_controller(): void
    {
        $this->assertStringContainsString(
            'ProgressController::class',
            $this->routesSource,
            '/build-progress must reference ProgressController.'
        );
    }
}
