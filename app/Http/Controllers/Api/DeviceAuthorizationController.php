<?php

namespace App\Http\Controllers\Api;

use App\Admin\Housekeeping;
use App\Http\Controllers\Controller;
use App\Models\DeviceAuthorization;
use App\Notifications\HubNotice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Linking ProjectTrucks to an account, modelled on the OAuth 2.0 device authorization grant
 * (RFC 8628): `POST /api/v1/device/code` starts it, the driver approves the user code at
 * `/link`, and the app polls `POST /api/v1/device/token` until it gets a token.
 */
class DeviceAuthorizationController extends Controller
{
    public function code(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_name' => ['required', 'string', 'max:100'],
        ]);

        $deviceCode = Str::random(64);
        $userCode = DeviceAuthorization::newUserCode();

        DeviceAuthorization::query()->create([
            'device_code_hash' => DeviceAuthorization::hashDeviceCode($deviceCode),
            'user_code' => $userCode,
            'client_name' => $data['client_name'],
            'expires_at' => now()->addSeconds(DeviceAuthorization::LIFETIME_SECONDS),
        ]);

        // Old attempts are no use to anyone. This also stores the last two closed days first,
        // so the admin dashboard keeps their link-request counts (#4665).
        Housekeeping::pruneLinkRequests();

        $formatted = DeviceAuthorization::formatUserCode($userCode);

        return response()->json([
            'device_code' => $deviceCode,
            'user_code' => $formatted,
            'verification_uri' => route('link.show'),
            'verification_uri_complete' => route('link.show', ['code' => $formatted]),
            'expires_in' => DeviceAuthorization::LIFETIME_SECONDS,
            'interval' => DeviceAuthorization::POLL_INTERVAL_SECONDS,
        ]);
    }

    public function token(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_code' => ['required', 'string', 'max:128'],
        ]);

        return DB::transaction(function () use ($data) {
            $auth = DeviceAuthorization::query()
                ->where('device_code_hash', DeviceAuthorization::hashDeviceCode($data['device_code']))
                ->lockForUpdate()
                ->first();

            if ($auth === null || $auth->isExpired()) {
                return $this->error('expired_token', 'The code has expired. Start linking again.');
            }

            if ($auth->denied_at !== null) {
                $auth->delete();

                return $this->error('access_denied', 'The link request was declined.');
            }

            if ($auth->approved_at === null) {
                $tooSoon = $auth->last_polled_at !== null
                    && $auth->last_polled_at->gt(now()->subSeconds(DeviceAuthorization::POLL_INTERVAL_SECONDS - 1));
                $auth->forceFill(['last_polled_at' => now()])->save();

                return $tooSoon
                    ? $this->error('slow_down', 'Polling too fast.')
                    : $this->error('authorization_pending', 'Waiting for the driver to approve the code.');
            }

            $user = $auth->user;
            if ($user === null) {
                $auth->delete();

                return $this->error('access_denied', 'The account no longer exists.');
            }

            // Approved just before staff suspended the account (#4651).
            if ($user->isSuspended()) {
                $auth->delete();

                return $this->error('access_denied', 'This account is suspended.');
            }

            $token = $user->createToken($auth->client_name, ['sync']);
            $auth->delete();
            // Security notice (#4643): the bell at once, the email (if on) after the commit.
            $user->notify(HubNotice::appLinked($auth->client_name)->afterCommit());

            return response()->json([
                'access_token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'user' => [
                    'name' => $user->name,
                    'username' => $user->username,
                    'profile_url' => route('drivers.show', $user),
                ],
            ]);
        });
    }

    private function error(string $code, string $description): JsonResponse
    {
        return response()->json(['error' => $code, 'error_description' => $description], 400);
    }
}
