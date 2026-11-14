<?php

/*
 * Server-side checks on synced jobs (#4642). The Hub doesn't trust the app's own `flagged`
 * field alone: a modified client could send anything, and leaderboards make that worth doing.
 * A job that fails a check gets a `hub_flag_reason` and stays off stats and leaderboards.
 * `App\Sync\JobChecks` runs these; tune the numbers here.
 */
return [

    // Same limits as ProjectTrucks' own sanity check (src-tauri/src/commands/jobs.rs).
    'max_avg_speed_kmh' => 150,
    'min_delivery_km' => 1.0,

    // The app's avg_speed_kmh is distance / duration. A reported speed this far from that
    // (whichever is larger) means the numbers were edited.
    'speed_mismatch_kmh' => 10,
    'speed_mismatch_ratio' => 0.25,

    // duration_sec is the real time between pickup and delivery: it can't be longer than the
    // time between started_at and completed_at (plus this slack for clock rounding).
    'duration_slack_sec' => 300,

    // Income above `income_flat + distance_km * income_per_km` is more than any cargo pays.
    // Kept generous: special transport pays far more per km than a normal load.
    'income_per_km' => 300,
    'income_flat' => 25000,

    // completed_at more than this far ahead of the server clock.
    'future_slack_sec' => 600,

    // Driving time on one UTC day (by completed_at), across all of a driver's jobs.
    'max_driving_sec_per_day' => 86400,

    // More jobs than this completed within any hour are held for review, not counted.
    'max_jobs_per_hour' => 6,

];
