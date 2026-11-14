<?php

namespace App\Http\Controllers;

use App\Models\DeviceAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** `/link`: where a driver approves the code ProjectTrucks shows them. */
class LinkDeviceController extends Controller
{
    public function show(Request $request): Response
    {
        $code = (string) $request->query('code', '');
        $auth = $code !== '' ? $this->find($code) : null;

        return Inertia::render('link/Show', [
            'code' => $code,
            'request' => $auth ? [
                'user_code' => DeviceAuthorization::formatUserCode($auth->user_code),
                'client_name' => $auth->client_name,
                'expires_at' => $auth->expires_at->toIso8601String(),
            ] : null,
            'invalid' => $code !== '' && $auth === null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_code' => ['required', 'string', 'max:20'],
            'decision' => ['required', 'in:approve,deny'],
        ]);

        $auth = $this->find($data['user_code']);
        if ($auth === null) {
            return back()->withErrors(['user_code' => __('That code is wrong or has expired. Start linking again in ProjectTrucks.')]);
        }

        if ($data['decision'] === 'approve') {
            $auth->forceFill(['user_id' => $request->user()->id, 'approved_at' => now()])->save();
            Inertia::flash('toast', ['type' => 'success', 'message' => __('ProjectTrucks is linked. It will start syncing in a few seconds.')]);
        } else {
            $auth->forceFill(['denied_at' => now()])->save();
            Inertia::flash('toast', ['type' => 'info', 'message' => __('Link request declined.')]);
        }

        return to_route('devices.index');
    }

    private function find(string $code): ?DeviceAuthorization
    {
        $auth = DeviceAuthorization::query()
            ->where('user_code', DeviceAuthorization::normaliseUserCode($code))
            ->first();

        return $auth !== null && $auth->isPending() ? $auth : null;
    }
}
