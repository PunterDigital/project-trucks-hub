<?php

namespace Database\Seeders;

use App\Enums\CompanyRole;
use App\Enums\Recruitment;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\LiveStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
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
    }
}
