<?php

use App\Http\Controllers\Auth\SteamController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyMembershipController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\LinkDeviceController;
use App\Http\Controllers\VersionController;
use Illuminate\Support\Facades\Route;

// What's deployed (playbook #4445). Outside the web group: no session, no cookies.
Route::get('version', VersionController::class)->withoutMiddleware('web')->name('version');

// Public pages: anyone can browse drivers, jobs, companies and leaderboards.
Route::get('/', HomeController::class)->name('home');
Route::get('leaderboards', LeaderboardController::class)->name('leaderboards');
Route::get('drivers', [DriverController::class, 'index'])->name('drivers.index');
Route::get('drivers/{driver:username}', [DriverController::class, 'show'])->name('drivers.show');
Route::get('jobs/{delivery}', [DeliveryController::class, 'show'])->whereNumber('delivery')->name('jobs.show');
Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');

// Sign in with Steam.
Route::middleware('guest')->group(function () {
    Route::get('auth/steam/complete', [SteamController::class, 'complete'])->name('steam.complete');
    Route::post('auth/steam/complete', [SteamController::class, 'store'])->name('steam.store');
});
Route::get('auth/steam', [SteamController::class, 'redirect'])->name('steam.redirect');
Route::get('auth/steam/callback', [SteamController::class, 'callback'])->name('steam.callback');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Approving ProjectTrucks' link code.
    Route::get('link', [LinkDeviceController::class, 'show'])->name('link.show');
    Route::post('link', [LinkDeviceController::class, 'update'])->middleware('throttle:20,1')->name('link.update');

    Route::patch('jobs/{delivery}', [DeliveryController::class, 'update'])->whereNumber('delivery')->name('jobs.update');

    Route::get('companies/create', [CompanyController::class, 'create'])->name('companies.create');
    Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
    Route::get('companies/{company:slug}/edit', [CompanyController::class, 'edit'])->name('companies.edit');
    Route::put('companies/{company:slug}', [CompanyController::class, 'update'])->name('companies.update');
    Route::delete('companies/{company:slug}', [CompanyController::class, 'destroy'])->name('companies.destroy');

    Route::post('companies/{company:slug}/join', [CompanyMembershipController::class, 'join'])->name('companies.join');
    Route::post('companies/{company:slug}/apply', [CompanyMembershipController::class, 'apply'])->name('companies.apply');
    Route::delete('companies/{company:slug}/application', [CompanyMembershipController::class, 'withdraw'])->name('companies.withdraw');
    Route::post('companies/{company:slug}/leave', [CompanyMembershipController::class, 'leave'])->name('companies.leave');
    Route::post('companies/{company:slug}/applications/{application}', [CompanyMembershipController::class, 'decide'])->name('companies.applications.decide');
    Route::patch('companies/{company:slug}/members/{member}', [CompanyMembershipController::class, 'update'])->name('companies.members.update');
    Route::delete('companies/{company:slug}/members/{member}', [CompanyMembershipController::class, 'destroy'])->name('companies.members.destroy');
});

// After the auth routes so /companies/create isn't read as a company slug.
Route::get('companies/{company:slug}', [CompanyController::class, 'show'])->name('companies.show');

require __DIR__.'/settings.php';
