<?php

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
use Tag1\ScoltaLaravel\Http\Controllers\SummarizeController;
use Tag1\ScoltaLaravel\Http\Controllers\FollowUpController;

Route::group([
    'prefix' => config('scolta.route_prefix', 'api/scolta/v1'),
    'middleware' => config('scolta.middleware', ['api']),
], function () {
    Route::post('/expand-query', ExpandQueryController::class)->name('scolta.expand');
    Route::post('/summarize', SummarizeController::class)->name('scolta.summarize');
    Route::post('/followup', FollowUpController::class)->name('scolta.followup');
});
