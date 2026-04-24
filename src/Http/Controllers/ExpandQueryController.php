<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Cache\NullCacheDriver;
use Tag1\Scolta\Http\AiControllerTrait;
use Tag1\Scolta\Prompt\PromptEnricherInterface;
use Tag1\ScoltaLaravel\Cache\LaravelCacheDriver;
use Tag1\ScoltaLaravel\Prompt\EventDrivenEnricher;
use Tag1\ScoltaLaravel\Services\ScoltaAiService;

/**
 * POST /api/scolta/v1/expand-query
 *
 * Expands a search query into 2-4 related terms using AI.
 */
class ExpandQueryController extends Controller
{
    use AiControllerTrait;

    public function __construct(private readonly Dispatcher $events) {}

    public function __invoke(Request $request, ScoltaAiService $ai): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|min:1|max:500',
        ]);

        $config = $ai->getConfig();
        $handler = $this->createHandler($ai, $config);
        $result = $handler->handleExpandQuery($validated['query']);

        if ($result['ok']) {
            return response()->json($result['data']);
        }

        if (isset($result['exception'])) {
            logger()->error('[scolta] Expand failed', ['error' => $result['exception']->getMessage(), 'exception' => $result['exception']]);
        }

        return response()->json(['error' => $result['error']], $result['status']);
    }

    protected function resolveCache(int $cacheTtl): CacheDriverInterface
    {
        return $cacheTtl > 0 ? new LaravelCacheDriver : new NullCacheDriver;
    }

    protected function getCacheGeneration(): int
    {
        return (int) Cache::get('scolta_expand_generation', 0);
    }

    protected function resolveEnricher(): PromptEnricherInterface
    {
        return new EventDrivenEnricher($this->events);
    }
}
