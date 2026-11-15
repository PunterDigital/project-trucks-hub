<?php

use App\Http\Controllers\Auth\SteamController;
use App\Http\Controllers\Settings\ConnectionsController;
use App\Http\Controllers\Settings\DevicesController;
use App\Http\Controllers\Settings\NotificationSettingsController;
use App\Http\Controllers\Settings\PrivacyController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    Route::get('settings/devices', [DevicesController::class, 'index'])->name('devices.index');
    Route::delete('settings/devices/{token}', [DevicesController::class, 'destroy'])->whereNumber('token')->name('devices.destroy');

    Route::get('settings/privacy', [PrivacyController::class, 'edit'])->name('privacy-settings.edit');
    Route::get('settings/privacy/export', [PrivacyController::class, 'export'])
        ->middleware('throttle:6,1')
        ->name('privacy-settings.export');
    Route::delete('settings/privacy/jobs', [PrivacyController::class, 'destroyJobs'])->name('privacy-settings.jobs.destroy');

    Route::get('settings/notifications', [NotificationSettingsController::class, 'edit'])->name('notification-settings.edit');
    Route::put('settings/notifications', [NotificationSettingsController::class, 'update'])->name('notification-settings.update');

    Route::get('settings/connections', [ConnectionsController::class, 'edit'])->name('connections.edit');
    Route::delete('settings/connections/steam', [SteamController::class, 'destroy'])->name('steam.destroy');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
