<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
use Tag1\Scolta\Health\HealthChecker;
use Tag1\ScoltaLaravel\Models\ScoltaTracker;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * GET /api/scolta/v1/health
 *
 * Returns JSON status for monitoring tools, load balancers, and dashboards.
 */
class HealthController extends Controller
{
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
            $indexFile = $outputDir.'/pagefind.js';
            $mtime = filemtime($indexFile);
            $fragments = glob($outputDir.'/fragment/*') ?: [];
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

        $result['assets_published'] = file_exists(public_path('vendor/scolta/scolta.js'));

        return response()->json($result);
    }
}
