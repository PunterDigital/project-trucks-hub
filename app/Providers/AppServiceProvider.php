<?php

namespace App\Providers;

use App\Auth\SteamProvider;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Sentry\State\Scope;
use SocialiteProviders\Manager\SocialiteWasCalled;

use function Sentry\configureScope;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();

        // Our subclass of the Steam provider: OpenID sign-in that works without a Web API key.
        Event::listen(SocialiteWasCalled::class, fn (SocialiteWasCalled $event) => $event->extendSocialite('steam', SteamProvider::class));

        // Sentry (#4653): the signed-in user's id and nothing else (send_default_pii is off, so
        // no name, email, IP or cookies). Inert while SENTRY_LARAVEL_DSN is empty.
        Event::listen(Authenticated::class, function (Authenticated $event): void {
            configureScope(fn (Scope $scope) => $scope->setUser(['id' => $event->user->getAuthIdentifier()]));
        });
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('device-code', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        // The app polls every 5 s for up to 15 minutes; allow some slack for several PCs behind one IP.
        RateLimiter::for('device-token', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        // Runs after auth:sanctum, so there is always a user.
        RateLimiter::for('sync', fn (Request $request) => Limit::perMinute(120)->by('sync:'.$request->user()->id));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
