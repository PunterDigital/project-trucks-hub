<?php

namespace App\Http\Controllers;

use App\Support\LatestRelease;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** /download: get ProjectTrucks straight from the Hub. */
class DownloadController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('download/Show', [
            'release' => LatestRelease::get(),
            'releasesPage' => LatestRelease::RELEASES_PAGE,
        ]);
    }

    /**
     * A permanent link to the current installer (`/download/windows`, `/download/windows/msi`)
     * that redirects to the file on GitHub, so the browser starts the download at once.
     */
    public function windows(string $kind = 'exe'): RedirectResponse
    {
        $installer = LatestRelease::installer($kind);

        return $installer !== null
            ? redirect()->away($installer['url'])
            : to_route('download');
    }
}
