<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string|null $message
 * @property list<array{question: string, type: string, answer: string|null}>|null $answers
 * @property ApplicationStatus $status
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read User $user
 */
#[Fillable(['company_id', 'user_id', 'message', 'answers', 'status', 'decided_by', 'decided_at'])]
class CompanyApplication extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'decided_at' => 'datetime',
            // The questions as asked with the answers (#5222): later edits don't change them.
            'answers' => 'array',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
