<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tag1\Scolta\AiProvider\Amazee\AmazeeBudgetExceededException;

/**
 * Converts AmazeeBudgetExceededException into a 503 JSON response.
 *
 * Applied automatically to Scolta API routes when Amazee.ai is active.
 * Clients receive a clear JSON error with a Retry-After header rather
 * than an unhandled 500.
 */
class HandleAmazeeBudgetExceeded
{
    public function handle(Request $request, Closure $next): mixed
    {
        try {
            return $next($request);
        } catch (AmazeeBudgetExceededException $e) {
            logger()->warning('[scolta] Amazee.ai budget exceeded — AI search unavailable', [
                'message' => $e->getMessage(),
            ]);

            if (! $request->expectsJson()) {
                session()->flash(
                    'scolta_error',
                    'AI search is temporarily unavailable. Your Amazee.ai trial budget has been exceeded.'
                );

                return redirect()->back();
            }

            return new JsonResponse(
                ['error' => 'AI service temporarily unavailable: Amazee.ai budget exceeded.'],
                503,
                ['Retry-After' => '3600'],
            );
        }
    }
}
