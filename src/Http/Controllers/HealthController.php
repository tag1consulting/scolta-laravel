<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
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
        $outputDir = config('scolta.pagefind.output_dir', public_path('scolta-pagefind'));
        $indexFile = $outputDir . '/pagefind.js';

        // Index status.
        $indexBuilt = file_exists($indexFile);
        $index = [
            'built' => $indexBuilt,
        ];
        if ($indexBuilt) {
            $index['fragments'] = count(glob($outputDir . '/fragment/*') ?: []);
            $mtime = filemtime($indexFile);
            $index['last_build'] = $mtime ? date('c', $mtime) : null;
        }

        // AI status.
        $aiStatus = [
            'provider' => $ai->hasLaravelAiSdk() ? 'laravel-sdk' : ($ai->getConfig()->aiProvider ?: 'anthropic'),
            'configured' => $ai->hasLaravelAiSdk() || !empty($ai->getConfig()->aiApiKey),
        ];

        // Tracker status.
        $tracker = ['available' => Schema::hasTable('scolta_tracker')];
        if ($tracker['available']) {
            $tracker['pending_index'] = ScoltaTracker::getPendingCount('index');
            $tracker['pending_delete'] = ScoltaTracker::getPendingCount('delete');
        }

        // Overall status.
        $status = 'ok';
        if (!$indexBuilt) {
            $status = 'degraded';
        }
        if (!$aiStatus['configured'] && !$ai->hasLaravelAiSdk()) {
            $status = 'degraded';
        }

        return response()->json([
            'status' => $status,
            'index' => $index,
            'ai' => $aiStatus,
            'tracker' => $tracker,
            'assets_published' => file_exists(public_path('vendor/scolta/scolta.js')),
        ]);
    }
}
