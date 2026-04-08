<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * POST /api/scolta/v1/followup
 *
 * Handles conversational follow-up messages. Enforces the server-side
 * follow-up limit, same as WordPress and Drupal.
 *
 * Laravel's form request validation makes the message array validation
 * particularly clean — the rules read almost like documentation.
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

        $messages = $validated['messages'];

        // Last message must be from user.
        if (end($messages)['role'] !== 'user') {
            return response()->json(
                ['error' => 'Last message must be from user'],
                400
            );
        }

        $config = $ai->getConfig();
        $maxFollowups = $config->maxFollowUps;

        // Enforce follow-up limit server-side.
        // Conversation structure: initial user + assistant, then pairs of follow-ups.
        $followupsSoFar = intdiv(count($messages) - 2, 2);
        if ($followupsSoFar >= $maxFollowups) {
            return response()->json([
                'error' => 'Follow-up limit reached',
                'limit' => $maxFollowups,
            ], 429);
        }

        try {
            $response = $ai->conversation(
                $ai->getFollowUpPrompt(),
                $messages,
                512,
            );

            $remaining = $maxFollowups - $followupsSoFar - 1;

            return response()->json([
                'response' => $response,
                'remaining' => max(0, $remaining),
            ]);
        } catch (\Exception $e) {
            logger()->error('[scolta] Follow-up failed', ['error' => $e->getMessage(), 'exception' => $e]);

            return response()->json(
                ['error' => 'Follow-up unavailable'],
                503
            );
        }
    }
}
