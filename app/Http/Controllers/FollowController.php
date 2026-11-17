<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Following drivers (#4647): a toggle on profiles, feeding the home page's Following tab. */
class FollowController extends Controller
{
    public function store(Request $request, User $driver): RedirectResponse
    {
        $viewer = $this->viewer($request);

        // Only drivers you can see: a private profile outside your company looks like any
        // other page you can't open.
        abort_unless($driver->isVisibleTo($viewer), 404);
        abort_if($driver->is($viewer), 403, 'You can’t follow yourself.');

        $viewer->following()->syncWithoutDetaching([$driver->id]);

        return back();
    }

    public function destroy(Request $request, User $driver): RedirectResponse
    {
        // Unfollowing always works, even once the driver is out of sight.
        $this->viewer($request)->following()->detach($driver->id);

        return back();
    }

    private function viewer(Request $request): User
    {
        $viewer = $request->user();
        abort_unless($viewer instanceof User, 403);

        return $viewer;
    }
}
