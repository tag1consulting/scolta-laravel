<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * POST /api/scolta/v1/summarize
 *
 * Generates an AI summary of search results.
 */
class SummarizeController extends AiController
{
    public function __invoke(Request $request, ScoltaAiService $ai): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|min:1|max:500',
            'context' => 'required|string|min:1|max:50000',
        ]);

        $handler = $this->createHandler($ai, $ai->getConfig());
        $result = $handler->handleSummarize($validated['query'], $validated['context']);

        if ($result['ok']) {
            return response()->json($result['data']);
        }

        return $this->errorResponse($result, 'Summarize');
    }
}
