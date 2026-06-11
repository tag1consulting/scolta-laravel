<?php

declare(strict_types=1);

/**
 * Amazee.ai settings routes.
 *
 * These are web (session-aware) routes for the admin settings UI.
 * The prefix and middleware are configurable via config('scolta.amazee_route_prefix')
 * and config('scolta.amazee_middleware').
 *
 * SECURITY: this file is only loaded when 'scolta.amazee_middleware' is
 * configured beyond the bare ['web'] group (e.g. ['web', 'auth']) — these
 * routes can wipe stored AI credentials (disconnect) and start trials, so
 * they must never be reachable anonymously. With the shipped default the
 * routes are not registered at all. See ScoltaServiceProvider::amazeeAdminRoutesEnabled().
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
