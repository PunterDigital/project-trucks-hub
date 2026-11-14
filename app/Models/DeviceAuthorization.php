<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One "link this app" attempt (OAuth device-flow style): the app gets a secret device code and
 * shows the driver a short user code, the driver approves it on the website, and the app's next
 * poll swaps the device code for an API token.
 *
 * @property int $id
 * @property string $device_code_hash
 * @property string $user_code
 * @property string $client_name
 * @property int|null $user_id
 * @property Carbon|null $approved_at
 * @property Carbon|null $denied_at
 * @property Carbon|null $last_polled_at
 * @property Carbon $expires_at
 * @property-read User|null $user
 */
#[Fillable([
    'device_code_hash', 'user_code', 'client_name', 'user_id', 'approved_at', 'denied_at',
    'last_polled_at', 'expires_at',
])]
class DeviceAuthorization extends Model
{
    public const LIFETIME_SECONDS = 900;

    public const POLL_INTERVAL_SECONDS = 5;

    /** No vowels or look-alikes (0/O, 1/I), so codes never spell words and are easy to read out. */
    private const CODE_ALPHABET = 'BCDFGHJKLMNPQRSTVWXZ23456789';

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'denied_at' => 'datetime',
            'last_polled_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashDeviceCode(string $deviceCode): string
    {
        return hash('sha256', $deviceCode);
    }

    public static function newUserCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (self::query()->where('user_code', $code)->exists());

        return $code;
    }

    /** "BCDF-GHJK" → "BCDFGHJK"; tolerant of lower case, spaces and dashes. */
    public static function normaliseUserCode(string $input): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');
    }

    public static function formatUserCode(string $code): string
    {
        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->approved_at === null && $this->denied_at === null && ! $this->isExpired();
    }
}
