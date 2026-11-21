<?php

use App\Http\Controllers\Auth\SteamController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\CompanyApplicationQuestionController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyDiscordController;
use App\Http\Controllers\CompanyEventController;
use App\Http\Controllers\CompanyGoalController;
use App\Http\Controllers\CompanyImageController;
use App\Http\Controllers\CompanyInviteController;
use App\Http\Controllers\CompanyMembershipController;
use App\Http\Controllers\CompanyNewsController;
use App\Http\Controllers\CompanyRankController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\DeliveryReactionController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\DriverController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\GuideController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\LinkDeviceController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PreviewImageController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\VersionController;
use App\Models\CompanyInvite;
use App\Models\Post;
use App\Support\Guides;
use App\Support\ImageStore;
use App\Support\LinkPreview;
use Illuminate\Support\Facades\Route;

// What's deployed (playbook #4445). Outside the web group: no session, no cookies.
Route::get('version', VersionController::class)->withoutMiddleware('web')->name('version');

// Search engines (#5149): public pages, drivers, companies and events. No session or cookies.
// robots.txt is a static file in public/ (Forge's nginx serves /robots.txt itself).
Route::get('sitemap.xml', SitemapController::class)->withoutMiddleware('web')->name('sitemap');

// Uploaded pictures (#4644), straight from the public disk. No session or cookies either.
Route::get('media/{kind}/{file}', MediaController::class)
    ->whereIn('kind', ['avatars', 'logos', 'banners', 'covers'])
    ->where('file', ImageStore::FILE_PATTERN)
    ->withoutMiddleware('web')
    ->name('media');

// Link-preview cards (#4652): a guest's view of the item, no session or cookies.
Route::get('og/{type}/{id}.png', PreviewImageController::class)
    ->whereIn('type', LinkPreview::TYPES)
    ->whereNumber('id')
    ->withoutMiddleware('web')
    ->name('preview-image');

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
// Company invite links (#5221): the page is public, so a guest sees what they're invited to.
Route::get('invite/{token}', [CompanyInviteController::class, 'show'])
    ->where('token', CompanyInvite::TOKEN_PATTERN)
    ->middleware('throttle:30,1')
    ->name('invites.show');
