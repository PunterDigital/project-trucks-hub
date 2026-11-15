<?php

namespace App\Http\Controllers\Settings;

use App\Enums\ImageKind;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ImageStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Settings → Profile → Profile picture (#4644). */
class AvatarController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->validate(['avatar' => ImageStore::RULES]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill([
            'avatar_path' => ImageStore::replace($request->file('avatar'), ImageKind::Avatar, $user->avatar_path),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile picture updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Removes the uploaded picture, so the Steam one shows again. With no upload, removes the
     * Steam picture instead (linking Steam again brings it back).
     */
    public function destroy(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->avatar_path !== null) {
            ImageStore::delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        } else {
            $user->forceFill(['avatar_url' => null])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile picture removed.')]);

        return to_route('profile.edit');
    }
}
