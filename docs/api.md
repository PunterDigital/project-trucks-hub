# ProjectTrucks Hub API (v1)

The API ProjectTrucks uses to link to an account and sync jobs. JSON in, JSON out; send
`Accept: application/json`. Base URL: the Hub's `APP_URL` + `/api/v1`.

Nothing leaves the driver's PC until they link an account. Job **notes are never sent**.

## 1. Linking (device code)

Modelled on the OAuth 2.0 device authorization grant (RFC 8628).

### `POST /device/code`

```json
{ "client_name": "ProjectTrucks on DESKTOP-1234" }
```

`client_name` (max 100) is what the driver sees on the approval page and in
Settings → Linked apps. Rate limit: 10/min per IP.

```json
{
    "device_code": "64 random characters, keep secret",
    "user_code": "BCDF-GH23",
    "verification_uri": "https://hub.example/link",
    "verification_uri_complete": "https://hub.example/link?code=BCDF-GH23",
    "expires_in": 900,
    "interval": 5
}
```

Show `user_code` and open `verification_uri_complete` in the browser. The driver signs in (or
signs up) and approves.

### `POST /device/token`

```json
{ "device_code": "…" }
```

Poll every `interval` seconds until it answers 200:

| Status | `error`                 | Meaning                                       |
| ------ | ----------------------- | --------------------------------------------- |
| 400    | `authorization_pending` | Not approved yet: keep polling                |
| 400    | `slow_down`             | Polled faster than `interval`: wait longer    |
| 400    | `access_denied`         | The driver declined: stop                     |
| 400    | `expired_token`         | Expired (15 min) or already used: start again |
| 200    | –                       | Linked                                        |

```json
{
    "access_token": "1|plain-text-token",
    "token_type": "Bearer",
    "user": {
        "name": "Erin Example",
        "username": "erin",
        "profile_url": "https://hub.example/drivers/erin"
    }
}
```

Store the token (Windows Credential Manager, not the SQLite settings table) and send it as
`Authorization: Bearer <token>` on every call below. A 401 means the driver unlinked the app
on the website: drop the token and show "Not linked".

## 2. Account

- `GET /me` → `{ id, name, username, avatar, company: {name, slug, tag} | null, profile_url, profile_private, jobs_synced }`
- `DELETE /token` → 204. "Unlink" in the app: revokes the token it is using.

## 3. Jobs

### `POST /jobs`

Up to **100** jobs per call. Fields are the app's own `jobs` table columns (`notes` and
`session_id` left out). Re-sending a job with the same `id` updates it, so edits and retries
are safe; a re-sync never changes whether the driver hid the job on the website or which
company it counts for.

```json
{
    "jobs": [
        {
            "id": "7f1c6a8e-3c2b-4d9e-9a51-0c6f2b1e5d10",
            "truck_make": "Scania",
            "truck_model": "S",
            "source_city": "Calais",
            "source_company": "Tradeaux",
            "dest_city": "Paris",
            "dest_company": "Euroacres",
            "cargo_id": "apples",
            "cargo_name": "Apples",
            "cargo_mass_kg": 18000,
            "declared_income": 12450,
            "distance_km": 291.4,
            "duration_sec": 14400,
            "avg_speed_kmh": 72.8,
            "fuel_used_l": 95.2,
            "damage_delta": 0.013,
            "started_at": "2026-11-14T10:00:00Z",
            "completed_at": "2026-11-14T14:00:00Z",
            "status": "DELIVERED",
            "source": "TELEMETRY",
            "flagged": false,
            "flag_reason": null
        }
    ]
}
```

| Field                        | Rules                                                                   |
| ---------------------------- | ----------------------------------------------------------------------- |
| `id`                         | required, ≤ 64 chars, unique in the batch                               |
| `game`                       | optional, `ets2` (default) or `ats`                                     |
| `status`                     | `DELIVERED`, `LATE` or `CANCELLED`                                      |
| `source`                     | `TELEMETRY` or `MANUAL` (see below)                                     |
| `started_at`, `completed_at` | ISO 8601; stored in UTC                                                 |
| `damage_delta`               | 0–1                                                                     |
| `game_duration_min`          | optional, in-game minutes from pickup to delivery (ProjectTrucks 0.11+) |
| `avg_speed_kmh`              | `distance_km` per in-game hour; before 0.11, per real hour              |

