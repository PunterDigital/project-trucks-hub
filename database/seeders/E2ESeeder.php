<?php

namespace Database\Seeders;

use App\Enums\CompanyRole;
use App\Enums\Recruitment;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\LiveStatus;
use App\Models\User;
use App\Support\LatestRelease;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
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
    }
}
