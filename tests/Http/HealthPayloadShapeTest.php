<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Http;

use PHPUnit\Framework\TestCase;
use Tag1\ScoltaLaravel\Http\Controllers\HealthController;

/**
 * The health endpoint exposes the full diagnostic payload (provider, index
 * integrity, tracker counts, asset staleness) only to callers passing the
 * 'scolta.health-detail' Gate; anonymous monitors get exactly ['status' => ...].
 *
 * shapePayload() is tested directly (no kernel boot needed); the Gate wiring
 * is asserted structurally against the controller and provider source —
 * same approach as RouteSmokeTest and StructuralIntegrityTest.
 */
class HealthPayloadShapeTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function fullReport(string $status = 'ok'): array
    {
        return [
            'status' => $status,
            'ai_provider' => 'anthropic',
            'ai_configured' => true,
            'index_exists' => true,
            'index' => ['built' => true, 'fragments' => 42, 'integrity' => ['valid' => true, 'issues' => []]],
            'tracker' => ['available' => true, 'pending_index' => 3, 'pending_delete' => 0],
            'assets_published' => true,
            'assets_current' => false,
            'assets_warning' => 'Published JS does not match package version.',
        ];
    }

    public function test_anonymous_payload_contains_exactly_status(): void
    {
        $shaped = HealthController::shapePayload($this->fullReport(), false);

        $this->assertSame(['status'], array_keys($shaped));
        $this->assertSame('ok', $shaped['status']);
    }

    public function test_anonymous_payload_still_reflects_degraded_status(): void
    {
        $shaped = HealthController::shapePayload($this->fullReport('degraded'), false);

        $this->assertSame(['status' => 'degraded'], $shaped);
    }

    public function test_authorized_payload_contains_full_detail(): void
    {
        $full = $this->fullReport();
        $shaped = HealthController::shapePayload($full, true);

        $this->assertSame($full, $shaped);
        foreach (['ai_provider', 'index', 'tracker', 'assets_published', 'assets_current'] as $key) {
            $this->assertArrayHasKey($key, $shaped);
        }
    }

    // -----------------------------------------------------------------------
    // Wiring: the controller must consult the gate, and the provider must
    // define a default for it.
    // -----------------------------------------------------------------------

    public function test_controller_gates_detail_on_health_detail_gate(): void
    {
        $src = file_get_contents(
            dirname(__DIR__, 2).'/src/Http/Controllers/HealthController.php'
        );

        $this->assertStringContainsString(
            "Gate::allows('scolta.health-detail')",
            $src,
            'HealthController must shape the payload via the scolta.health-detail Gate.'
        );
        $this->assertStringContainsString(
            'self::shapePayload($result,',
            $src,
            'HealthController must route the response through shapePayload().'
        );
    }

    public function test_provider_defines_default_health_detail_gate(): void
    {
        $src = file_get_contents(
            dirname(__DIR__, 2).'/src/ScoltaServiceProvider.php'
        );

        $this->assertStringContainsString(
            "Gate::define('scolta.health-detail'",
            $src,
            'ScoltaServiceProvider must define a default scolta.health-detail Gate.'
        );
        $this->assertStringContainsString(
            "Gate::has('scolta.health-detail')",
            $src,
            'The default gate definition must not clobber a host-defined gate.'
        );
    }
}
