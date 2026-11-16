<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A row of Laravel's `failed_jobs` table, for the admin Queue page (#4666). Read-only here:
 * staff retry or forget through `queue:retry` / `queue:forget`.
 *
 * @property int $id
 * @property string $uuid
 * @property string $connection
 * @property string $queue
 * @property string $payload
 * @property string $exception
 * @property Carbon $failed_at
 * @property-read string $name
 * @property-read string $exception_summary
 */
class FailedJob extends Model
{
    protected $table = 'failed_jobs';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['failed_at' => 'datetime'];
    }

    /**
     * The job's class (or display name) from the payload; also the audit log's label.
     *
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(function (): string {
            $payload = json_decode($this->payload, true);

            return is_array($payload) && is_string($payload['displayName'] ?? null) ? $payload['displayName'] : 'Unknown job';
        });
    }

    /**
     * The exception's first line, without the stack trace.
     *
     * @return Attribute<string, never>
     */
    protected function exceptionSummary(): Attribute
    {
        return Attribute::get(fn (): string => Str::limit(trim(strtok($this->exception, "\n") ?: ''), 200));
    }
}
