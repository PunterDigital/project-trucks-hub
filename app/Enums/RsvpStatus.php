<?php

namespace App\Enums;

/** A driver's answer to a company event (#4648). */
enum RsvpStatus: string
{
    case Going = 'going';
    case Maybe = 'maybe';
    case NotGoing = 'not_going';

    public function label(): string
    {
        return match ($this) {
            self::Going => 'Going',
            self::Maybe => 'Maybe',
            self::NotGoing => 'Not going',
        };
    }
}
