<?php

namespace Tests\Feature;

use App\Admin\Moderation;
use App\Enums\CompanyRole;
use App\Enums\NoticeType;
use App\Enums\Recruitment;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Settings\PrivacyController;
use App\Models\Company;
use App\Models\CompanyApplication;
use App\Models\User;
use App\Notifications\HubNotice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Notifications (#4643): who hears about what, the bell, and the email settings. */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function company(Recruitment $recruitment = Recruitment::Apply): array
    {
        $company = Company::factory()->create(['recruitment' => $recruitment]);
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $driver = User::factory()->create();
        $company->members()->create(['user_id' => $owner->id, 'role' => CompanyRole::Owner]);
        $company->members()->create(['user_id' => $manager->id, 'role' => CompanyRole::Manager]);
        $company->members()->create(['user_id' => $driver->id, 'role' => CompanyRole::Driver]);

        return [$company, $owner, $manager, $driver];
    }

    private function sentType(User $user, NoticeType $type): bool
    {
        return Notification::sent($user, HubNotice::class)
            ->contains(fn (HubNotice $n) => $n->notice === $type);
    }

    public function test_an_application_tells_the_owner_and_managers_once(): void
    {
        Notification::fake();
        [$company, $owner, $manager, $driver] = $this->company();
        $applicant = User::factory()->create(['name' => 'Abe Apply']);

        $this->actingAs($applicant)->post(route('companies.apply', $company), ['message' => 'I drive at night.']);
        $this->actingAs($applicant)->post(route('companies.apply', $company));

        foreach ([$owner, $manager] as $user) {
            Notification::assertSentToTimes($user, HubNotice::class, 1);
            Notification::assertSentTo($user, HubNotice::class, function (HubNotice $n) use ($user, $company) {
                return $n->notice === NoticeType::ApplicationReceived
                    && $n->message === "Abe Apply applied to join {$company->name}."
                    && $n->via($user) === ['database', 'mail']
                    && in_array('Their message: “I drive at night.”', $n->mailLines, true);
            });
        }
        Notification::assertNotSentTo($driver, HubNotice::class);
    }

    public function test_the_applicant_hears_the_decision(): void
    {
        Notification::fake();
        [$company, $owner] = $this->company();
        $accepted = User::factory()->create();
        $declined = User::factory()->create();
        $a = CompanyApplication::query()->create(['company_id' => $company->id, 'user_id' => $accepted->id, 'status' => 'pending']);
        $d = CompanyApplication::query()->create(['company_id' => $company->id, 'user_id' => $declined->id, 'status' => 'pending']);

        $this->actingAs($owner)->post(route('companies.applications.decide', [$company, $a]), ['decision' => 'accept']);
        $this->actingAs($owner)->post(route('companies.applications.decide', [$company, $d]), ['decision' => 'decline']);

        Notification::assertSentTo($accepted, HubNotice::class, fn (HubNotice $n) => $n->notice === NoticeType::ApplicationDecided
            && str_contains($n->message, 'accepted your application'));
        Notification::assertSentTo($declined, HubNotice::class, fn (HubNotice $n) => $n->notice === NoticeType::ApplicationDecided
            && str_contains($n->message, 'declined your application'));
    }

    public function test_joining_an_open_company_tells_the_owner_only_in_the_bell(): void
    {
        Notification::fake();
        [$company, $owner, $manager] = $this->company(Recruitment::Open);
        $joiner = User::factory()->create();

        $this->actingAs($joiner)->post(route('companies.join', $company));

        Notification::assertSentTo($owner, HubNotice::class, fn (HubNotice $n) => $n->notice === NoticeType::MemberJoined
            && $n->via($owner) === ['database']);
        Notification::assertNotSentTo($manager, HubNotice::class);
    }

    public function test_role_changes_handovers_and_removals_tell_the_member(): void
    {
        Notification::fake();
        [$company, $owner, $manager, $driver] = $this->company();

        $this->actingAs($owner)->patch(route('companies.members.update', [$company, $driver->membership]), ['role' => 'manager']);
        $this->assertTrue($this->sentType($driver, NoticeType::RoleChanged));

        // Saving the same role again says nothing.
        $this->actingAs($owner)->patch(route('companies.members.update', [$company, $driver->fresh()->membership]), ['role' => 'manager']);
        Notification::assertSentToTimes($driver, HubNotice::class, 1);

        $this->actingAs($owner)->patch(route('companies.members.update', [$company, $manager->membership]), ['role' => 'owner']);
        Notification::assertSentTo($manager, HubNotice::class, fn (HubNotice $n) => $n->notice === NoticeType::OwnershipHandedOver
            && str_contains($n->message, $owner->name.' handed'));

        $this->actingAs($manager->fresh())->delete(route('companies.members.destroy', [$company, $driver->fresh()->membership]));
        $this->assertTrue($this->sentType($driver, NoticeType::RemovedFromCompany));
    }

    public function test_staff_removing_a_member_tells_them(): void
    {
        Notification::fake();
        [, , , $driver] = $this->company();

        Moderation::removeMember($driver->membership, 'Spam');

        $this->assertTrue($this->sentType($driver, NoticeType::RemovedFromCompany));
    }

    public function test_linking_an_app_sends_a_security_notice_with_email(): void
    {
        RateLimiter::clear('device-token');
        $user = User::factory()->create();
        $start = $this->postJson(route('api.device.code'), ['client_name' => 'ProjectTrucks on RIG'])->json();
        $this->actingAs($user)->post(route('link.update'), ['user_code' => $start['user_code'], 'decision' => 'approve']);
        $this->travel(6)->seconds();

        Notification::fake();
        $this->postJson(route('api.device.token'), ['device_code' => $start['device_code']])->assertOk();

        Notification::assertSentTo($user, HubNotice::class, function (HubNotice $n) use ($user) {
            $mail = $n->toMail($user);

            return $n->notice === NoticeType::AppLinked
                && $n->via($user) === ['database', 'mail']
                && $mail instanceof MailMessage
                && $mail->subject === 'A ProjectTrucks app was linked to your account'
                && $n->url === route('devices.index');
        });
    }

    public function test_the_bell_lists_notifications_and_marks_them_read(): void
    {
        [$company, $owner] = $this->company();
        $owner->notify(HubNotice::memberJoined($company, User::factory()->create(['name' => 'Jo Join'])));
        $this->travel(1)->minutes();
        $owner->notify(HubNotice::roleChanged($company, CompanyRole::Owner));

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('notifications.unread', 2)
                ->has('notifications.items', 2)
                ->where('notifications.items.1.message', "Jo Join joined {$company->name}.")
                ->where('notifications.items.1.read', false));

        $first = $owner->notifications()->oldest()->first();
        $this->actingAs($owner)->post(route('notifications.open', $first->id))
            ->assertRedirect(route('companies.show', $company));
        $this->assertNotNull($first->fresh()->read_at);
        $this->assertSame(1, $owner->unreadNotifications()->count());

        $this->actingAs($owner)->post(route('notifications.read-all'))->assertRedirect();
        $this->assertSame(0, $owner->unreadNotifications()->count());

        // Guests get no bell; someone else's notification is a 404.
        $this->app['auth']->forgetGuards();
        $this->get(route('home'))->assertInertia(fn ($page) => $page->where('notifications', null));
        $this->actingAs(User::factory()->create())->post(route('notifications.open', $first->id))->assertNotFound();
    }

    public function test_opening_a_notice_never_redirects_off_site(): void
    {
        [$company, $owner] = $this->company();
        // A prefix check on url('/') let a look-alike host through (#5531).
        $evil = url('/').'.evil.test/x';
        $owner->notify(new HubNotice(NoticeType::MemberJoined, 'x', $evil, 'x'));
        $notice = $owner->notifications()->first();

        $response = $this->actingAs($owner)->from(route('dashboard'))->post(route('notifications.open', $notice->id));
        $response->assertRedirect(route('dashboard'));
        $this->assertNotNull($notice->fresh()->read_at);

        $this->assertTrue(NotificationController::isOwnUrl(route('companies.show', $company)));
        $this->assertTrue(NotificationController::isOwnUrl(url('/')));
        foreach ([
            $evil,
            'https://evil.test/',
            '//evil.test/x',
            '/relative/path',
            str_replace('://', '://me@', url('/')).'/x',
            strtr(url('/'), ['http://' => 'https://', 'https://' => 'http://']).'/x',
            'javascript:alert(1)',
        ] as $url) {
            $this->assertFalse(NotificationController::isOwnUrl($url), $url);
        }
    }

    public function test_email_settings_turn_emails_off_but_keep_the_bell(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('notification-settings.edit'))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/Notifications')
                ->has('emails', 3)
                ->where('emails.0.type', 'application_received')
                ->where('emails.0.enabled', true));

        $this->actingAs($user)->put(route('notification-settings.update'), ['enabled' => ['application_decided']])
            ->assertRedirect(route('notification-settings.edit'));
        $user->refresh();
        $this->assertEqualsCanonicalizing(['application_received', 'app_linked'], $user->mail_opt_outs);
        $this->assertFalse($user->wantsMail(NoticeType::AppLinked));
        $this->assertTrue($user->wantsMail(NoticeType::ApplicationDecided));
        $this->assertSame(['database'], HubNotice::appLinked('Rig')->via($user));

        // Only the emailed types are accepted.
        $this->actingAs($user)->put(route('notification-settings.update'), ['enabled' => ['member_joined']])
            ->assertSessionHasErrors('enabled.0');
    }

    public function test_emails_are_queued_and_the_bell_is_written_at_once(): void
    {
        $notice = HubNotice::appLinked('Rig');

        $this->assertSame(['database' => 'sync'], $notice->viaConnections());
        $this->assertInstanceOf(ShouldQueue::class, $notice);
    }

    public function test_suspended_drivers_and_deleted_accounts_get_nothing(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['suspended_at' => now()])->save();
        $this->assertSame([], HubNotice::appLinked('Rig')->via($user));

        $other = User::factory()->create();
        $other->notify(HubNotice::appLinked('Rig'));
        $this->assertSame(1, $other->notifications()->count());
        $other->delete();
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_the_privacy_export_includes_notifications(): void
    {
        $user = User::factory()->create();
        $user->notify(HubNotice::appLinked('Rig'));

        $export = PrivacyController::exportFor($user);

        $this->assertSame([], $export['email_notifications_off']);
        $this->assertCount(1, $export['notifications']);
        $this->assertStringContainsString('Rig', $export['notifications'][0]['message']);
    }
}
