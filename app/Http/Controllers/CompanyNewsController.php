<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyPost;
use App\Models\User;
use App\Notifications\HubNotice;
use App\Support\DiscordWebhook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company news (#5224). Owners and managers write, edit, pin and delete the company's posts;
 * the company page and the home page's "My company" tab show them. A new post rings every
 * other member's bell (they can switch it off) and can also go to the company's Discord.
 */
class CompanyNewsController extends Controller
{
    public function create(Request $request, Company $company): Response
    {
        $this->authorizeManage($request, $company);

        return Inertia::render('news/Edit', [
            'company' => $company->summary(),
            'post' => null,
            'discord' => $company->discordActive(),
        ]);
    }

    public function store(Request $request, Company $company): RedirectResponse
    {
        $this->authorizeManage($request, $company);

        $data = $this->validated($request);
        $toDiscord = $request->boolean('discord');

        $post = new CompanyPost($data);
        $post->company_id = $company->id;
        $post->created_by = $request->user()?->id;
        $post->save();

        $members = User::query()
            ->whereIn('id', $company->members()->select('user_id'))
            ->whereKeyNot($post->created_by)
            ->get();
        Notification::send($members, HubNotice::companyNews($post));

        if ($toDiscord) {
            DiscordWebhook::newsPosted($post);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('News posted.')]);

        return to_route('companies.show', $company);
    }

    public function edit(Request $request, CompanyPost $post): Response
    {
        $this->authorizeManage($request, $post->company);

        return Inertia::render('news/Edit', [
            'company' => $post->company->summary(),
            'post' => $post->summary($request->user()),
            'discord' => false,
        ]);
    }

    public function update(Request $request, CompanyPost $post): RedirectResponse
    {
        $this->authorizeManage($request, $post->company);

        $post->update($this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('News saved.')]);

        return to_route('companies.show', $post->company);
    }

    /** Pins the post (unpinning any other) or unpins it. One pinned post per company. */
    public function pin(Request $request, CompanyPost $post): RedirectResponse
    {
        $this->authorizeManage($request, $post->company);

        $pin = $request->boolean('pinned');
        DB::transaction(function () use ($post, $pin) {
            if ($pin) {
                CompanyPost::query()->where('company_id', $post->company_id)->whereKeyNot($post->id)
                    ->whereNotNull('pinned_at')->update(['pinned_at' => null]);
            }
            // Pinning doesn't count as an edit, so the "edited" note stays as it was.
            $post->timestamps = false;
            $post->forceFill(['pinned_at' => $pin ? now() : null])->save();
        });

        return back();
    }

    public function destroy(Request $request, CompanyPost $post): RedirectResponse
    {
        $this->authorizeManage($request, $post->company);

        $post->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('News deleted.')]);

        return back();
    }

    /** @return array{title: string, body: string, visibility: string} */
    private function validated(Request $request): array
    {
        /** @var array{title: string, body: string, visibility: string} */
        return $request->validate([
            'title' => ['required', 'string', 'max:'.CompanyPost::TITLE_MAX],
            'body' => ['required', 'string', 'max:'.CompanyPost::BODY_MAX],
            'visibility' => ['required', Rule::in(CompanyPost::VISIBILITIES)],
        ]);
    }

    private function authorizeManage(Request $request, Company $company): void
    {
        abort_unless($company->roleOf($request->user())?->canManage() ?? false, 403);
    }
}
