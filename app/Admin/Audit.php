<?php

namespace App\Admin;

use App\Models\AdminAuditLog;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the admin audit log (#4664). Every staff action goes through here, so the log page
 * can't miss one.
 */
class Audit
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(
        string $action,
        ?Model $target = null,
        ?array $before = null,
        ?array $after = null,
        ?User $staff = null,
    ): AdminAuditLog {
        $staff ??= Auth::user() instanceof User ? Auth::user() : null;

        return AdminAuditLog::query()->create([
            'staff_id' => $staff?->id,
            'staff_name' => $staff !== null ? $staff->name : 'Command line',
            'action' => $action,
            'target_type' => $target ? class_basename($target) : null,
            'target_id' => $target?->getKey(),
            'target_label' => $target ? self::label($target) : null,
            'before' => $before,
            'after' => $after,
        ]);
    }

    private static function label(Model $target): string
    {
        return match (true) {
            $target instanceof User => $target->name.' ('.$target->email.')',
            $target instanceof Post => $target->title,
            default => (string) ($target->getAttribute('name') ?? '#'.$target->getKey()),
        };
    }
}
