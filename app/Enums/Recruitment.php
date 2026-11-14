<?php

namespace App\Enums;

enum Recruitment: string
{
    /** Anyone can join straight away. */
    case Open = 'open';
    /** Drivers apply and an owner or manager decides. */
    case Apply = 'apply';
    /** No new members. */
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open to everyone',
            self::Apply => 'Applications',
            self::Closed => 'Closed',
        };
    }
}
