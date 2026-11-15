<?php

use App\Http\Controllers\Auth\SteamController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyImageController;
use App\Http\Controllers\CompanyMembershipController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\DeliveryReactionController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\LinkDeviceController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\VersionController;
use App\Support\ImageStore;
use Illuminate\Support\Facades\Route;

// What's deployed (playbook #4445). Outside the web group: no session, no cookies.
Route::get('version', VersionController::class)->withoutMiddleware('web')->name('version');

// Uploaded pictures (#4644), straight from the public disk. No session or cookies either.
Route::get('media/{kind}/{file}', MediaController::class)
    ->whereIn('kind', ['avatars', 'logos', 'banners'])
    ->where('file', ImageStore::FILE_PATTERN)
    ->withoutMiddleware('web')
    ->name('media');

// Public pages: anyone can browse drivers, jobs, companies and leaderboards.
Route::get('/', HomeController::class)->name('home');
Route::get('leaderboards', LeaderboardController::class)->name('leaderboards');
Route::get('map', MapController::class)->name('map');
Route::get('download', [DownloadController::class, 'show'])->name('download');
// Permanent installer links: /download/windows (setup .exe) and /download/windows/msi.
Route::get('download/windows/{kind?}', [DownloadController::class, 'windows'])
    ->whereIn('kind', ['exe', 'msi'])
    ->name('download.windows');
Route::get('drivers', [DriverController::class, 'index'])->name('drivers.index');
Route::get('drivers/{driver:username}', [DriverController::class, 'show'])->name('drivers.show');
Route::get('jobs/{delivery}', [DeliveryController::class, 'show'])->whereNumber('delivery')->name('jobs.show');
Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
Route::get('privacy', [LegalController::class, 'privacy'])->name('privacy');
Route::get('terms', [LegalController::class, 'terms'])->name('terms');

// Sign in with Steam.
Route::middleware('guest')->group(function () {
    Route::get('auth/steam/complete', [SteamController::class, 'complete'])->name('steam.complete');
    Route::post('auth/steam/complete', [SteamController::class, 'store'])->name('steam.store');
});
Route::get('auth/steam', [SteamController::class, 'redirect'])->name('steam.redirect');
Route::get('auth/steam/callback', [SteamController::class, 'callback'])->name('steam.callback');
Route::get('auth/steam/confirm', [SteamController::class, 'confirm'])->middleware('auth')->name('steam.confirm');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Approving ProjectTrucks' link code.
    Route::get('link', [LinkDeviceController::class, 'show'])->name('link.show');
    Route::post('link', [LinkDeviceController::class, 'update'])->middleware('throttle:20,1')->name('link.update');

    Route::post('reports', [ReportController::class, 'store'])->middleware('throttle:10,1')->name('reports.store');

    // The header bell (#4643).
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{notification}', [NotificationController::class, 'open'])->whereUuid('notification')->name('notifications.open');

    Route::patch('jobs/{delivery}', [DeliveryController::class, 'update'])->whereNumber('delivery')->name('jobs.update');

    // Kudos and comments (#4646).
    Route::post('jobs/{delivery}/kudos', [DeliveryReactionController::class, 'giveKudos'])
        ->whereNumber('delivery')->middleware('throttle:60,1')->name('jobs.kudos.give');
    Route::delete('jobs/{delivery}/kudos', [DeliveryReactionController::class, 'takeKudos'])
        ->whereNumber('delivery')->middleware('throttle:60,1')->name('jobs.kudos.take');
    Route::post('jobs/{delivery}/comments', [DeliveryReactionController::class, 'comment'])
        ->whereNumber('delivery')->middleware('throttle:10,1')->name('jobs.comments.store');
    Route::delete('jobs/{delivery}/comments/{comment}', [DeliveryReactionController::class, 'deleteComment'])
        ->whereNumber(['delivery', 'comment'])->middleware('throttle:30,1')->name('jobs.comments.destroy');

    Route::get('companies/create', [CompanyController::class, 'create'])->name('companies.create');
    Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
    Route::get('companies/{company:slug}/edit', [CompanyController::class, 'edit'])->name('companies.edit');
    Route::put('companies/{company:slug}', [CompanyController::class, 'update'])->name('companies.update');
    Route::delete('companies/{company:slug}', [CompanyController::class, 'destroy'])->name('companies.destroy');
    Route::post('companies/{company:slug}/images/{kind}', [CompanyImageController::class, 'store'])
        ->whereIn('kind', ['logo', 'banner'])
        ->middleware('throttle:20,1')
        ->name('companies.images.store');
    Route::delete('companies/{company:slug}/images/{kind}', [CompanyImageController::class, 'destroy'])
        ->whereIn('kind', ['logo', 'banner'])
        ->name('companies.images.destroy');

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
