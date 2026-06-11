<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * POST /api/scolta/v1/expand-query
 *
 * Expands a search query into 2-4 related terms using AI.
 */
class ExpandQueryController extends AiController
{
    public function __invoke(Request $request, ScoltaAiService $ai): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|min:1|max:500',
        ]);

        $handler = $this->createHandler($ai, $ai->getConfig());
        $result = $handler->handleExpandQuery($validated['query']);

        if ($result['ok']) {
            return response()->json($result['data']);
        }

        return $this->errorResponse($result, 'Expand');
    }
}
