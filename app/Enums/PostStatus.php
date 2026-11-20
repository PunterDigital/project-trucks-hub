<?php

namespace App\Enums;

/** A blog post's state (#5150). Only published posts whose time has come are public. */
enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
        };
    }
}
