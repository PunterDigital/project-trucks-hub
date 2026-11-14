<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One staff action in the admin panel or `hub:staff` (#4664). Rows are never edited or deleted
 * from the app.
 *
 * @property int $id
 * @property int|null $staff_id
 * @property string|null $staff_name
 * @property string $action
 * @property string|null $target_type
 * @property int|null $target_id
 * @property string|null $target_label
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property Carbon $created_at
 * @property-read User|null $staff
 */
#[Fillable(['staff_id', 'staff_name', 'action', 'target_type', 'target_id', 'target_label', 'before', 'after'])]
class AdminAuditLog extends Model
{
    protected $table = 'admin_audit_log';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
