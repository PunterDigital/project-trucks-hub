<?php

namespace Database\Seeders;

use App\Enums\CompanyRole;
use App\Enums\PostStatus;
use App\Enums\Recruitment;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\LiveStatus;
use App\Models\Post;
use App\Models\User;
use App\Support\LatestRelease;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Data for the Playwright suite (tests/e2e). Add new records at the END, so the ids of
 * existing ones never shift under older specs. Every account's password is "password".
 */
class E2ESeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('password');

        // A driver with no company and nothing synced: the spec links ProjectTrucks as them.
        User::query()->create([
            'name' => 'Erin Example',
            'username' => 'erin',
            'email' => 'erin@example.com',
            'password' => $password,
        ])->forceFill(['email_verified_at' => now()])->save();

        // An owner with a company that takes applications, plus some history.
        $owner = User::query()->create([
            'name' => 'Olga Owner',
            'username' => 'olga',
            'email' => 'olga@example.com',
            'password' => $password,
        ]);
        $owner->forceFill(['email_verified_at' => now()])->save();

        $company = Company::query()->create([
            'name' => 'Nordic Haulage',
            'slug' => 'nordic-haulage',
            'tag' => 'NRDC',
            'description' => 'Scandinavia and the Baltics. Convoys on Sundays.',
            'recruitment' => Recruitment::Apply,
        ]);
        $company->members()->create(['user_id' => $owner->id, 'role' => CompanyRole::Owner]);

        Delivery::factory()->for($owner)->create([
            'company_id' => $company->id,
            'source_city' => 'Oslo',
            'dest_city' => 'Stockholm',
            'cargo_name' => 'Fish',
            'distance_km' => 530,
            'income' => 24000,
            'completed_at' => now()->subHours(3),
        ]);

        LiveStatus::query()->create([
            'user_id' => $owner->id,
            'truck_make' => 'Volvo',
            'truck_model' => 'FH',
            'cargo_name' => 'Timber',
            'cargo_mass_kg' => 21000,
            'source_city' => 'Malmö',
            'dest_city' => 'Göteborg',
            'driven_km' => 120,
            'distance_left_km' => 150,
            'progress_pct' => 44.4,
            'updated_at' => now()->addDays(30), // stays "on the road" for the whole run
        ]);

        // An open company.
        Company::query()->create([
            'name' => 'Open Road Co',
            'slug' => 'open-road-co',
            'tag' => 'ORC',
            'description' => 'Everyone welcome.',
            'recruitment' => Recruitment::Open,
        ]);

        // The latest ProjectTrucks release, so /download never asks GitHub during E2E.
        $release = [
            'version' => '0.7.0',
            'published_at' => '2026-11-14T13:56:02Z',
            'notes_url' => 'https://github.com/PunterDigital/project-trucks/releases/tag/v0.7.0',
            'installers' => [
                [
                    'kind' => 'exe',
                    'name' => 'ProjectTrucks_0.7.0_x64-setup.exe',
                    'size' => 4684067,
                    'url' => 'https://github.com/PunterDigital/project-trucks/releases/download/v0.7.0/ProjectTrucks_0.7.0_x64-setup.exe',
                    'sha256' => '19915b3ab49999fbbec4c11159b79033e5ee57181064269dbf0a0a4f0adb7af7',
                ],
                [
                    'kind' => 'msi',
                    'name' => 'ProjectTrucks_0.7.0_x64_en-US.msi',
                    'size' => 6668288,
                    'url' => 'https://github.com/PunterDigital/project-trucks/releases/download/v0.7.0/ProjectTrucks_0.7.0_x64_en-US.msi',
                    'sha256' => '2cea6156fb6001b0e9921828a6812970873fa29246cb601f36311d6f2ca55922',
                ],
            ],
        ];
        Cache::forever(LatestRelease::CACHE_KEY, $release);
        Cache::forever(LatestRelease::LAST_GOOD_KEY, $release);

        // Everyone above chose their password.
        User::query()->update(['password_set_at' => now()]);

        // A Steam sign-up that never chose a password (#4672). Its hash is "password" only
        // so E2E can log in; the app treats it as unknown (password_set_at is null).
        $steve = User::query()->create([
            'name' => 'Steve Steam',
            'email' => 'steve@example.com',
            'username' => 'steve-steam',
            'password' => $password,
        ]);
        $steve->forceFill(['steam_id' => '76561198000000042', 'email_verified_at' => now()])->save();

        // Privacy settings (#4641): a driver whose jobs privacy.spec deletes.
        $paula = User::query()->create([
            'name' => 'Paula Privacy',
            'email' => 'paula@example.com',
            'username' => 'paula-privacy',
            'password' => $password,
        ]);
        $paula->forceFill(['email_verified_at' => now(), 'password_set_at' => now()])->save();
        Delivery::factory()->for($paula)->create([
            'source_city' => 'Lyon',
            'dest_city' => 'Milano',
            'cargo_name' => 'Cheese',
            'completed_at' => now()->subHours(2),
        ]);

        // Admin panel (#4664): staff with two-factor on. admin.spec computes the TOTP code
        // from this secret (base32), the way an authenticator app would.
        $sam = User::query()->create([
            'name' => 'Sam Staff',
            'email' => 'sam@example.com',
            'username' => 'sam-staff',
            'password' => $password,
        ]);
        $sam->forceFill([
            'email_verified_at' => now(),
            'password_set_at' => now(),
            'is_staff' => true,
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt((string) json_encode(['e2e-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        // Live map (#4687): one driver on a job, one free-roaming, one private (never shown).
        // `updated_at` a day ahead keeps them "fresh" however late map.spec runs.
        foreach ([
            ['Mona Map', 'mona-map', false, ['cargo_name' => 'Timber', 'source_city' => 'Hamburg', 'dest_city' => 'Wien', 'pos_x' => -3000.0, 'pos_z' => -15000.0, 'heading_deg' => 180.0]],
            ['Fred Free', 'fred-free', false, ['pos_x' => 2000.0, 'pos_z' => 1000.0, 'heading_deg' => 90.0]],
            ['Pia Private', 'pia-private', true, ['pos_x' => 0.0, 'pos_z' => 0.0, 'heading_deg' => 0.0]],
        ] as [$name, $username, $private, $live]) {
            $driver = User::query()->create([
                'name' => $name,
                'email' => $username.'@example.com',
                'username' => $username,
                'password' => $password,
            ]);
            $driver->forceFill(['email_verified_at' => now(), 'password_set_at' => now(), 'profile_private' => $private])->save();
            LiveStatus::query()->create([
                'user_id' => $driver->id,
                'truck_make' => 'Renault',
                'truck_model' => 'T',
                'updated_at' => now()->addDay(),
                ...$live,
            ]);
        }

        // Auto-refresh (#4723): renames herself while a guest watches her profile.
        $rita = User::query()->create([
            'name' => 'Rita Refresh',
            'email' => 'rita-refresh@example.com',
            'username' => 'rita-refresh',
            'password' => $password,
        ]);
        $rita->forceFill(['email_verified_at' => now(), 'password_set_at' => now()])->save();

        // Moderation (#4651): Rex reports Troll Tom, Mo (staff, own 2FA secret so the TOTP
        // codes don't clash with Sam's) resolves it and suspends Susie.
        foreach ([
            ['Rex Reporter', 'rex-report'],
            ['Troll Tom', 'troll-tom'],
            ['Susie Suspend', 'susie-suspend'],
            ['Mo Moderator', 'mo-mod'],
        ] as [$name, $username]) {
            $user = User::query()->create([
                'name' => $name,
                'email' => $username.'@example.com',
                'username' => $username,
                'password' => $password,
            ]);
            $user->forceFill(['email_verified_at' => now(), 'password_set_at' => now()])->save();
        }
        User::query()->where('username', 'mo-mod')->firstOrFail()->forceFill([
            'is_staff' => true,
            'two_factor_secret' => encrypt('MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U'),
            'two_factor_recovery_codes' => encrypt((string) json_encode(['e2e-recovery-code-mo'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        // Notifications (#4643): Abe applies to Nora's Bell Freight, Nora accepts from the bell.
        foreach ([['Nora Notify', 'nora-notify'], ['Abe Apply', 'abe-apply']] as [$name, $username]) {
            $user = User::query()->create([
                'name' => $name,
                'email' => $username.'@example.com',
                'username' => $username,
                'password' => $password,
            ]);
            $user->forceFill(['email_verified_at' => now(), 'password_set_at' => now()])->save();
        }
        $bell = Company::query()->create([
            'name' => 'Bell Freight',
            'slug' => 'bell-freight',
            'tag' => 'BELL',
            'description' => 'Night runs, mostly.',
            'recruitment' => Recruitment::Apply,
        ]);
        $bell->members()->create([
            'user_id' => User::query()->where('username', 'nora-notify')->value('id'),
            'role' => CompanyRole::Owner,
        ]);

        // Uploads (#4644): Uma sets a profile picture and her company's logo and banner.
        $uma = User::query()->create([
            'name' => 'Uma Upload',
            'email' => 'uma-upload@example.com',
            'username' => 'uma-upload',
            'password' => $password,
        ]);
        $uma->forceFill(['email_verified_at' => now(), 'password_set_at' => now()])->save();
        $pictures = Company::query()->create([
            'name' => 'Picture Perfect Haulage',
            'slug' => 'picture-perfect-haulage',
            'tag' => 'PIX',
            'description' => 'Looks good on the leaderboard.',
            'recruitment' => Recruitment::Apply,
        ]);
        $pictures->members()->create(['user_id' => $uma->id, 'role' => CompanyRole::Owner]);

        // Kudos and comments (#4646): Kira reacts to Jack's Rotterdam → Lyon job.
        foreach ([['Kira Kudos', 'kira-kudos'], ['Jack Job', 'jack-job']] as [$name, $username]) {
            $user = User::query()->create([
                'name' => $name,
                'email' => $username.'@example.com',
                'username' => $username,
                'password' => $password,
            ]);
            $user->forceFill(['email_verified_at' => now(), 'password_set_at' => now()])->save();
        }
        Delivery::factory()->for(User::query()->where('username', 'jack-job')->firstOrFail())->create([
            'source_city' => 'Rotterdam',
            'dest_city' => 'Lyon',
            'cargo_name' => 'Tulips',
            // Short and two days old, so it stays below the jobs other specs expect at the top
            // of the feed and the leaderboards.
            'distance_km' => 120.0,
            'income' => 3000,
            'duration_sec' => 2 * 3600,
            'avg_speed_kmh' => 60,
            'started_at' => now()->subDays(2)->subHours(2),
            'completed_at' => now()->subDays(2),
        ]);

        // Company events (#4648): Eve (owner) plans a convoy, Max (member) answers, Otto
        // (no company) can only look.
        foreach ([['Eve Events', 'eve-events'], ['Max Member', 'max-member'], ['Otto Outsider', 'otto-outsider']] as [$name, $username]) {
            $user = User::query()->create([
                'name' => $name,
                'email' => $username.'@example.com',
                'username' => $username,
                'password' => $password,
            ]);
            $user->forceFill(['email_verified_at' => now(), 'password_set_at' => now()])->save();
        }
        $convoy = Company::query()->create([
            'name' => 'Convoy Club',
            'slug' => 'convoy-club',
            'tag' => 'CVY',
            'description' => 'Sunday convoys.',
            'recruitment' => Recruitment::Apply,
        ]);
        $convoy->members()->create(['user_id' => User::query()->where('username', 'eve-events')->value('id'), 'role' => CompanyRole::Owner]);
        $convoy->members()->create(['user_id' => User::query()->where('username', 'max-member')->value('id'), 'role' => CompanyRole::Driver]);

        // Charts (#4650): Cora and Cody drive for Chart Haulage. Cora's one job ends at 23:30
        // UTC eight days ago, so it falls on different days in UTC and Tokyo. Eight days is always
        // before this week (the default boards), and the accounts are backdated so they sit at
        // the end of the admin users list (newest first) that moderation.spec pages through.
        $chartCompany = Company::query()->create([
            'name' => 'Chart Haulage',
            'slug' => 'chart-haulage',
            'tag' => 'CHRT',
            'description' => 'We like numbers.',
            'recruitment' => Recruitment::Apply,
        ]);
        foreach ([['Cora Chart', 'cora-chart', 'Chart paper', 23, 120.0], ['Cody Chart', 'cody-chart', 'Graph paper', 12, 80.0]] as $i => [$name, $username, $cargo, $hour, $km]) {
            $user = User::query()->create([
                'name' => $name,
                'email' => $username.'@example.com',
                'username' => $username,
                'password' => $password,
            ]);
            $user->forceFill(['email_verified_at' => now(), 'password_set_at' => now(), 'created_at' => now()->subYear()])->save();
            $chartCompany->members()->create(['user_id' => $user->id, 'role' => $i === 0 ? CompanyRole::Owner : CompanyRole::Driver]);
            $done = now('UTC')->subDays(8)->setTime($hour, 30);
            Delivery::factory()->for($user)->create([
                'company_id' => $chartCompany->id,
                'source_city' => 'Calais',
                'dest_city' => 'Brussel',
                'cargo_name' => $cargo,
                'distance_km' => $km,
                'income' => 2000,
                'duration_sec' => 2 * 3600,
                'avg_speed_kmh' => $km / 2,
                'started_at' => $done->copy()->subHours(2),
                'completed_at' => $done,
            ]);
        }

        // Discord webhook (#4652): Dina owns Dispatch Club and connects its Discord. Backdated
        // like the chart drivers, so the admin users list's first page stays as it was.
        $dina = User::query()->create([
            'name' => 'Dina Discord',
            'email' => 'dina-discord@example.com',
            'username' => 'dina-discord',
            'password' => $password,
        ]);
        $dina->forceFill(['email_verified_at' => now(), 'password_set_at' => now(), 'created_at' => now()->subYear()])->save();
        Company::query()->create([
            'name' => 'Dispatch Club',
            'slug' => 'dispatch-club',
            'tag' => 'DSPC',
            'recruitment' => Recruitment::Apply,
        ])->members()->create(['user_id' => $dina->id, 'role' => CompanyRole::Owner]);

        // Dark theme (#4658): Tina switches themes; her own account keeps the login throttle clear.
        $tina = User::query()->create([
            'name' => 'Tina Theme',
            'email' => 'tina-theme@example.com',
            'username' => 'tina-theme',
            'password' => $password,
        ]);
        $tina->forceFill(['email_verified_at' => now(), 'password_set_at' => now(), 'created_at' => now()->subYear()])->save();

        // Admin Queue page (#4666): one failed job for staff to forget.
        DB::table('failed_jobs')->insert([
            'uuid' => '6f1c0d5e-4666-4e2e-9a77-000000004666',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['uuid' => '6f1c0d5e-4666-4e2e-9a77-000000004666', 'displayName' => 'App\Jobs\PostToDiscord', 'job' => 'Illuminate\Queue\CallQueuedHandler@call', 'data' => []]),
            'exception' => "Illuminate\\Http\\Client\\ConnectionException: Discord took too long to answer\nStack trace:\n#0 {main}",
            'failed_at' => now()->subHour(),
        ]);

        // Pending review (#4743): Rhea's job was flagged by the Hub's checks; Ray (staff, own 2FA
        // secret) approves it. Short and five days old, so it tops no feed or board once approved.
        foreach ([['Rhea Review', 'rhea-review'], ['Ray Reviewer', 'ray-reviewer']] as [$name, $username]) {
            $user = User::query()->create([
                'name' => $name,
                'email' => $username.'@example.com',
                'username' => $username,
                'password' => $password,
            ]);
            $user->forceFill(['email_verified_at' => now(), 'password_set_at' => now(), 'created_at' => now()->subYear()])->save();
        }
        User::query()->where('username', 'ray-reviewer')->firstOrFail()->forceFill([
            'is_staff' => true,
            'two_factor_secret' => encrypt('KRSXG5CTMVRXEZLUKRSXG5CTMVRXEZLU'),
            'two_factor_recovery_codes' => encrypt((string) json_encode(['e2e-recovery-code-ray'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $reviewDone = now('UTC')->subDays(5)->setTime(14, 0);
        Delivery::factory()->for(User::query()->where('username', 'rhea-review')->firstOrFail())->create([
            'source_city' => 'Groningen',
            'dest_city' => 'Bremen',
            'cargo_name' => 'Potatoes',
            'distance_km' => 40,
            'income' => 1200,
            'duration_sec' => 3600,
            'avg_speed_kmh' => 40,
            'started_at' => $reviewDone->copy()->subHour(),
            'completed_at' => $reviewDone,
        ])->forceFill(['hub_flag_reason' => 'Average speed over 150 km/h'])->save();

        // Follows (#4647): Fay follows Fred from his profile and reads the Following tab.
        foreach ([['Fay Follower', 'fay-follower'], ['Fred Followed', 'fred-followed']] as [$name, $username]) {
            $user = User::query()->create([
                'name' => $name,
                'email' => $username.'@example.com',
                'username' => $username,
                'password' => $password,
            ]);
            $user->forceFill(['email_verified_at' => now(), 'password_set_at' => now(), 'created_at' => now()->subYear()])->save();
        }
        $fredDone = now('UTC')->subDays(6)->setTime(10, 0);
        Delivery::factory()->for(User::query()->where('username', 'fred-followed')->firstOrFail())->create([
            'source_city' => 'Bergen',
            'dest_city' => 'Trondheim',
            'cargo_name' => 'Salmon',
            'distance_km' => 40,
            'income' => 1500,
            'duration_sec' => 3600,
            'avg_speed_kmh' => 40,
            'started_at' => $fredDone->copy()->subHour(),
            'completed_at' => $fredDone,
        ]);

        // Company goals (#4649): Gina owns Goal Getters and sets this month's targets; Gus drives
        // for it. Both profiles are private so their jobs stay off the public feed and boards
        // (other specs expect other rows first); Gina sees them as company mates.
        foreach ([['Gina Goals', 'gina-goals'], ['Gus Goals', 'gus-goals']] as [$name, $username]) {
            $user = User::query()->create([
                'name' => $name,
                'email' => $username.'@example.com',
                'username' => $username,
                'password' => $password,
                'profile_private' => true,
            ]);
            $user->forceFill(['email_verified_at' => now(), 'password_set_at' => now(), 'created_at' => now()->subYear()])->save();
        }
        $gina = User::query()->where('username', 'gina-goals')->firstOrFail();
        $gus = User::query()->where('username', 'gus-goals')->firstOrFail();
        $goalGetters = Company::query()->create([
            'name' => 'Goal Getters',
            'slug' => 'goal-getters',
            'tag' => 'GOAL',
            'description' => 'Hitting targets since November.',
            'recruitment' => Recruitment::Closed,
        ]);
        $goalGetters->members()->create(['user_id' => $gina->id, 'role' => CompanyRole::Owner]);
        $goalGetters->members()->create(['user_id' => $gus->id, 'role' => CompanyRole::Driver]);
        $monthStart = now('UTC')->startOfMonth();
        // This month (an hour ago, or just after the month began on its first hour).
        $thisMonth = now('UTC')->subHour()->max($monthStart->copy()->addMinutes(2));
        $lastMonth = $monthStart->copy()->subDays(3)->setTime(12, 0);
        foreach ([[$gina, 40, $thisMonth, 'Lille'], [$gus, 20, $thisMonth->copy()->subMinute(), 'Gent'], [$gina, 40, $lastMonth, 'Brugge']] as [$driver, $km, $done, $city]) {
            Delivery::factory()->for($driver)->create([
                'company_id' => $goalGetters->id,
                'source_city' => 'Calais',
                'dest_city' => $city,
                'cargo_name' => 'Goal posts',
                'distance_km' => $km,
                'income' => $km * 30,
                'duration_sec' => (int) ($km / 40 * 3600),
                'avg_speed_kmh' => 40,
                'started_at' => $done->copy()->subSeconds((int) ($km / 40 * 3600)),
                'completed_at' => $done,
            ]);
        }
        $lastKey = $monthStart->copy()->subMonth()->format('Y-m');
        $goalGetters->goals()->create(['month' => $lastKey, 'metric' => 'distance_km', 'target' => 30]);
        $goalGetters->goals()->create(['month' => $lastKey, 'metric' => 'deliveries', 'target' => 5]);

        // Blog (#5150): one published post for the public and SSR specs; Bea (staff, own 2FA
        // secret) writes and publishes another in blog.spec.ts.
        Post::query()->create([
            'title' => 'Welcome to the ProjectTrucks blog',
            'slug' => 'welcome',
            'excerpt' => 'News, guides and release notes for ProjectTrucks drivers.',
            'body' => "The blog is open. Expect **release notes**, guides and convoy news.\n\n## What's next\n\n- Guides for new drivers\n- Monthly roundups",
            'status' => PostStatus::Published,
            'published_at' => now()->subDays(2),
        ]);
        $bea = User::query()->create([
            'name' => 'Bea Blogger',
            'email' => 'bea-blogger@example.com',
            'username' => 'bea-blogger',
            'password' => $password,
        ]);
        $bea->forceFill([
            'email_verified_at' => now(),
            'password_set_at' => now(),
            'created_at' => now()->subYear(),
            'is_staff' => true,
            'two_factor_secret' => encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
            'two_factor_recovery_codes' => encrypt((string) json_encode(['e2e-recovery-code-bea'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        // Levels and achievements (#4645) for everything seeded above, silently, as on deploy.
        Artisan::call('hub:progress');
    }
}
