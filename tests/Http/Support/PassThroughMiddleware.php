<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Http\Support;

use Closure;
use Illuminate\Http\Request;

/**
 * Test middleware that lets every request through.
 *
 * Stands in for a satisfied auth middleware in feature tests, proving
 * that configured middleware restores access to gated routes.
 */
class PassThroughMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        return $next($request);
    }
}
