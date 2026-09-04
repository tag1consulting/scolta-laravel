<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Http;

use PHPUnit\Framework\TestCase;
use Tag1\ScoltaLaravel\Http\Controllers\HealthController;

/**
 * The index-integrity spot check is the adapter's own fault detection, merged
 * into a payload scolta-php's HealthChecker owns.
 *
 * The committed lock resolves a scolta-php without `status_reasons`, so
 * degradeFor() is driven here against both payload shapes directly.
 */
class HealthStatusReasonsTest extends TestCase
{
    /**
     * The payload the installed scolta-php produces: no `status_reasons` key.
     *
     * @return array<string, mixed>
     */
    private function legacyReport(string $status = 'ok'): array
    {
        return [
            'status' => $status,
            'ai_provider' => 'anthropic',
            'ai_configured' => true,
            'index_exists' => true,
        ];
    }

    /**
     * The payload scolta-php produces once `status_reasons` lands.
     *
     * @param  list<string>  $reasons
     * @return array<string, mixed>
     */
    private function reasonsReport(array $reasons = []): array
    {
        return [
            'status' => $reasons === [] ? 'ok' : 'degraded',
            'status_reasons' => $reasons,
            'ai_provider' => '',
            'ai_configured' => false,
            'index_exists' => true,
        ];
    }

    // -----------------------------------------------------------------------
    // Payload shape without status_reasons (the scolta-php the lock names).
    // -----------------------------------------------------------------------

    public function test_legacy_payload_is_degraded_without_gaining_a_reasons_key(): void
    {
        $shaped = HealthController::degradeFor(
            $this->legacyReport(),
            HealthController::REASON_INDEX_INTEGRITY_INVALID
        );

        $this->assertSame('degraded', $shaped['status']);
        $this->assertArrayNotHasKey(
            'status_reasons',
            $shaped,
            'The adapter must not invent a key the installed scolta-php does not produce.'
        );
    }

    public function test_legacy_payload_keeps_every_other_field(): void
    {
        $before = $this->legacyReport();
        $shaped = HealthController::degradeFor(
            $before,
            HealthController::REASON_INDEX_INTEGRITY_INVALID
        );

        $this->assertSame(
            array_diff_key($before, ['status' => null]),
            array_diff_key($shaped, ['status' => null])
        );
    }

    // -----------------------------------------------------------------------
    // Payload shape with status_reasons (scolta-php 1.5.0 and later).
    // -----------------------------------------------------------------------

    public function test_reason_is_appended_to_an_otherwise_healthy_payload(): void
    {
        $shaped = HealthController::degradeFor(
            $this->reasonsReport(),
            HealthController::REASON_INDEX_INTEGRITY_INVALID
        );

        $this->assertSame('degraded', $shaped['status']);
        $this->assertSame(['index_integrity_invalid'], $shaped['status_reasons']);
    }

    public function test_reason_is_appended_beside_the_checkers_own_reasons(): void
    {
        $shaped = HealthController::degradeFor(
            $this->reasonsReport(['index_stale_artifact_urls', 'ai_auth_failing']),
            HealthController::REASON_INDEX_INTEGRITY_INVALID
        );

        $this->assertSame(
            ['index_stale_artifact_urls', 'ai_auth_failing', 'index_integrity_invalid'],
            $shaped['status_reasons'],
            'Existing reasons must survive; the adapter appends rather than overwrites.'
        );
        $this->assertSame('degraded', $shaped['status']);
    }

    public function test_status_is_never_empty_of_reasons_while_non_ok(): void
    {
        $shaped = HealthController::degradeFor(
            $this->reasonsReport(),
            HealthController::REASON_INDEX_INTEGRITY_INVALID
        );

        // status_reasons is empty exactly when status is 'ok'; a degraded
        // payload with an empty list is the contradiction this prevents.
        $this->assertNotSame('ok', $shaped['status']);
        $this->assertNotSame([], $shaped['status_reasons']);
    }

    public function test_the_same_reason_is_not_recorded_twice(): void
    {
        $once = HealthController::degradeFor(
            $this->reasonsReport(),
            HealthController::REASON_INDEX_INTEGRITY_INVALID
        );
        $twice = HealthController::degradeFor(
            $once,
            HealthController::REASON_INDEX_INTEGRITY_INVALID
        );

        $this->assertSame($once, $twice);
    }

    // -----------------------------------------------------------------------
    // Severity.
    // -----------------------------------------------------------------------

    public function test_a_more_severe_status_is_not_demoted(): void
    {
        // scolta-php reports only 'ok' and 'degraded' today; the flat overwrite
        // this replaces is why it did not add a more severe third value.
        $report = $this->reasonsReport(['index_missing']);
        $report['status'] = 'unavailable';

        $shaped = HealthController::degradeFor(
            $report,
            HealthController::REASON_INDEX_INTEGRITY_INVALID
        );

        $this->assertSame('unavailable', $shaped['status']);
        $this->assertSame(
            ['index_missing', 'index_integrity_invalid'],
            $shaped['status_reasons'],
            'A status left alone must still gain the reason.'
        );
    }

    public function test_an_already_degraded_status_stays_degraded(): void
    {
        $shaped = HealthController::degradeFor(
            $this->reasonsReport(['index_stale_artifact_urls']),
            HealthController::REASON_INDEX_INTEGRITY_INVALID
        );

        $this->assertSame('degraded', $shaped['status']);
    }

    // -----------------------------------------------------------------------
    // The anonymous payload is unchanged by any of this.
    // -----------------------------------------------------------------------

    public function test_anonymous_payload_carries_the_degraded_status_and_no_reasons(): void
    {
        foreach (['legacy' => $this->legacyReport(), 'reasons' => $this->reasonsReport()] as $label => $report) {
            $shaped = HealthController::shapePayload(
                HealthController::degradeFor($report, HealthController::REASON_INDEX_INTEGRITY_INVALID),
                false
            );

            $this->assertSame(['status' => 'degraded'], $shaped, "Anonymous {$label} payload");
        }
    }
}
