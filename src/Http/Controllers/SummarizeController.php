<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * POST /api/scolta/v1/summarize
 *
 * Generates an AI summary of search results. Takes the user's query
 * and excerpts from the top results, returns a concise summary.
 */
class SummarizeController extends Controller
{
    public function __invoke(Request $request, ScoltaAiService $ai): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|min:1|max:500',
            'context' => 'required|string|min:1|max:50000',
        ]);

        $userMessage = "Search query: {$validated['query']}\n\nSearch result excerpts:\n{$validated['context']}";

        try {
            $summary = $ai->message(
                $ai->getSummarizePrompt(),
                $userMessage,
                512,
            );

            return response()->json(['summary' => $summary]);
        } catch (\Exception $e) {
            logger()->error('[scolta] Summarize failed', ['error' => $e->getMessage()]);

            return response()->json(
                ['error' => 'Summarization unavailable'],
                503
            );
        }
    }
}
