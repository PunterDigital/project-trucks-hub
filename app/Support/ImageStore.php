<?php

namespace App\Support;

use App\Enums\ImageKind;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;

/**
 * Avatars, company logos and banners (#4644). Every upload is decoded and re-encoded as WebP at
 * a fixed size, so no original bytes (EXIF, GPS, anything hidden in the file) are ever kept or
 * served. Files get a random name on each upload: the old one is deleted, and MediaController
 * can cache them forever.
 */
class ImageStore
{
    public const DISK = 'public';

    /** Accepted uploads: the validation rules for the file field. */
    public const RULES = ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'];

    /** The path pattern MediaController serves (see `path()`). */
    public const FILE_PATTERN = '[A-Za-z0-9]{40}\.webp';

    public static function store(UploadedFile $file, ImageKind $kind): string
    {
        $image = ImageManager::usingDriver(GdDriver::class, strip: true, decodeAnimation: false)
            ->decodePath($file->getRealPath());

        $encoded = $image
            ->cover($kind->width(), $kind->height())
            ->encodeUsingFormat(Format::WEBP, quality: 85);

        $path = $kind->value.'/'.Str::random(40).'.webp';
        Storage::disk(self::DISK)->put($path, (string) $encoded);

        return $path;
    }

    /** Stores the new picture, then deletes the one it replaces. */
    public static function replace(UploadedFile $file, ImageKind $kind, ?string $old): string
    {
        $path = self::store($file, $kind);
        self::delete($old);

        return $path;
    }

    public static function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public static function url(?string $path): ?string
    {
        return $path === null || $path === '' ? null : '/media/'.$path;
    }
}
