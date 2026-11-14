<?php

namespace App\Console\Commands;

use App\Admin\Audit;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grants or revokes access to the admin panel (#4664). The only way in: the panel has no page
 * that makes someone staff.
 */
class HubStaff extends Command
{
    protected $signature = 'hub:staff {email : The account\'s email address} {--revoke : Take staff access away}';

    protected $description = 'Grant (or with --revoke, take away) access to the Hub admin panel';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No account with the email {$email}.");

            return self::FAILURE;
        }

        $staff = ! $this->option('revoke');

        if ($user->is_staff === $staff) {
            $this->info($staff ? "{$user->name} is already staff." : "{$user->name} isn't staff.");

            return self::SUCCESS;
        }

        $user->forceFill(['is_staff' => $staff])->save();
        Audit::record(
            $staff ? 'staff.granted' : 'staff.revoked',
            $user,
            before: ['is_staff' => ! $staff],
            after: ['is_staff' => $staff],
        );

        if ($staff) {
            $this->info("{$user->name} is now staff.");

            if ($user->two_factor_confirmed_at === null) {
                $this->warn('They need to turn on two-factor authentication (Settings → Security) before /admin opens.');
            }
        } else {
            $this->info("{$user->name} is no longer staff.");
        }

        return self::SUCCESS;
    }
}
