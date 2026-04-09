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
 * POST /api/scolta/v1/summarize
 *
 * Generates an AI summary of search results.
 */
class SummarizeController extends Controller
{
    public function __invoke(Request $request, ScoltaAiService $ai): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|min:1|max:500',
            'context' => 'required|string|min:1|max:50000',
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

        $result = $handler->handleSummarize($validated['query'], $validated['context']);

        if ($result['ok']) {
            return response()->json($result['data']);
        }

        if (isset($result['exception'])) {
            logger()->error('[scolta] Summarize failed', ['error' => $result['exception']->getMessage(), 'exception' => $result['exception']]);
        }

        return response()->json(['error' => $result['error']], $result['status']);
    }
}
