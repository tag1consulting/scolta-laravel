<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Tag1\Scolta\Index\BuildState;

/**
 * GET /api/scolta/v1/build-progress
 *
 * Returns the current index build status for monitoring tools and
 * admin dashboards. Responds with a JSON object whose 'status' field
 * is either 'idle' or 'building'. A building response names the 'phase'
 * (gathering, merging, publishing) and carries 'progress' only while
 * gathering: the ratio describes chunks committed against the pre-gather
 * record total, which says nothing about the merge.
 *
 * This endpoint requires auth:sanctum — it is admin-only.
 *
 * @since 0.2.0
 *
 * @stability experimental
 */
class ProgressController extends Controller
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

        $phase = $state->phase() ?? BuildState::PHASE_GATHERING;
        $response = [
            'status' => 'building',
            'phase' => $phase,
            'started_at' => $state->getStartTime(),
            'pages_processed' => $state->getPagesProcessed(),
        ];
        if ($phase === BuildState::PHASE_GATHERING) {
            $response['progress'] = $state->getProgress();
        }

        return response()->json($response);
    }
}
