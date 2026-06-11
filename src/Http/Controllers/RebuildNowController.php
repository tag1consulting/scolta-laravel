<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Tag1\ScoltaLaravel\Jobs\TriggerRebuild;

/**
 * POST /api/scolta/v1/rebuild-now
 *
 * Dispatches an immediate index rebuild to the queue. Admin-only
 * (auth:sanctum). Previously a logic-bearing route closure with a
 * check-then-release lock race: the lock was released before dispatch,
 * so two concurrent requests could both dispatch a rebuild. The lock is
 * now held through the dispatch.
 *
 * @since 1.0.4
 *
 * @stability experimental
 */
class RebuildNowController extends Controller
{
    /**
     * @since 1.0.4
     *
     * @stability experimental
     */
    public function __invoke(Request $request): JsonResponse
    {
        $lock = Cache::lock('scolta_build', 3600);
        if (! $lock->get()) {
            return response()->json(['error' => 'Build already in progress'], 409);
        }

        try {
            TriggerRebuild::dispatch($request->boolean('force', false));
        } finally {
            $lock->release();
        }

        return response()->json(['message' => 'Rebuild dispatched']);
    }
}
