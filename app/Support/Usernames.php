<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

final class Usernames
{
    /** Paths under /drivers/ and words that would read as staff accounts. */
    public const RESERVED = ['admin', 'administrator', 'staff', 'support', 'system', 'projecttrucks', 'me', 'new', 'settings'];

    /** A free username based on `$name`, e.g. "Big Truck" → "big-truck" (or "big-truck-2"). */
    public static function suggest(string $name): string
    {
        $base = Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9_-]+/', '-')->trim('-')->limit(24, '')->toString();
        if (strlen($base) < 3 || in_array($base, self::RESERVED, true)) {
            $base = 'driver';
        }

        $candidate = $base;
        $n = 1;
        while (User::query()->where('username', $candidate)->exists()) {
            $n++;
            $candidate = $base.'-'.$n;
        }

        return $candidate;
    }
}
