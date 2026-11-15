<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Support\DiscordWebhook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** A company's Discord webhook (#4652): owners and managers set, test and remove it. */
class CompanyDiscordController extends Controller
{
    public function update(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeManage($request, $company);

        $data = $request->validate([
            'webhook' => ['required', 'string', 'max:255', 'regex:'.DiscordWebhook::URL_PATTERN],
        ], [
            'webhook.regex' => __('Paste a Discord webhook URL (https://discord.com/api/webhooks/…).'),
        ]);

        $company->forceFill([
            'discord_webhook' => $data['webhook'],
            'discord_webhook_disabled_at' => null,
            'discord_webhook_error' => null,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Discord webhook saved. Send a test message to check it.')]);

        return to_route('companies.edit', $company);
    }

    public function test(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeManage($request, $company);

        $error = DiscordWebhook::test($company);

        Inertia::flash('toast', $error === null
            ? ['type' => 'success', 'message' => __('Test message sent. Check your Discord channel.')]
            : ['type' => 'error', 'message' => $error]);

        return to_route('companies.edit', $company);
    }

    public function destroy(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeManage($request, $company);

        $company->forceFill([
            'discord_webhook' => null,
            'discord_webhook_disabled_at' => null,
            'discord_webhook_error' => null,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Discord webhook removed.')]);

        return to_route('companies.edit', $company);
    }

    private function authorizeManage(Request $request, Company $company): void
    {
        abort_unless($company->roleOf($request->user())?->canManage() ?? false, 403);
    }
}
