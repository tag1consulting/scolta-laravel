<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tag1\Scolta\Health\HealthChecker;
use Tag1\ScoltaLaravel\Models\ScoltaTracker;
use Tag1\ScoltaLaravel\Services\AssetStatus;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * GET /api/scolta/v1/health
 *
 * Returns JSON status for monitoring tools, load balancers, and dashboards.
 *
 * Anonymous callers receive only the overall status — enough for uptime
 * monitors. The full diagnostic payload (provider, index integrity, tracker
 * counts, asset staleness) requires the 'scolta.health-detail' Gate, which
 * defaults to any authenticated user and can be redefined by the host app.
 *
 * @since 0.1.0
 *
 * @stability experimental
 */
class HealthController extends Controller
{
    /**
     * @since 0.1.0
     *
     * @stability experimental
     */
    public function __invoke(ScoltaAiService $ai): JsonResponse
    {
        $config = $ai->getConfig();
        $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));

        $checker = new HealthChecker(
            config: $config,
            indexOutputDir: $outputDir,
            pagefindBinaryPath: config('scolta.pagefind.binary'),
            projectDir: base_path(),
        );

        $result = $checker->check();

        // Laravel-specific: override AI provider when Laravel AI SDK is active.
        if ($ai->hasLaravelAiSdk()) {
            $result['ai_provider'] = 'laravel-sdk';
            $result['ai_configured'] = true;
        }

        // Laravel-specific: index detail enrichment.
        if ($result['index_exists']) {
            $indexFile = $outputDir.'/pagefind/pagefind.js';
            $mtime = filemtime($indexFile);
            $fragments = File::glob($outputDir.'/pagefind/fragment/*') ?: [];
            $result['index'] = [
                'built' => true,
                'fragments' => count($fragments),
                'last_build' => $mtime ? date('c', $mtime) : null,
            ];

            // Validate index integrity: check that pagefind.js is non-empty
            // and at least one fragment file exists and is readable.
            $integrity = ['valid' => true, 'issues' => []];

            $jsSize = filesize($indexFile);
            if ($jsSize === false || $jsSize === 0) {
                $integrity['valid'] = false;
                $integrity['issues'][] = 'pagefind.js is empty or unreadable';
            }

            if (count($fragments) > 0) {
                // Spot-check first fragment is readable and non-empty.
                $firstFragment = $fragments[0];
                $fragSize = filesize($firstFragment);
                if ($fragSize === false || $fragSize === 0) {
                    $integrity['valid'] = false;
                    $integrity['issues'][] = 'Fragment file is empty or corrupt';
                }
            } else {
                $integrity['valid'] = false;
                $integrity['issues'][] = 'No fragment files found';
            }

            $result['index']['integrity'] = $integrity;

            if (! $integrity['valid']) {
                $result['status'] = 'degraded';
            }
        } else {
            $result['index'] = ['built' => false];
        }

        // Laravel-specific: tracker status.
        $tracker = ['available' => Schema::hasTable('scolta_tracker')];
        if ($tracker['available']) {
            $tracker['pending_index'] = ScoltaTracker::getPendingCount('index');
            $tracker['pending_delete'] = ScoltaTracker::getPendingCount('delete');
        }
        $result['tracker'] = $tracker;

        $assetStatus = new AssetStatus;
        $result['assets_published'] = $assetStatus->arePublished();

        $assetsCurrent = $assetStatus->areCurrent();
        if ($assetsCurrent !== null) {
            $result['assets_current'] = $assetsCurrent;
            if (! $assetsCurrent) {
                $result['assets_warning'] = 'Published JS does not match package version. Run: php artisan vendor:publish --tag=scolta-assets --force';
            }
        }

        return response()->json(
            self::shapePayload($result, Gate::allows('scolta.health-detail'))
        );
    }

    /**
     * Shape the health payload for the caller's authorization level.
     *
     * The full report is always computed first so the trimmed status still
     * reflects integrity degradation; unauthorized callers then get exactly
     * ['status' => ...] and nothing else.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     *
     * @since 1.0.4
     *
     * @stability experimental
     */
    public static function shapePayload(array $result, bool $detail): array
    {
        return $detail ? $result : ['status' => $result['status']];
    }
}
