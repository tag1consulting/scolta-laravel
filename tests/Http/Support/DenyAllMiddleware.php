<?php

declare(strict_types=1);

namespace Tag1\ScoltaLaravel\Tests\Http\Support;

use Closure;
use Illuminate\Http\Request;

/**
 * Test middleware that rejects every request with 403.
 *
 * Stands in for an auth/gate middleware in feature tests so route
 * protection can be asserted without a full auth scaffold.
 */
class DenyAllMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort(403);
    }
}
