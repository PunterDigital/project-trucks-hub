<?php

namespace App\Http\Controllers;

use App\Enums\NoticeType;
use App\Models\Delivery;
use App\Models\DeliveryComment;
use App\Models\DeliveryKudos;
use App\Models\User;
use App\Notifications\HubNotice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Kudos and comments on jobs (#4646). Only someone who can see the job (the same rules as the
 * job page, {@see Delivery::scopeVisibleTo()}) can react to it; anyone else gets a 404.
 */
class DeliveryReactionController extends Controller
{
    public function giveKudos(Request $request, Delivery $delivery): RedirectResponse
    {
        $viewer = $this->viewer($request);
        $this->ensureVisible($viewer, $delivery);
        abort_if($delivery->user_id === $viewer->id, 403, 'You can’t give kudos to your own job.');

        $kudos = DeliveryKudos::query()->firstOrCreate(['delivery_id' => $delivery->id, 'user_id' => $viewer->id]);

        // Once per giver and job: taking kudos back and giving it again doesn't ring again.
        $owner = $delivery->user;
        $notice = HubNotice::kudosReceived($delivery, $viewer);
        if ($kudos->wasRecentlyCreated && ! $owner->notifications()
            ->where('data->type', NoticeType::KudosReceived->value)
            ->where('data->message', $notice->message)
            ->exists()) {
            $owner->notify($notice);
        }

        return back();
    }

    public function takeKudos(Request $request, Delivery $delivery): RedirectResponse
    {
        $viewer = $this->viewer($request);
        $this->ensureVisible($viewer, $delivery);

        DeliveryKudos::query()->where('delivery_id', $delivery->id)->where('user_id', $viewer->id)->delete();

        return back();
    }

    public function comment(Request $request, Delivery $delivery): RedirectResponse
    {
        $viewer = $this->viewer($request);
        $this->ensureVisible($viewer, $delivery);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:'.DeliveryComment::MAX_LENGTH],
        ], [], ['body' => 'comment']);
        $body = str_replace(["\r\n", "\r"], "\n", (string) $data['body']);

        $delivery->comments()->create(['user_id' => $viewer->id, 'body' => $body]);

        if ($delivery->user_id !== $viewer->id) {
            $delivery->user->notify(HubNotice::commentReceived($delivery, $viewer, $body));
        }

        return back();
    }

    /** The author, or the job's driver, removes a comment. */
    public function deleteComment(Request $request, Delivery $delivery, DeliveryComment $comment): RedirectResponse
    {
        abort_unless($comment->delivery_id === $delivery->id, 404);
        abort_unless($comment->canBeDeletedBy($request->user()), 403);

        $comment->delete();

        return back();
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function ensureVisible(User $viewer, Delivery $delivery): void
    {
        abort_unless(Delivery::query()->visibleTo($viewer)->whereKey($delivery->id)->exists(), 404);
    }
}
