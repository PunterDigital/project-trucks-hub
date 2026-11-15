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

/** Settings → Notifications (#4643): which emails a driver gets. The bell always shows everything. */
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
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $emailed = array_map(fn (NoticeType $t) => $t->value, NoticeType::emailed());
        $data = $request->validate([
            'enabled' => ['present', 'array'],
            'enabled.*' => ['string', Rule::in($emailed)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill([
            'mail_opt_outs' => array_values(array_diff($emailed, $data['enabled'])),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Email notifications saved.')]);

        return to_route('notification-settings.edit');
    }
}
