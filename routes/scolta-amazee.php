<?php

declare(strict_types=1);

/**
 * Amazee.ai settings routes.
 *
 * These are web (session-aware) routes for the admin settings UI.
 * The prefix and middleware are configurable via config('scolta.amazee_route_prefix')
 * and config('scolta.amazee_middleware').
 *
 * Default: /scolta/amazee — add auth middleware to protect these in production.
 *
 * Usage: Route::middleware(['web','auth'])->group(base_path('routes/scolta-amazee.php'));
 */

use Illuminate\Support\Facades\Route;
use Tag1\ScoltaLaravel\Http\Controllers\AmazeeSettingsController;

Route::group([
    'prefix' => config('scolta.amazee_route_prefix', 'scolta/amazee'),
    'middleware' => config('scolta.amazee_middleware', ['web']),
], function () {
    Route::get('/', [AmazeeSettingsController::class, 'show'])->name('scolta.amazee.show');
    Route::post('/trial', [AmazeeSettingsController::class, 'startTrial'])->name('scolta.amazee.trial');
    Route::post('/request-code', [AmazeeSettingsController::class, 'requestCode'])->name('scolta.amazee.request-code');
    Route::post('/verify-code', [AmazeeSettingsController::class, 'verifyCode'])->name('scolta.amazee.verify-code');
    Route::get('/regions', [AmazeeSettingsController::class, 'listRegions'])->name('scolta.amazee.regions');
    Route::post('/connect', [AmazeeSettingsController::class, 'connect'])->name('scolta.amazee.connect');
    Route::delete('/disconnect', [AmazeeSettingsController::class, 'disconnect'])->name('scolta.amazee.disconnect');
});
