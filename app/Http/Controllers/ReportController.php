<?php

namespace App\Http\Controllers;

use App\Enums\ReportReason;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * "Report" on driver profiles, companies and jobs (#4651). Staff see the reports in the admin
 * panel's Reports queue. One open report per item per driver, so nobody can flood the queue.
 */
class ReportController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Report::TYPES))],
            'id' => ['required', 'integer'],
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'details' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var User $reporter */
        $reporter = $request->user();
        $class = Report::TYPES[$data['type']];
        $item = $this->findReportable($class, (int) $data['id'], $reporter);

        if ($item === null) {
            abort(404);
        }

        if ($this->isOwnItem($item, $reporter)) {
            return $this->toast('error', __('You can’t report your own profile, company or jobs.'));
        }

        $created = DB::transaction(function () use ($reporter, $item, $data) {
            $already = Report::query()->open()
                ->where('reporter_id', $reporter->id)
                ->where('reportable_type', $item::class)
                ->where('reportable_id', $item->getKey())
                ->lockForUpdate()
                ->exists();

            if ($already) {
                return false;
            }

            Report::query()->create([
                'reporter_id' => $reporter->id,
                'reportable_type' => $item::class,
                'reportable_id' => $item->getKey(),
                'reason' => $data['reason'],
                'details' => $data['details'] ?? null,
            ]);

            return true;
        });

        return $created
            ? $this->toast('success', __('Thanks for the report. Staff will take a look.'))
            : $this->toast('info', __('You’ve already reported this. Staff will take a look.'));
    }

    /** The item, if it exists and the reporter can see it. */
    private function findReportable(string $class, int $id, User $reporter): User|Company|Delivery|null
    {
        return match ($class) {
            User::class => ($u = User::query()->find($id)) && $u->isVisibleTo($reporter) ? $u : null,
            Company::class => Company::query()->find($id),
            Delivery::class => Delivery::query()->visibleTo($reporter)->whereKey($id)->first(),
            default => null,
        };
    }

    private function isOwnItem(User|Company|Delivery $item, User $reporter): bool
    {
        return match (true) {
            $item instanceof User => $item->is($reporter),
            $item instanceof Company => $item->roleOf($reporter) !== null,
            $item instanceof Delivery => $item->user_id === $reporter->id,
        };
    }

    private function toast(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
