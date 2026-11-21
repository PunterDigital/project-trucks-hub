<?php

namespace App\Http\Controllers\Settings;

use App\Enums\NoticeType;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Notifications (#4643): which emails a driver gets, and (#5224) which optional
 * bell notices (`NoticeType::bellOptional()`, company news so far). Everything else always
 * shows under the bell.
 */
class NotificationSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/Notifications', [
            'emails' => array_map(fn (NoticeType $type) => [
                'type' => $type->value,
                'label' => $type->settingLabel(),
                'description' => $type->settingDescription(),
                'enabled' => $user->wantsMail($type),
            ], NoticeType::emailed()),
            'bell' => array_map(fn (NoticeType $type) => [
                'type' => $type->value,
                'label' => $type->settingLabel(),
                'description' => $type->settingDescription(),
                'enabled' => $user->wantsBell($type),
            ], NoticeType::bellOptionals()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $emailed = array_map(fn (NoticeType $t) => $t->value, NoticeType::emailed());
        $optional = array_map(fn (NoticeType $t) => $t->value, NoticeType::bellOptionals());
        $data = $request->validate([
            'enabled' => ['present', 'array'],
            'enabled.*' => ['string', Rule::in($emailed)],
            // Optional, so an older page that only sends `enabled` leaves the bell as it was.
            'bell' => ['sometimes', 'array'],
            'bell.*' => ['string', Rule::in($optional)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill([
            'mail_opt_outs' => array_values(array_diff($emailed, $data['enabled'])),
            ...(array_key_exists('bell', $data)
                ? ['bell_opt_outs' => array_values(array_diff($optional, $data['bell']))]
                : []),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification settings saved.')]);

        return to_route('notification-settings.edit');
    }
}
