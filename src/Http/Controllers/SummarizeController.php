<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * POST /api/scolta/v1/summarize
 *
 * Generates an AI summary of search results. Takes the user's query
 * and excerpts from the top results, returns a concise summary.
 * Caches responses using the same generation counter as expand-query.
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

        // Cache lookup with generation counter.
        $generation = Cache::get('scolta_expand_generation', 0);
        $cacheKey = 'scolta_summarize_'.$generation.'_'.hash('sha256', strtolower($validated['query']).'|'.$validated['context']);
        if ($config->cacheTtl > 0) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return response()->json($cached);
            }
        }

        $userMessage = "Search query: {$validated['query']}\n\nSearch result excerpts:\n{$validated['context']}";

        try {
            $summary = $ai->message(
                $ai->getSummarizePrompt(),
                $userMessage,
                512,
            );

            $result = ['summary' => $summary];

            if ($config->cacheTtl > 0) {
                Cache::put($cacheKey, $result, $config->cacheTtl);
            }

            return response()->json($result);
        } catch (\Exception $e) {
            logger()->error('[scolta] Summarize failed', ['error' => $e->getMessage(), 'exception' => $e]);

            return response()->json(
                ['error' => 'Summarization unavailable'],
                503
            );
        }
    }
}
