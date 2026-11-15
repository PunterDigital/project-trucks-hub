<?php

namespace App\Jobs;

use App\Models\Company;
use App\Support\DiscordWebhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * One post to a company's Discord webhook (#4652). It reads the webhook when it runs, so a
 * removed or replaced URL is respected, and it holds the payload as plain arrays.
 * Retried with a back-off; a final failure switches the webhook off (`DiscordWebhook::disable`).
 */
class PostToDiscord implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 30;

    /** @param  array<string, mixed>  $payload */
    public function __construct(public int $companyId, public array $payload) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(): void
    {
        $company = Company::query()->find($this->companyId);
        if ($company === null || ! $company->discordActive()) {
            return;
        }

        $response = Http::timeout(10)->acceptJson()->post($company->discord_webhook.'?wait=true', $this->payload);

        if ($response->successful()) {
            return;
        }

        // Deleted or revoked webhook: retrying won't help.
        if (in_array($response->status(), [401, 403, 404], true)) {
            DiscordWebhook::disable($company, __('Discord doesn’t know this webhook any more (:status).', ['status' => $response->status()]));

            return;
        }

        // Rate limited: wait as long as Discord asks (this still counts as an attempt).
        if ($response->status() === 429 && $this->job !== null) {
            $this->release((int) ceil((float) ($response->json('retry_after') ?? 5)));

            return;
        }

        throw new RuntimeException('Discord answered '.$response->status());
    }

    public function failed(?Throwable $e): void
    {
        $company = Company::query()->find($this->companyId);
        if ($company === null || $company->discord_webhook === null) {
            return;
        }

        // Never the exception's own text: an HTTP client error names the full URL, the secret.
        DiscordWebhook::disable($company, $e instanceof ConnectionException
            ? __('Couldn’t reach Discord after several tries.')
            : __('Discord kept answering with an error.'));
    }
}
