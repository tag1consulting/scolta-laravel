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
    /**
     * Maximum characters per individual message.
     *
     * The first conversation turn carries the full AI-Overview context
     * (the same search-result excerpts the summarize endpoint accepts, up to
     * ~50k chars), so this must be at least as large as that context. Aligned
     * with scolta-php AiEndpointHandler::FOLLOW_UP_MAX_MESSAGE_CHARS so the
     * two validation layers cannot disagree and reject a legitimate payload.
     */
    private const MAX_MESSAGE_CHARS = 100000;

    /**
     * Maximum combined characters across all messages. Aligned with
     * scolta-php AiEndpointHandler::FOLLOW_UP_MAX_TOTAL_CHARS: a full
     * conversation is the large first-turn context plus its summary plus
     * several follow-up turns, each of which may itself embed extra context.
     */
    private const MAX_TOTAL_CHARS = 400000;

    /**
     * Maximum number of messages in a conversation payload. The handler
     * additionally enforces config('scolta.max_follow_ups') on user turns.
     */
    private const MAX_MESSAGES = 25;

    public function __invoke(Request $request, ScoltaAiService $ai): JsonResponse
    {
        $validated = $request->validate([
            'messages' => [
                'required',
                'array',
                'min:1',
                'max:'.self::MAX_MESSAGES,
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $total = 0;
                    foreach ((array) $value as $message) {
                        if (is_array($message)) {
                            $total += strlen((string) ($message['content'] ?? ''));
                        }
                    }
                    if ($total > self::MAX_TOTAL_CHARS) {
                        $fail(sprintf('The combined messages content must not exceed %d characters.', self::MAX_TOTAL_CHARS));
                    }
                },
            ],
            'messages.*.role' => 'required|string|in:user,assistant',
            'messages.*.content' => 'required|string|min:1|max:'.self::MAX_MESSAGE_CHARS,
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
