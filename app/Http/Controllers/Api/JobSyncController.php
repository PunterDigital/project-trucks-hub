<?php

namespace App\Http\Controllers\Api;

use App\Achievements\Progress;
use App\Enums\DeliveryStatus;
use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\User;
use App\Support\DiscordWebhook;
use App\Sync\JobChecks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Jobs pushed by ProjectTrucks. Field names follow the app's own `jobs` table so the client can
 * send rows as they are. Upserts by the job's local id, so a re-sync (or an edit) updates the
 * same row. Notes stay on the driver's PC: they are never accepted here.
 */
class JobSyncController extends Controller
{
    public const MAX_BATCH = 100;

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'jobs' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'jobs.*.id' => ['required', 'string', 'max:64', 'distinct'],
            'jobs.*.game' => ['nullable', Rule::in(['ets2', 'ats'])],
            'jobs.*.truck_make' => ['nullable', 'string', 'max:60'],
            'jobs.*.truck_model' => ['nullable', 'string', 'max:60'],
            'jobs.*.source_city' => ['required', 'string', 'max:80'],
            'jobs.*.source_company' => ['required', 'string', 'max:80'],
            'jobs.*.dest_city' => ['required', 'string', 'max:80'],
            'jobs.*.dest_company' => ['required', 'string', 'max:80'],
            'jobs.*.cargo_id' => ['required', 'string', 'max:80'],
            'jobs.*.cargo_name' => ['required', 'string', 'max:120'],
            'jobs.*.cargo_mass_kg' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'jobs.*.declared_income' => ['required', 'integer', 'min:-100000000', 'max:100000000'],
            'jobs.*.distance_km' => ['required', 'numeric', 'min:0', 'max:100000'],
            'jobs.*.duration_sec' => ['required', 'integer', 'min:0', 'max:31536000'],
            // No upper limit worth refusing a batch for: apps before 0.11 sent km per real hour
            // (#4771), which passes 1,000 on any long job. JobChecks judges the number instead.
            'jobs.*.avg_speed_kmh' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'jobs.*.game_duration_min' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:52560000'],
            'jobs.*.fuel_used_l' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'jobs.*.damage_delta' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'jobs.*.started_at' => ['required', 'date'],
            'jobs.*.completed_at' => ['required', 'date'],
            'jobs.*.status' => ['required', Rule::enum(DeliveryStatus::class)],
            'jobs.*.source' => ['required', Rule::in(['TELEMETRY', 'MANUAL'])],
            'jobs.*.flagged' => ['sometimes', 'boolean'],
            'jobs.*.flag_reason' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $companyId = $user->companyId();

