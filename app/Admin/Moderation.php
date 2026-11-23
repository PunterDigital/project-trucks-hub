<?php

namespace App\Admin;

use App\Achievements\Progress;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyMember;
use App\Models\Delivery;
use App\Models\Report;
use App\Models\User;
use App\Notifications\HubNotice;
use App\Sync\JobChecks;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Staff moderation actions (#4651). The admin panel's buttons call these; each one refuses
 * when the item is already in the asked-for state (a stale page), and writes the audit log.
 */
class Moderation
{
    /**
     * Prefix of a Hub flag set by staff rather than by {@see JobChecks}, before staff flags
     * became rejections (#4743, the migration converted them). Nothing writes it any more.
     */
    public const STAFF_FLAG_PREFIX = 'Flagged by staff: ';

    public static function suspend(User $user, string $reason, ?User $staff = null): void
    {
        if ($user->suspended_at !== null) {
            throw new RuntimeException('This account is already suspended.');
        }
        if ($staff !== null && $staff->is($user)) {
            throw new RuntimeException('You can’t suspend your own account.');
        }

        DB::transaction(function () use ($user, $reason, $staff) {
            $tokens = $user->tokens()->count();
            $user->forceFill(['suspended_at' => now(), 'suspension_reason' => $reason])->save();
            // Linked apps stop syncing at once, and the driver drops off the map and "on the road".
            $user->tokens()->delete();
            $user->liveStatus()->delete();
            // Database sessions: sign them out everywhere now, not only on their next request.
            if (config('session.driver') === 'database') {
                DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }

            Audit::record('user.suspended', $user, ['suspended' => false], [
                'suspended' => true,
                'reason' => $reason,
                'linked_apps_revoked' => $tokens,
            ], $staff);
        });
    }

    public static function liftSuspension(User $user, string $reason, ?User $staff = null): void
    {
        if ($user->suspended_at === null) {
            throw new RuntimeException('This account isn’t suspended.');
        }

        $before = ['suspended' => true, 'reason' => $user->suspension_reason];
        $user->forceFill(['suspended_at' => null, 'suspension_reason' => null])->save();

        Audit::record('user.suspension_lifted', $user, $before, ['suspended' => false, 'reason' => $reason], $staff);
    }

    public static function hideJob(Delivery $job, string $reason, ?User $staff = null): void
    {
        if ($job->staff_hidden) {
            throw new RuntimeException('This job is already hidden by staff.');
        }

        $job->forceFill(['staff_hidden' => true])->save();
        Audit::record('job.hidden', $job, ['staff_hidden' => false], ['staff_hidden' => true, 'reason' => $reason], $staff);
        self::refreshProgress($job);
    }

    public static function unhideJob(Delivery $job, string $reason, ?User $staff = null): void
    {
        if (! $job->staff_hidden) {
            throw new RuntimeException('This job isn’t hidden by staff.');
        }

        $job->forceFill(['staff_hidden' => false])->save();
        Audit::record('job.unhidden', $job, ['staff_hidden' => true], ['staff_hidden' => false, 'reason' => $reason], $staff);
        self::refreshProgress($job);
    }

    /**
     * Approves a flagged (or earlier rejected) job (#4743): it shows publicly and counts, the
     * app's flag included. Unchanged re-syncs keep the decision and no longer run the Hub's
     * checks on it; a re-sync with changed figures takes the approval away (#5530).
     */
    public static function approveJob(Delivery $job, string $note, ?User $staff = null): void
    {
        $state = $job->reviewState();
        if ($state === Delivery::REVIEW_APPROVED) {
            throw new RuntimeException('This job is already approved.');
        }
        if ($state === null) {
            throw new RuntimeException('This job isn’t waiting for a review.');
        }

        self::review($job, Delivery::REVIEW_APPROVED, $note, $staff, 'job.approved');
    }

    /**
     * Rejects a job (#4743; was "Flag as cheated"): only its driver sees it, with "Rejected by
     * staff" and the reason, and it counts nowhere. Works on any job, flagged or not.
     */
    public static function rejectJob(Delivery $job, string $reason, ?User $staff = null): void
    {
        if ($job->review === Delivery::REVIEW_REJECTED) {
            throw new RuntimeException('This job is already rejected.');
        }

        self::review($job, Delivery::REVIEW_REJECTED, $reason, $staff, 'job.rejected');
    }

    private static function review(Delivery $job, string $decision, string $note, ?User $staff, string $action): void
    {
        $before = ['review' => $job->reviewState(), 'review_note' => $job->review_note];
        $job->forceFill([
            'review' => $decision,
            'review_note' => $note,
            'reviewed_at' => now(),
            'hub_held' => false,
            'staff_reviewed' => true,
        ])->save();

        Audit::record($action, $job, $before, ['review' => $decision, 'review_note' => $note], $staff);
        self::refreshProgress($job);
    }

    /** The job counts differently now: levels and achievements follow (#4645). */
    private static function refreshProgress(Delivery $job): void
    {
        Progress::refresh($job->user, notify: false);
    }

    /**
     * Replaces a company's name, tag, link and description with neutral ones. The owner can
     * choose new ones on the company's Edit page. The logo and banner go too (#4644).
     */
    public static function resetCompanyProfile(Company $company, string $reason, ?User $staff = null): void
    {
        $before = $company->only(['name', 'tag', 'slug', 'description', 'logo_path', 'banner_path']);
        $tag = 'C'.$company->id;

        $name = 'Company '.$company->id;
        $slug = 'company-'.$company->id;
        // Someone may already have picked that name: add a suffix until it's free.
        for ($n = 2; Company::query()->whereKeyNot($company->id)
            ->where(fn ($q) => $q->where('name', $name)->orWhere('slug', $slug))->exists(); $n++) {
            $name = 'Company '.$company->id.'-'.$n;
            $slug = 'company-'.$company->id.'-'.$n;
        }

        // The logo and banner go too (#4644): they're part of what a company shows the world.
        $company->forgetImages();
        $company->forceFill([
            'name' => $name,
            // Tags are 2–5 capitals or digits; past C9999 fall back to a free 5-character one.
            'tag' => strlen($tag) <= 5 && ! Company::query()->where('tag', $tag)->whereKeyNot($company->id)->exists()
                ? $tag
                : self::freeTag(),
            'slug' => $slug,
            'description' => null,
        ])->save();

        Audit::record('company.profile_reset', $company, $before, [
            ...$company->only(['name', 'tag', 'slug', 'description', 'logo_path', 'banner_path']),
            'reason' => $reason,
        ], $staff);
    }

    /** Takes a driver out of a company. The owner can't be removed this way. */
    public static function removeMember(CompanyMember $member, string $reason, ?User $staff = null): void
    {
        if ($member->role === CompanyRole::Owner) {
            throw new RuntimeException('The owner can’t be removed. Suspend the account or reset the company instead.');
        }

        $member->loadMissing('user', 'company');
        $member->delete();
        $member->user->notify(HubNotice::removedFromCompany($member->company));

        Audit::record('company.member_removed', $member->company, [
            'member' => $member->user->name,
            'role' => $member->role->value,
        ], ['member' => null, 'reason' => $reason], $staff);
    }

    public static function resolveReport(Report $report, string $note, ?User $staff = null): void
    {
        if (! $report->isOpen()) {
            throw new RuntimeException('This report is already resolved.');
        }

        $report->forceFill([
            'status' => Report::RESOLVED,
            'resolved_by' => $staff?->id,
            'resolution_note' => $note,
            'resolved_at' => now(),
        ])->save();

        Audit::record('report.resolved', $report, ['status' => Report::OPEN], [
            'status' => Report::RESOLVED,
            'item' => $report->typeLabel().': '.$report->itemLabel(),
            'note' => $note,
        ], $staff);
    }

    private static function freeTag(): string
    {
        do {
            $tag = 'C'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 4));
        } while (Company::query()->where('tag', $tag)->exists());

        return $tag;
    }
}
