<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Cache\NullCacheDriver;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * POST /api/scolta/v1/followup
 *
 * Handles conversational follow-up messages.
 */
class FollowUpController extends AiController
{
    public function __invoke(Request $request, ScoltaAiService $ai): JsonResponse
    {
        $validated = $request->validate([
            'messages' => 'required|array|min:1',
            'messages.*.role' => 'required|string|in:user,assistant',
            'messages.*.content' => 'required|string|min:1',
        ]);

        $handler = $this->createHandler($ai, $ai->getConfig());
        $result = $handler->handleFollowUp($validated['messages']);

        if ($result['ok']) {
            return response()->json($result['data']);
        }

        return $this->errorResponse($result, 'Follow-up');
    }

    /**
     * Follow-up responses are conversation-specific and never cached.
     */
    protected function resolveCache(int $cacheTtl): CacheDriverInterface
    {
        return new NullCacheDriver;
    }

    protected function getCacheGeneration(): int
    {
        return 0;
    }
}
