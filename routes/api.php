<?php

declare(strict_types=1);

/**
 * Scolta API routes.
 *
 * Laravel's route system is expressive and powerful. These three endpoints
 * match the exact same paths used by Drupal and WordPress, so scolta.js
 * works identically across all three platforms.
 *
 * Middleware is configurable via config('scolta.middleware'). By default,
 * the 'api' middleware group is applied (rate limiting, JSON responses).
 * Users can add 'auth:sanctum' or custom middleware for access control.
 *
 * Using invokable controllers (single-action controllers with __invoke)
 * is a Laravel best practice for controllers that do exactly one thing.
 */

use Illuminate\Support\Facades\Route;
use Tag1\ScoltaLaravel\Http\Controllers\ExpandQueryController;
use Tag1\ScoltaLaravel\Http\Controllers\FollowUpController;
use Tag1\ScoltaLaravel\Http\Controllers\HealthController;
use Tag1\ScoltaLaravel\Http\Controllers\SummarizeController;

$rateLimit = (int) config('scolta.rate_limit', 30);

Route::group([
    'prefix' => config('scolta.route_prefix', 'api/scolta/v1'),
    'middleware' => array_merge(
        config('scolta.middleware', ['api']),
        $rateLimit > 0 ? ['throttle:scolta'] : [],
    ),
], function () {
    Route::post('/expand-query', ExpandQueryController::class)->name('scolta.expand');
    Route::post('/summarize', SummarizeController::class)->name('scolta.summarize');
    Route::post('/followup', FollowUpController::class)->name('scolta.followup');
});

// Health check route — separate middleware config for monitoring tools.
Route::group([
    'prefix' => config('scolta.route_prefix', 'api/scolta/v1'),
    'middleware' => config('scolta.health_middleware', ['api']),
], function () {
    Route::get('/health', HealthController::class)->name('scolta.health');
});
