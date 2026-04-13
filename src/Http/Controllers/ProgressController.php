<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Tag1\Scolta\Index\BuildState;

/**
 * GET /api/scolta/v1/build-progress
 *
 * Returns the current index build status for monitoring tools and
 * admin dashboards. Responds with a JSON object whose 'status' field
 * is either 'idle' or 'building'.
 *
 * This endpoint requires auth:sanctum — it is admin-only.
 *
 * @since 0.2.0
 *
 * @stability experimental
 */
class ProgressController
{
    /**
     * @since 0.2.0
     *
     * @stability experimental
     */
    public function __invoke(): JsonResponse
    {
        $stateDir = config('scolta.state_dir', storage_path('app/scolta'));
        $state = new BuildState($stateDir);

        if (! $state->isRunning()) {
            return response()->json([
                'status' => 'idle',
                'last_build' => $state->getLastBuildTime(),
            ]);
        }

        return response()->json([
            'status' => 'building',
            'progress' => $state->getProgress(),
            'started_at' => $state->getStartTime(),
            'pages_processed' => $state->getPagesProcessed(),
        ]);
    }
}