A batch with any invalid job is refused whole (422, Laravel validation errors keyed
`jobs.<index>.<field>`). Response:

```json
{
    "synced": 1,
    "jobs": [
        {
            "id": "7f1c…",
            "url": "https://hub.example/jobs/42",
            "hub_flag_reason": null
        }
    ]
}
```

Jobs with `source: MANUAL` (typed in by hand) are never stored (#4751): they are accepted,
left out of `jobs` and listed under `skipped` (`{"id": "…", "reason": "manual"}`), and a copy the
Hub stored before is deleted. ProjectTrucks 0.10+ doesn't send them; older versions read the
missing entry as synced without a link. `skipped` is `[]` when nothing was skipped.

`hub_flag_reason` is set when the Hub's own checks flagged the job (see **What counts**); the
app may ignore it. `hidden` (added for #4654) is whether the driver hid the job on the Hub,
from the app or on the job page; a re-sync never changes it.

### `PATCH /jobs/{id}`

"Hide on Hub" from the app (#4654). Body `{"hidden": true|false}`. Hidden jobs are seen only by
the driver and don't count anywhere (same as the job page's own switch). Response
`{"id": "…", "url": "https://hub.example/jobs/42", "hidden": true}`; 404 when the Hub doesn't
have that job (never synced, or a manual job), 422 without a boolean `hidden`.

### `DELETE /jobs/{id}`

The driver deleted the job in the app. 204 (also when it was never synced).

## 4. Live status ("on the road")

### `PUT /status`

Send on change and at least once a minute while the game is running. A status not refreshed
for **120 s** counts as offline.

```json
{
    "truck": { "make": "Volvo", "model": "FH" },
    "job": {
        "cargo_name": "Cement",
        "cargo_mass_kg": 20000,
        "source_city": "Berlin",
        "source_company": "…",
        "destination_city": "Praha",
        "destination_company": "…",
        "income": 9000,
        "driven_km": 120.5,
        "distance_left_km": 230,
        "progress_pct": 34.4,
        "parked": false
    },
    "position": { "x": -12345.7, "z": 4321.0, "heading_deg": 90.0 }
}
```

`job` follows ProjectTrucks' `CurrentJob` (snake_case). `job: null` with a `truck` means free
roam.

`position` (optional, ProjectTrucks 0.9+) feeds the live map (`/map`): SCS world metres, `x`
grows east and `z` grows south (each within ±1,000,000), `heading_deg` is the SCS heading
(0 = north, counter-clockwise, -360 to 360, optional). Send it on a job and while free-roaming.
The Hub drops it for drivers who switched off "Show me on the live map", and the map never shows
private profiles.

### `DELETE /status`

The game closed or auto-capture was switched off. 204.

## Limits

All authenticated calls share 120 requests/min per account. A 429 carries `Retry-After`.

## What counts

Stats and leaderboards count jobs with status `DELIVERED` or `LATE` that ProjectTrucks did not
flag, the Hub's own checks did not flag, and the driver did not hide.

The Hub re-checks every delivered or late job it receives (`config/anticheat.php`): average
speed over 150 km/h per in-game hour (#4771: the game's odometer counts game kilometres, ~19 per
real one on roads, so a real-time average means nothing), more than 3,000 km per real hour (the
only speed check for jobs without `game_duration_min`), under 1 km, `avg_speed_kmh` or
`duration_sec` that don't match the distance, the game time and the job's start/end times, income far above what the distance pays, a
`completed_at` in the future, more than 24 h driven on one UTC day, and more than 6 jobs
completed within an hour (held for review). A Hub flag stays put: re-syncing the job never
clears it. Cancelled jobs show on the driver's profile but count for
nothing. Drivers with a private profile stay off the public feed and the driver leaderboard;
their jobs still count for their company.
