<?php

namespace App\Providers;

use App\Admin\QueueStats;
use App\Admin\SchedulerStatus;
use App\Auth\SteamProvider;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
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
        $this->configureAuthMail();

        // Our subclass of the Steam provider: OpenID sign-in that works without a Web API key.
        Event::listen(SocialiteWasCalled::class, fn (SocialiteWasCalled $event) => $event->extendSocialite('steam', SteamProvider::class));

        // Sentry (#4653): the signed-in user's id and nothing else (send_default_pii is off, so
        // no name, email, IP or cookies). Inert while SENTRY_LARAVEL_DSN is empty.
        Event::listen(Authenticated::class, function (Authenticated $event): void {
            configureScope(fn (Scope $scope) => $scope->setUser(['id' => $event->user->getAuthIdentifier()]));
        });

        // Scheduler status for the admin panel (#4666): a heartbeat each time cron starts
        // `schedule:run`, and each scheduled task's last run.
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if ($event->command === 'schedule:run') {
                SchedulerStatus::beat();
            }
        });
        Event::listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $event) => SchedulerStatus::record($event->task, $event->task->exitCode === 0 || $event->task->exitCode === null, $event->runtime));
        Event::listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $event) => SchedulerStatus::record($event->task, false, $event->runtime));

        // Queue throughput and run times for the admin Queue page (#4666).
        Event::listen(JobProcessing::class, fn (JobProcessing $event) => QueueStats::started($event->connectionName, $event->job));
        Event::listen(JobProcessed::class, fn (JobProcessed $event) => QueueStats::finished($event->connectionName, $event->job, failed: false));
        Event::listen(JobFailed::class, fn (JobFailed $event) => QueueStats::finished($event->connectionName, $event->job, failed: true));
    }

    /**
     * Our own wording for Fortify's verify-email and reset-password mails (#4661). Both render
     * with the Hub mail theme (config/mail.php `markdown`); the links are Laravel's own.
     */
    protected function configureAuthMail(): void
    {
        VerifyEmail::toMailUsing(function (object $notifiable, string $url): MailMessage {
            $app = (string) config('app.name');

            return (new MailMessage)
                ->subject(__('Confirm your email for :app', ['app' => $app]))
                ->greeting(__('Welcome to :app!', ['app' => $app]))
                ->line(__('Confirm your email address to finish setting up your account. Then link the ProjectTrucks app and your jobs will show up on your profile.'))
                ->action(__('Confirm email address'), $url)
                ->line(__('If you didn\'t create an account, you can ignore this email.'));
        });

        ResetPassword::toMailUsing(function (object $notifiable, string $token): MailMessage {
            /** @var User $notifiable */
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));
            $broker = (string) config('auth.defaults.passwords');
            $minutes = (int) config("auth.passwords.{$broker}.expire");

            return (new MailMessage)
                ->subject(__('Set a new password for :app', ['app' => config('app.name')]))
                ->greeting(__('Hello!'))
                ->line(__('Someone asked to set a new password for your account. If it was you, choose one here:'))
                ->action(__('Choose a new password'), $url)
                ->line(__('This link works for :count minutes.', ['count' => $minutes]))
                ->line(__('If you didn\'t ask for this, you can ignore this email: your password stays as it is.'));
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
