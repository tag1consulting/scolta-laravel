<?php

/**
 * Scolta API routes.
 *
 * Endpoints match the exact same paths used by Drupal and WordPress,
 * so scolta.js works identically across all three platforms.
 *
 * Rate limiting is applied via the 'scolta' rate limiter defined in
 * the service provider. Set SCOLTA_RATE_LIMIT=0 in .env to disable.
 */

use Illuminate\Support\Facades\Route;
use Tag1\ScoltaLaravel\Http\Controllers\ExpandQueryController;
use Tag1\ScoltaLaravel\Http\Controllers\FollowUpController;
use Tag1\ScoltaLaravel\Http\Controllers\HealthController;
use Tag1\ScoltaLaravel\Http\Controllers\SummarizeController;

$middleware = config('scolta.middleware', ['api']);
if (config('scolta.rate_limit', 30) > 0) {
    $middleware[] = 'throttle:scolta';
}

Route::group([
    'prefix' => config('scolta.route_prefix', 'api/scolta/v1'),
    'middleware' => $middleware,
], function () {
    Route::post('/expand-query', ExpandQueryController::class)->name('scolta.expand');
    Route::post('/summarize', SummarizeController::class)->name('scolta.summarize');
    Route::post('/followup', FollowUpController::class)->name('scolta.followup');
});

// Health check — separate middleware group (optionally public).
Route::get(
    config('scolta.route_prefix', 'api/scolta/v1') . '/health',
    HealthController::class
)->middleware(config('scolta.health_middleware', ['api']))->name('scolta.health');
