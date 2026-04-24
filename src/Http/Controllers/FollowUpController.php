<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Cache\NullCacheDriver;
use Tag1\Scolta\Http\AiControllerTrait;
use Tag1\Scolta\Prompt\PromptEnricherInterface;
use Tag1\ScoltaLaravel\Prompt\EventDrivenEnricher;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * POST /api/scolta/v1/followup
 *
 * Handles conversational follow-up messages.
 */
class FollowUpController extends Controller
{
    use AiControllerTrait;

    public function __construct(private readonly Dispatcher $events) {}

    public function __invoke(Request $request, ScoltaAiService $ai): JsonResponse
    {
        $validated = $request->validate([
            'messages' => 'required|array|min:1',
            'messages.*.role' => 'required|string|in:user,assistant',
            'messages.*.content' => 'required|string|min:1',
        ]);

        $config  = $ai->getConfig();
        $handler = $this->createHandler($ai, $config);
        $result  = $handler->handleFollowUp($validated['messages']);

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

    protected function resolveCache(int $cacheTtl): CacheDriverInterface
    {
        return new NullCacheDriver;
    }

    protected function getCacheGeneration(): int
    {
        return 0;
    }

    protected function resolveEnricher(): PromptEnricherInterface
    {
        return new EventDrivenEnricher($this->events);
    }
}
