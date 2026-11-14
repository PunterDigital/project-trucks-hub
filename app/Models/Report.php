<?php

namespace App\Models;

use App\Enums\ReportReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A driver's report of a profile, company or job (#4651). Staff work the open ones in the
 * admin panel's Reports queue. One open report per item per reporter.
 *
 * @property int $id
 * @property int $reporter_id
 * @property string $reportable_type
 * @property int $reportable_id
 * @property ReportReason $reason
 * @property string|null $details
 * @property string $status
 * @property int|null $resolved_by
 * @property string|null $resolution_note
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $reporter
 * @property-read User|null $resolver
 * @property-read User|Company|Delivery|null $reportable
 */
#[Fillable(['reporter_id', 'reportable_type', 'reportable_id', 'reason', 'details'])]
class Report extends Model
{
    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    /** What the report form sends => the model it points at. */
    public const TYPES = [
        'driver' => User::class,
        'company' => Company::class,
        'job' => Delivery::class,
    ];

    protected function casts(): array
    {
        return [
            'reason' => ReportReason::class,
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @return MorphTo<Model, $this> */
    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<Report>  $query
     * @return Builder<Report>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::OPEN);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /** "Driver", "Company" or "Job", for the queue. */
    public function typeLabel(): string
    {
        return match ($this->reportable_type) {
            User::class => 'Driver',
            Company::class => 'Company',
            Delivery::class => 'Job',
            default => class_basename($this->reportable_type),
        };
    }

    /** A one-line description of the reported item, or a note that it's gone. */
    public function itemLabel(): string
    {
        $item = $this->reportable;

        return match (true) {
            $item instanceof User => $item->name.($item->username ? ' (@'.$item->username.')' : ''),
            $item instanceof Company => '['.$item->tag.'] '.$item->name,
            $item instanceof Delivery => $item->source_city.' → '.$item->dest_city.' ('.$item->user->name.')',
            default => 'Deleted since',
        };
    }
}
