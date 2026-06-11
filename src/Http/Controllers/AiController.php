<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Tag1\Scolta\Cache\CacheDriverInterface;
use Tag1\Scolta\Cache\NullCacheDriver;
use Tag1\Scolta\Http\AiControllerTrait;
use Tag1\Scolta\Prompt\PromptEnricherInterface;
use Tag1\ScoltaLaravel\Cache\LaravelCacheDriver;
use Tag1\ScoltaLaravel\Prompt\EventDrivenEnricher;

/**
 * Base class for the AI endpoint controllers.
 *
 * Owns the platform wiring the three AI controllers (expand, summarize,
 * follow-up) previously duplicated: cache driver resolution, the shared
 * generation counter, the prompt enricher, and the error response shape.
 * Subclasses implement __invoke() with their endpoint-specific validation
 * and handler call.
 *
 * @since 1.0.4
 *
 * @stability experimental
 */
abstract class AiController extends Controller
{
    use AiControllerTrait;

    public function __construct(protected readonly Dispatcher $events) {}

    protected function resolveCache(int $cacheTtl): CacheDriverInterface
    {
        return $cacheTtl > 0 ? new LaravelCacheDriver : new NullCacheDriver;
    }

    /**
     * The shared AI cache generation counter.
     *
     * 'scolta_expand_generation' invalidates ALL cached AI responses —
     * query expansions AND summaries — at once. It is incremented by
     * index builds (content changed, so cached answers may be stale) and
     * by `scolta:clear-cache`. The "expand" in the name is historical: the
     * key predates the summarize cache sharing the same generation, and
     * renaming it would orphan deployed counters mid-flight.
     */
    protected function getCacheGeneration(): int
    {
        return (int) Cache::get('scolta_expand_generation', 0);
    }

    protected function resolveEnricher(): PromptEnricherInterface
    {
        return new EventDrivenEnricher($this->events);
    }

    /**
     * Shared error response for a failed handler result.
     *
     * Logs the underlying exception when the handler attached one, and
     * passes the optional 'limit' hint through (used by the follow-up
     * endpoint when the conversation cap is reached).
     *
     * @param  array{ok: bool, data?: mixed, status?: int, error?: string, exception?: \Throwable, limit?: int}  $result
     */
    protected function errorResponse(array $result, string $operation): JsonResponse
    {
        if (isset($result['exception'])) {
            logger()->error("[scolta] {$operation} failed", [
                'error' => $result['exception']->getMessage(),
                'exception' => $result['exception'],
            ]);
        }

        $response = ['error' => $result['error'] ?? 'Unknown error'];
        if (isset($result['limit'])) {
            $response['limit'] = $result['limit'];
        }

        return response()->json($response, $result['status'] ?? 500);
    }
}
