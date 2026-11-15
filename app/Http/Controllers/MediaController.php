<?php

namespace App\Http\Controllers;

use App\Support\ImageStore;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves uploaded pictures (#4644) from the public disk, so production needs no `storage:link`.
 * Names are random and never reused, so a file can be cached for a year.
 */
class MediaController extends Controller
{
    public function __invoke(string $kind, string $file): Response
    {
        $path = $kind.'/'.$file;
        $disk = Storage::disk(ImageStore::DISK);

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
