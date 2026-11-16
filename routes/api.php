<?php

use App\Http\Controllers\Api\DeviceAuthorizationController;
use App\Http\Controllers\Api\JobSyncController;
use App\Http\Controllers\Api\LiveStatusController;
use App\Http\Controllers\Api\MeController;
use App\Http\Middleware\RecordApiUsage;
use Illuminate\Support\Facades\Route;

// The API ProjectTrucks uses. Documented in docs/api.md. `RecordApiUsage` is outermost so
// throttled and unauthenticated answers are counted too (admin Data usage page, #4667).
Route::prefix('v1')->name('api.')->middleware(RecordApiUsage::class)->group(function () {
    Route::post('device/code', [DeviceAuthorizationController::class, 'code'])
        ->middleware('throttle:device-code')
        ->name('device.code');
    Route::post('device/token', [DeviceAuthorizationController::class, 'token'])
        ->middleware('throttle:device-token')
        ->name('device.token');

    Route::middleware(['auth:sanctum', 'ability:sync', 'throttle:sync'])->group(function () {
        Route::get('me', [MeController::class, 'show'])->name('me');
        Route::delete('token', [MeController::class, 'destroyToken'])->name('token.destroy');

        Route::post('jobs', [JobSyncController::class, 'store'])->name('jobs.store');
        Route::delete('jobs/{clientId}', [JobSyncController::class, 'destroy'])
            ->where('clientId', '[A-Za-z0-9_-]{1,64}')
            ->name('jobs.destroy');
        Route::patch('jobs/{clientId}', [JobSyncController::class, 'update'])
            ->where('clientId', '[A-Za-z0-9_-]{1,64}')
            ->name('jobs.update');

        Route::put('status', [LiveStatusController::class, 'update'])->name('status.update');
        Route::delete('status', [LiveStatusController::class, 'destroy'])->name('status.destroy');
    });
});
