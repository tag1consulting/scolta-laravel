<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Tag1\Scolta\Cache\NullCacheDriver;
use Tag1\Scolta\Http\AiEndpointHandler;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * POST /api/scolta/v1/followup
 *
 * Handles conversational follow-up messages.
 */
class FollowUpController extends Controller
{
    public function __invoke(Request $request, ScoltaAiService $ai): JsonResponse
    {
        $validated = $request->validate([
            'messages' => 'required|array|min:1',
            'messages.*.role' => 'required|string|in:user,assistant',
            'messages.*.content' => 'required|string|min:1',
        ]);

        $config = $ai->getConfig();
        $handler = new AiEndpointHandler(
            $ai,
            new NullCacheDriver(),
            0,
            0,
            $config->maxFollowUps,
        );

        $result = $handler->handleFollowUp($validated['messages']);

        if ($result['ok']) {
            return response()->json($result['data']);
        }

        if (isset($result['exception'])) {
            logger()->error('[scolta] Follow-up failed', ['error' => $result['exception']->getMessage(), 'exception' => $result['exception']]);
        }

        $response = ['error' => $result['error']];
        if (isset($result['limit'])) {
            $response['limit'] = $result['limit'];
        }

        return response()->json($response, $result['status']);
    }
}
