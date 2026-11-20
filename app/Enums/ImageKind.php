<?php

namespace App\Enums;

/**
 * The pictures drivers and companies can upload (#4644), and blog covers (#5150), with the
 * size each is stored at.
 */
enum ImageKind: string
{
    case Avatar = 'avatars';
    case Logo = 'logos';
    case Banner = 'banners';
    case Cover = 'covers';

    public function width(): int
    {
        return match ($this) {
            self::Banner => 1500,
            self::Cover => 1200,
            default => 256,
        };
    }

    public function height(): int
    {
        return match ($this) {
            self::Banner => 400,
            self::Cover => 630,
            default => 256,
        };
    }
}
