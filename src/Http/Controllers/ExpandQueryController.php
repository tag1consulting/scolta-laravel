<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * POST /api/scolta/v1/expand-query
 *
 * Expands a search query into 2-4 related terms using AI.
 * Uses Laravel's Cache facade — whatever cache driver the app is
 * configured with (Redis, Memcached, file, database) works automatically.
 *
 * This is one of Laravel's strengths: swappable backends with a unified
 * API. The same code works with a file cache in development and Redis
 * in production, zero code changes.
 */
class ExpandQueryController extends Controller
{
    public function __invoke(Request $request, ScoltaAiService $ai): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|min:1|max:500',
        ]);

        $query = $validated['query'];
        $config = $ai->getConfig();

        // Cache lookup with generation counter for invalidation on rebuild.
        $generation = Cache::get('scolta_expand_generation', 0);
        $cacheKey = 'scolta_expand_'.$generation.'_'.hash('sha256', strtolower($query));
        if ($config->cacheTtl > 0) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return response()->json($cached);
            }
        }

        try {
            $response = $ai->message(
                $ai->getExpandPrompt(),
                'Expand this search query: '.$query,
                512,
            );

            // Strip markdown code fences if the LLM wraps the JSON.
            $cleaned = trim($response);
            $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
            $cleaned = preg_replace('/\s*```$/', '', $cleaned);
            $cleaned = trim($cleaned);

            $terms = json_decode($cleaned, true);
            if (! is_array($terms) || count($terms) < 2) {
                $terms = [$query];
            }

            if ($config->cacheTtl > 0) {
                Cache::put($cacheKey, $terms, $config->cacheTtl);
            }

            return response()->json($terms);
        } catch (\Exception $e) {
            logger()->error('[scolta] Expand failed', ['error' => $e->getMessage(), 'exception' => $e]);

            return response()->json(
                ['error' => 'Query expansion unavailable'],
                503
            );
        }
    }
}