        $skipped = [];
        /** @var list<Delivery> $announce */
        $announce = [];
        $ids = DB::transaction(function () use ($data, $user, $companyId, &$skipped, &$announce) {
            $ids = [];
            foreach ($data['jobs'] as $job) {
                // Jobs typed in by hand stay on the driver's PC (#4751): nothing on the Hub can
                // check them. Older apps still send them, so they're accepted and dropped, and a
                // copy the Hub got before is removed. They're left out of `jobs` (older apps
                // read a missing entry as "synced, no link") and listed under `skipped`.
                if ($job['source'] === 'MANUAL') {
                    Delivery::query()
                        ->where('user_id', $user->id)
                        ->where('client_id', $job['id'])
                        ->delete();
                    $skipped[] = ['id' => $job['id'], 'reason' => 'manual'];

                    continue;
                }

                $delivery = Delivery::query()->firstOrNew([
                    'user_id' => $user->id,
                    'client_id' => $job['id'],
                ]);

                if (! $delivery->exists) {
                    $delivery->company_id = $companyId;
                }

                $delivery->fill([
                    'game' => $job['game'] ?? 'ets2',
                    'truck_make' => $job['truck_make'] ?? null,
                    'truck_model' => $job['truck_model'] ?? null,
                    'source_city' => $job['source_city'],
                    'source_company' => $job['source_company'],
                    'dest_city' => $job['dest_city'],
                    'dest_company' => $job['dest_company'],
                    'cargo_id' => $job['cargo_id'],
                    'cargo_name' => $job['cargo_name'],
                    'cargo_mass_kg' => $job['cargo_mass_kg'],
                    'income' => $job['declared_income'],
                    'distance_km' => $job['distance_km'],
                    'duration_sec' => $job['duration_sec'],
                    'avg_speed_kmh' => $job['avg_speed_kmh'],
                    'game_duration_min' => $job['game_duration_min'] ?? null,
                    'fuel_used_l' => $job['fuel_used_l'] ?? null,
                    'damage' => $job['damage_delta'] ?? null,
                    'started_at' => Carbon::parse($job['started_at'])->utc(),
                    'completed_at' => Carbon::parse($job['completed_at'])->utc(),
                    'status' => $job['status'],
                    'source' => $job['source'],
                    'flagged' => (bool) ($job['flagged'] ?? false),
                    'flag_reason' => $job['flag_reason'] ?? null,
                ]);

                // The Hub's own checks (#4642). A Hub flag is never cleared by a re-sync, so an
                // edited client can't send a job twice to wash it. A job staff have reviewed
                // (#4651) keeps their decision: no more checks.
                if ($delivery->hub_flag_reason === null && ! $delivery->staff_reviewed) {
                    $check = JobChecks::run($delivery);
                    if ($check['reasons'] !== []) {
                        $delivery->hub_flag_reason = implode('; ', $check['reasons']);
                        $delivery->hub_held = $check['held'];
                    }
                }

                $isNew = ! $delivery->exists;
                $delivery->save();

                // Discord (#4652): only jobs the Hub hasn't seen before.
                if ($isNew) {
                    $announce[] = $delivery;
                }

                $ids[] = [
                    'id' => $job['id'],
                    'url' => route('jobs.show', $delivery),
                    'hub_flag_reason' => $delivery->hub_flag_reason,
                    // #4743: 'pending' until staff review a flagged job, then 'approved' / 'rejected'.
                    'review' => $delivery->reviewState(),
                    // Not `$delivery->hidden`: that is Eloquent's own $hidden property.
                    'hidden' => (bool) $delivery->getAttribute('hidden'),
                ];
            }

            return $ids;
        });

        // Levels and achievements (#4645), now that the batch is stored.
        Progress::refresh($user);

        // A first sync can bring a long backlog: a few recent ones at most.
        $posted = 0;
        foreach ($announce as $delivery) {
            if ($posted >= DiscordWebhook::JOBS_PER_BATCH) {
                break;
            }
            $posted += DiscordWebhook::jobDelivered($delivery) ? 1 : 0;
        }

        return response()->json(['synced' => count($ids), 'jobs' => $ids, 'skipped' => $skipped]);
    }

    /**
     * "Hide on Hub" from the app (#4654): the same switch as the job page's own toggle. Only the
     * driver's own synced jobs; 404 for a job the Hub doesn't have (never synced, or manual).
     */
    public function update(Request $request, string $clientId): JsonResponse
    {
        $data = $request->validate(['hidden' => ['required', 'boolean']]);

        $delivery = Delivery::query()
            ->where('user_id', $request->user()->id)
            ->where('client_id', $clientId)
            ->firstOrFail();

        $delivery->forceFill(['hidden' => $data['hidden']])->save();

        return response()->json([
            'id' => $clientId,
            'url' => route('jobs.show', $delivery),
            'hidden' => (bool) $delivery->getAttribute('hidden'),
        ]);
    }

    /** The driver deleted the job in ProjectTrucks. */
    public function destroy(Request $request, string $clientId): Response
    {
        Delivery::query()
            ->where('user_id', $request->user()->id)
            ->where('client_id', $clientId)
            ->delete();

        Progress::refresh($request->user(), notify: false);

        return response()->noContent();
    }
}
