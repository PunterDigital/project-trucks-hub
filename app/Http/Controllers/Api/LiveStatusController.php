<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "On the road" status. ProjectTrucks sends it while the game is running (on change and at
 * least every minute); a status not refreshed for {@see LiveStatus::FRESH_SECONDS} is treated
 * as offline, so a crashed app never leaves a driver "driving" forever.
 */
class LiveStatusController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'truck' => ['nullable', 'array'],
            'truck.make' => ['nullable', 'string', 'max:60'],
            'truck.model' => ['nullable', 'string', 'max:60'],
            'job' => ['nullable', 'array'],
            'job.cargo_name' => ['nullable', 'string', 'max:120'],
            'job.cargo_mass_kg' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'job.source_city' => ['nullable', 'string', 'max:80'],
            'job.source_company' => ['nullable', 'string', 'max:80'],
            'job.destination_city' => ['nullable', 'string', 'max:80'],
            'job.destination_company' => ['nullable', 'string', 'max:80'],
            'job.income' => ['nullable', 'integer', 'min:-100000000', 'max:100000000'],
            'job.driven_km' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'job.distance_left_km' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'job.progress_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'job.parked' => ['nullable', 'boolean'],
            // Live map (#4687): SCS world metres (x east, z south), heading 0 = north, CCW.
            'position' => ['nullable', 'array'],
            'position.x' => ['required_with:position', 'numeric', 'between:-1000000,1000000'],
            'position.z' => ['required_with:position', 'numeric', 'between:-1000000,1000000'],
            'position.heading_deg' => ['nullable', 'numeric', 'between:-360,360'],
        ]);

        $job = $data['job'] ?? null;
        $truck = $data['truck'] ?? null;
        // Drivers who switched the live map off never have a position stored.
        // (A model made in code, not read back, has no value yet: the column default is on.)
        $position = $request->user()->getAttribute('show_on_map') !== false ? ($data['position'] ?? null) : null;

        $status = LiveStatus::query()->updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'truck_make' => $truck['make'] ?? null,
                'truck_model' => $truck['model'] ?? null,
                // A job needs a cargo; without one the driver is free-roaming.
                'cargo_name' => $job['cargo_name'] ?? null,
                'cargo_mass_kg' => $job['cargo_mass_kg'] ?? null,
                'source_city' => $job['source_city'] ?? null,
                'source_company' => $job['source_company'] ?? null,
                'dest_city' => $job['destination_city'] ?? null,
                'dest_company' => $job['destination_company'] ?? null,
                'income' => $job['income'] ?? null,
                'driven_km' => $job['driven_km'] ?? null,
                'distance_left_km' => $job['distance_left_km'] ?? null,
                'progress_pct' => $job['progress_pct'] ?? null,
                'parked' => (bool) ($job['parked'] ?? false),
                'pos_x' => $position['x'] ?? null,
                'pos_z' => $position['z'] ?? null,
                'heading_deg' => $position['heading_deg'] ?? null,
                'updated_at' => now(),
            ],
        );

        return response()->json(['status' => $status->summary(), 'fresh_for' => LiveStatus::FRESH_SECONDS]);
    }

    /** The game closed or tracking was switched off. */
    public function destroy(Request $request): Response
    {
        LiveStatus::query()->where('user_id', $request->user()->id)->delete();

        return response()->noContent();
    }
}
