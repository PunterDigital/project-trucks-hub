<?php

namespace Tests\Feature\Admin;

use App\Enums\CompanyRole;
use App\Filament\Resources\AuditLog\Pages\ListAuditLog;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Deliveries\DeliveryResource;
use App\Filament\Resources\Deliveries\Pages\ListDeliveries;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\AdminAuditLog;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->forceFill(['is_staff' => true])->save();

        return $user;
    }

    /** @return array<string, array{string}> */
    public static function adminPages(): array
    {
        return [
            'dashboard' => ['/admin'],
            'users' => ['/admin/users'],
            'companies' => ['/admin/companies'],
            'jobs' => ['/admin/jobs'],
            'audit log' => ['/admin/audit-log'],
        ];
    }

    #[DataProvider('adminPages')]
    public function test_guests_get_a_404(string $url): void
    {
        $this->get($url)->assertNotFound();
    }

    #[DataProvider('adminPages')]
    public function test_drivers_get_a_404(string $url): void
    {
        $this->actingAs(User::factory()->withTwoFactor()->create())
            ->get($url)
            ->assertNotFound();
    }

    public function test_staff_without_two_factor_are_sent_to_security_settings(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['is_staff' => true])->save();

        $this->actingAs($user)->get('/admin/users')->assertRedirect(route('security.edit'));
    }

    #[DataProvider('adminPages')]
    public function test_staff_with_two_factor_open_every_page(string $url): void
    {
        $this->actingAs($this->staff())->get($url)->assertOk();
    }

    public function test_staff_open_each_record_page(): void
    {
        $driver = User::factory()->create(['username' => 'dana-driver']);
        $company = Company::factory()->create();
        $company->members()->create(['user_id' => $driver->id, 'role' => CompanyRole::Owner]);
        $job = Delivery::factory()->for($driver)->create(['company_id' => $company->id]);

        $this->actingAs($this->staff());

        // The links Filament builds itself: users and companies bind by username / slug on
        // the site, but by id here (a Steam sign-up may have no username yet).
        $userUrl = UserResource::getUrl('view', ['record' => $driver]);
        $companyUrl = CompanyResource::getUrl('view', ['record' => $company]);
        $this->assertStringEndsWith("/admin/users/{$driver->id}", $userUrl);
        $this->assertStringEndsWith("/admin/companies/{$company->id}", $companyUrl);

        $this->get($userUrl)->assertOk()->assertSee('dana-driver');
        $this->get($companyUrl)->assertOk()->assertSee($driver->name);
        $this->get(DeliveryResource::getUrl('view', ['record' => $job]))->assertOk()->assertSee($job->cargo_name);

        $noUsername = User::factory()->create(['username' => null]);
        $this->get(UserResource::getUrl('view', ['record' => $noUsername]))->assertOk()->assertSee($noUsername->email);
    }

    public function test_users_can_be_found_by_steam_id(): void
    {
        $steam = User::factory()->create();
        $steam->forceFill(['steam_id' => '76561198000000777'])->save();
        $other = User::factory()->create();

        $this->actingAs($this->staff());

        Livewire::test(ListUsers::class)
            ->searchTable('76561198000000777')
            ->assertCanSeeTableRecords([$steam])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_jobs_filter_on_flagged_and_hidden(): void
    {
        $driver = User::factory()->create();
        $clean = Delivery::factory()->for($driver)->create();
        $appFlag = Delivery::factory()->for($driver)->create(['flagged' => true, 'flag_reason' => 'Too fast']);
        $hubFlag = Delivery::factory()->for($driver)->create();
        $hubFlag->forceFill(['hub_flag_reason' => 'Distance under 1 km'])->save();
        $hidden = Delivery::factory()->for($driver)->create(['hidden' => true]);

        $this->actingAs($this->staff());

        Livewire::test(ListDeliveries::class)
            ->filterTable('flagged', true)
            ->assertCanSeeTableRecords([$appFlag, $hubFlag])
            ->assertCanNotSeeTableRecords([$clean, $hidden]);

        Livewire::test(ListDeliveries::class)
            ->filterTable('hidden', true)
            ->assertCanSeeTableRecords([$hidden])
            ->assertCanNotSeeTableRecords([$clean, $appFlag]);
    }

    public function test_lists_show_their_records(): void
    {
        $company = Company::factory()->create(['name' => 'Baltic Freight']);

        $this->actingAs($this->staff());

        Livewire::test(ListCompanies::class)->assertCanSeeTableRecords([$company]);
    }

    public function test_revoking_linked_apps_deletes_tokens_and_is_audited(): void
    {
        $driver = User::factory()->create();
        $driver->createToken('ProjectTrucks on PC', ['sync']);
        $driver->createToken('ProjectTrucks on laptop', ['sync']);
        $staff = $this->staff();

        $this->actingAs($staff);

        Livewire::test(ViewUser::class, ['record' => $driver->getKey()])
            ->callAction('revokeLinkedApps')
            ->assertNotified('2 linked apps revoked');

        $this->assertSame(0, $driver->tokens()->count());

        $log = AdminAuditLog::query()->sole();
        $this->assertSame('user.linked_apps_revoked', $log->action);
        $this->assertSame($staff->id, $log->staff_id);
        $this->assertSame('User', $log->target_type);
        $this->assertSame($driver->id, $log->target_id);
        $this->assertSame(['linked_apps' => 2], $log->before);
        $this->assertSame(['linked_apps' => 0], $log->after);

        Livewire::test(ListAuditLog::class)->assertCanSeeTableRecords([$log]);
    }

    public function test_hub_staff_grants_and_revokes_with_audit_rows(): void
    {
        $user = User::factory()->create(['email' => 'shay@example.com']);

        $this->artisan('hub:staff', ['email' => 'shay@example.com'])
            ->expectsOutputToContain('is now staff')
            ->expectsOutputToContain('two-factor')
            ->assertSuccessful();
        $this->assertTrue($user->fresh()?->is_staff);

        $this->artisan('hub:staff', ['email' => 'shay@example.com', '--revoke' => true])
            ->expectsOutputToContain('no longer staff')
            ->assertSuccessful();
        $this->assertFalse($user->fresh()?->is_staff);

        $this->assertSame(
            ['staff.granted', 'staff.revoked'],
            AdminAuditLog::query()->orderBy('id')->pluck('action')->all(),
        );
        $this->assertNull(AdminAuditLog::query()->first()?->staff_id);
        $this->assertSame('Command line', AdminAuditLog::query()->first()?->staff_name);
    }

    public function test_hub_staff_refuses_an_unknown_email(): void
    {
        $this->artisan('hub:staff', ['email' => 'nobody@example.com'])->assertFailed();
        $this->assertSame(0, AdminAuditLog::query()->count());
    }

    public function test_is_staff_is_never_mass_assignable(): void
    {
        $user = User::factory()->create();
        $user->update(['is_staff' => true]);

        $this->assertFalse($user->fresh()?->is_staff);
    }

    public function test_the_shared_user_prop_says_whether_they_are_staff(): void
    {
        $this->actingAs($this->staff())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.is_staff', true));

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.is_staff', false));
    }
}
