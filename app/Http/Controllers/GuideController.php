<?php

namespace App\Http\Controllers;

use App\Support\Guides;
use Inertia\Inertia;
use Inertia\Response;

/** Help guides (#5151): /guides and /guides/{slug}, from `resources/guides`. */
class GuideController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('guides/Index', [
            'guides' => array_map(Guides::summary(...), Guides::all()),
        ]);
    }

    public function show(string $guide): Response
    {
        $found = Guides::find($guide);
        abort_if($found === null, 404);

        return Inertia::render('guides/Show', [
            'guide' => Guides::page($found),
            'others' => array_values(array_map(
                Guides::summary(...),
                array_filter(Guides::all(), fn (array $other) => $other['slug'] !== $found['slug']),
            )),
        ]);
    }
}
