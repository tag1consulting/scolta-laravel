<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Tag1\Scolta\Cache\NullCacheDriver;
use Tag1\Scolta\Http\AiEndpointHandler;
use Tag1\ScoltaLaravel\Cache\LaravelCacheDriver;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * POST /api/scolta/v1/expand-query
 *
 * Expands a search query into 2-4 related terms using AI.
 */
class ExpandQueryController extends Controller
{
    public function __invoke(Request $request, ScoltaAiService $ai): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|min:1|max:500',
        ]);

        $config = $ai->getConfig();
        $generation = Cache::get('scolta_expand_generation', 0);
        $handler = new AiEndpointHandler(
            $ai,
            $config->cacheTtl > 0 ? new LaravelCacheDriver() : new NullCacheDriver(),
            $generation,
            $config->cacheTtl,
            $config->maxFollowUps,
        );

        $result = $handler->handleExpandQuery($validated['query']);

        if ($result['ok']) {
            return response()->json($result['data']);
        }

        if (isset($result['exception'])) {
            logger()->error('[scolta] Expand failed', ['error' => $result['exception']->getMessage(), 'exception' => $result['exception']]);
        }

        return response()->json(['error' => $result['error']], $result['status']);
    }
}
