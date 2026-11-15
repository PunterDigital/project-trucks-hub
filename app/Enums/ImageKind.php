<?php

namespace App\Enums;

/** The pictures drivers and companies can upload (#4644), with the size each is stored at. */
enum ImageKind: string
{
    case Avatar = 'avatars';
    case Logo = 'logos';
    case Banner = 'banners';

    public function width(): int
    {
        return $this === self::Banner ? 1500 : 256;
    }

    public function height(): int
    {
        return $this === self::Banner ? 400 : 256;
    }
}