// Company events and convoys (#4648).
Route::get('events', [CompanyEventController::class, 'index'])->name('events.index');
Route::get('events/{event}', [CompanyEventController::class, 'show'])->whereNumber('event')->name('events.show');
Route::get('events/{event}.ics', [CompanyEventController::class, 'calendar'])->whereNumber('event')->name('events.calendar');
// Blog (#5150). The feed sets no cookies; the preview is for staff (404 for anyone else).
Route::get('blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('blog/feed', [BlogController::class, 'feed'])->withoutMiddleware('web')->name('blog.feed');
Route::get('blog/preview/{post}', [BlogController::class, 'preview'])->whereNumber('post')->middleware('auth')->name('blog.preview');
Route::get('blog/{post:slug}', [BlogController::class, 'show'])->where('post', Post::SLUG_PATTERN)->name('blog.show');
// Help guides (#5151), from resources/guides.
Route::get('guides', [GuideController::class, 'index'])->name('guides.index');
Route::get('guides/{guide}', [GuideController::class, 'show'])->where('guide', Guides::SLUG_PATTERN)->name('guides.show');
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

    // Following drivers (#4647).
    Route::post('drivers/{driver:username}/follow', [FollowController::class, 'store'])
        ->middleware('throttle:60,1')->name('drivers.follow');
    Route::delete('drivers/{driver:username}/follow', [FollowController::class, 'destroy'])
        ->middleware('throttle:60,1')->name('drivers.unfollow');

    Route::get('companies/create', [CompanyController::class, 'create'])->name('companies.create');
    Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
    Route::get('companies/{company:slug}/edit', [CompanyController::class, 'edit'])->name('companies.edit');
    Route::put('companies/{company:slug}', [CompanyController::class, 'update'])->name('companies.update');
    // Discord webhook (#4652): owners and managers.
    Route::put('companies/{company:slug}/discord', [CompanyDiscordController::class, 'update'])
        ->middleware('throttle:20,1')->name('companies.discord.update');
    Route::post('companies/{company:slug}/discord/test', [CompanyDiscordController::class, 'test'])
        ->middleware('throttle:6,1')->name('companies.discord.test');
    Route::delete('companies/{company:slug}/discord', [CompanyDiscordController::class, 'destroy'])->name('companies.discord.destroy');
    Route::delete('companies/{company:slug}', [CompanyController::class, 'destroy'])->name('companies.destroy');
    // Company goals (#4649): owners and managers set this month's targets.
    Route::put('companies/{company:slug}/questions', [CompanyApplicationQuestionController::class, 'update'])
        ->middleware('throttle:20,1')->name('companies.questions.update');
    Route::put('companies/{company:slug}/goals', [CompanyGoalController::class, 'update'])
        ->middleware('throttle:20,1')->name('companies.goals.update');
    // Company ranks (#5223): the list on the edit page, and a pin per member.
    Route::put('companies/{company:slug}/ranks', [CompanyRankController::class, 'update'])
        ->middleware('throttle:20,1')->name('companies.ranks.update');
    Route::put('companies/{company:slug}/members/{member}/rank', [CompanyRankController::class, 'pin'])
        ->whereNumber('member')->middleware('throttle:30,1')->name('companies.members.rank');
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

    // Invite links (#5221).
    Route::post('companies/{company:slug}/invites', [CompanyInviteController::class, 'store'])
        ->middleware('throttle:20,1')->name('companies.invites.store');
    Route::delete('companies/{company:slug}/invites/{invite}', [CompanyInviteController::class, 'destroy'])
        ->whereNumber('invite')->name('companies.invites.destroy');
    Route::post('invite/{token}', [CompanyInviteController::class, 'accept'])
        ->where('token', CompanyInvite::TOKEN_PATTERN)
        ->middleware('throttle:10,1')->name('invites.accept');

    Route::get('companies/{company:slug}/events/create', [CompanyEventController::class, 'create'])->name('companies.events.create');
    Route::post('companies/{company:slug}/events', [CompanyEventController::class, 'store'])->middleware('throttle:20,1')->name('companies.events.store');
    Route::get('events/{event}/edit', [CompanyEventController::class, 'edit'])->whereNumber('event')->name('events.edit');
    Route::put('events/{event}', [CompanyEventController::class, 'update'])->whereNumber('event')->name('events.update');
    Route::post('events/{event}/cancel', [CompanyEventController::class, 'cancel'])->whereNumber('event')->name('events.cancel');
    Route::post('events/{event}/rsvp', [CompanyEventController::class, 'rsvp'])->whereNumber('event')->middleware('throttle:30,1')->name('events.rsvp');

    // Company news (#5224).
    Route::get('companies/{company:slug}/news/create', [CompanyNewsController::class, 'create'])->name('companies.news.create');
    Route::post('companies/{company:slug}/news', [CompanyNewsController::class, 'store'])->middleware('throttle:10,1')->name('companies.news.store');
    Route::get('news/{post}/edit', [CompanyNewsController::class, 'edit'])->whereNumber('post')->name('news.edit');
    Route::put('news/{post}', [CompanyNewsController::class, 'update'])->whereNumber('post')->middleware('throttle:20,1')->name('news.update');
    Route::put('news/{post}/pin', [CompanyNewsController::class, 'pin'])->whereNumber('post')->middleware('throttle:30,1')->name('news.pin');
    Route::delete('news/{post}', [CompanyNewsController::class, 'destroy'])->whereNumber('post')->name('news.destroy');
});

// After the auth routes so /companies/create isn't read as a company slug.
Route::get('companies/{company:slug}', [CompanyController::class, 'show'])->name('companies.show');

require __DIR__.'/settings.php';
