<?php

namespace App\Http\Controllers;

use App\Support\LinkPreview;
use Symfony\Component\HttpFoundation\Response;

/**
 * The 1200×630 link-preview card (#4652) for Discord, Slack and other unfurlers. Outside the
 * web group (no session, no cookies): the card is what a guest may see, whoever asks.
 */
class PreviewImageController extends Controller
{
    public function __invoke(string $type, int $id): Response
    {
        $png = LinkPreview::png($type, $id);
        abort_if($png === null, 404);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            // The page links it with `?v=<content hash>`, so an hour is plenty.
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
