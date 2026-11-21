# ProjectTrucks Hub — notes for Claude

The web side of ProjectTrucks (`../project-trucks`, Tauri desktop app): drivers link the app
and their jobs sync here; profiles, feed, companies (VTCs), leaderboards, live "on the road"
status. Laravel 13 + Inertia 3 + Vue 3 (Laravel Vue starter kit), Fortify (email/password,
2FA, passkeys), Sanctum (API tokens), Socialite + `socialiteproviders/steam`. Forge-hosted:
a merge to `main` is a production deploy (see the control repo playbook).

## Commands

PHP 8.4: `export PATH="$(ls -d ~/.pvm/versions/php-8.4* | sort -V | tail -1):$PATH"` first in
every Bash call (the default `php` is 8.3), and run Composer as `php <that dir>/composer/composer.phar`.

| What                 | Command                                                                                                                |
| -------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| Tests                | `php artisan test` (PHPUnit, in-memory SQLite; prefix `PAO_DISABLE=1` for full output)                                 |
| PHP style / analysis | `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` (level 7)                                                       |
| Frontend             | `npm run build` (also regenerates Wayfinder `resources/js/routes` + `actions`), `npm run check`, `npm run types:check` |
| E2E                  | `npm run build && npm run test:e2e`                                                                                    |

`php artisan wayfinder:generate --with-form` refreshes route helpers after route changes
without a full build.

## Domain

- `deliveries` = synced jobs (Laravel's queue owns `jobs`; the UI says "jobs", routes are
  `/jobs/{id}`). Upsert key `(user_id, client_id)`, `client_id` = the app's local UUID.
  `company_id` is set when first synced and never changed by a re-sync, nor is `hidden`.
  `hidden` changes only from the job page's switch or the app's `PATCH /api/v1/jobs/{id}`
  (#4654); sync responses carry it so the app can show the state.
- **Never `$this->hidden` inside `Delivery`**: that's Eloquent's `$hidden` array, not the
  column (a job page once said every job was hidden). Use `getAttribute('hidden')`.
- "Counted" (stats + leaderboards) = `DELIVERED|LATE`, not flagged, not hidden:
  `Delivery::scopeCounted()` / `countedConstraint()`; all totals go through
  `App\Support\Stats` and `Leaderboard`, so pages can't drift.
- Anti-cheat (#4642): `JobSyncController` runs `App\Sync\JobChecks` on every delivered/late
  job (thresholds in `config/anticheat.php`, same 150 km/h / 1 km limits as the app). A failure
  sets `deliveries.hub_flag_reason` (`hub_held` = the rate hold, for moderation later); a
  re-sync never clears it. "Counted" excludes it, `summary()['flagged']` is app OR Hub flag.
  E2E jobs must be plausible (distance / duration / start-end span agree) or they won't count.
  Speed (#4771): ETS2's odometer counts game km (~19 per real km on roads), so the 150 km/h
  limit is per **in-game** hour (`game_duration_min`, sent by ProjectTrucks 0.11+); every job
  also gets a 3,000 km per real hour limit, the only one for older apps. Before this every
  recorded job was flagged; the migration re-ran the checks once (`JobChecks::recheckSpeedFlags`).
- Manual jobs (#4751): `source: MANUAL` (typed in by hand in the app) is never stored.
  `JobSyncController` accepts it (older apps still send it), deletes any copy it holds, leaves
  it out of `jobs` (older apps read that as synced, no link) and lists it under `skipped`.
  Migration `2026_11_15_110000` removed the ones stored before. ProjectTrucks 0.10+ doesn't
  send them. `source` is what the app reports, so `JobChecks` stays the real defence.
- Visibility: `Delivery::scopeVisibleTo($viewer)` and `User::scopeVisibleTo` / `isVisibleTo`.
  Private profiles show only to themselves and company mates; they're off the public feed and
  driver board, still in company totals.
- Pages get explicit arrays (`publicSummary()`, `summary()`), never models. The shared
  `auth.user` prop is a fixed field list (`HandleInertiaRequests`); `VisibilityTest` checks no
  email reaches public pages.
- Companies: one per driver (`company_members.user_id` unique). Owner ⊃ manager ⊃ driver;
  rules in `CompanyMembershipController`. Every way in (open join, accepted application,
  invite link) goes through `App\Support\CompanyRoster::add()` (withdraws other applications,
  "Team player") and `announceJoin()` (owner's bell + Discord), so they can't drift.
- Live status: one row per driver, fresh for `LiveStatus::FRESH_SECONDS` (120 s).
- Device linking: `DeviceAuthorization` (RFC 8628 style), device code stored as sha256, user
  code 8 chars without vowels. Tokens are Sanctum PATs with ability `sync`; the website lists
  them under Settings → Linked apps. Contract for the app: `docs/api.md`. Change the API
  only additively; ProjectTrucks releases lag behind.
- Steam: sign-in is Steam's **OpenID** provider only (`App\Auth\SteamProvider`, our subclass of
  socialiteproviders/steam, registered in `AppServiceProvider`). The Web API key is never used
  to authenticate: `STEAM_API_KEY` is optional and only fetches name + avatar
  (GetPlayerSummaries, over https; the upstream provider used http and required the key). A
  failed lookup still signs in. `STEAM_LOGIN=false` hides it (E2E does, so no test hits Steam).
  The buttons are Valve's own "Sign in through Steam" image from Steam's CDN (Valve asks OpenID
  sites to use them). `allowed_hosts` comes from `APP_URL`. New Steam players finish sign-up
  with an email (random password; "Forgot password" sets one). 2FA accounts still go through
  Fortify's challenge (`login.id` in the session).
- Passwords: `users.password_set_at` says whether the driver chose one (`hasPassword()`;
  set it with `setChosenPassword()`, never a bare `password` update). Steam sign-ups have
  null, so nothing asks them for a password: the confirm page offers "Confirm with Steam"
  (`steam.confirm`, only the linked Steam id counts, sets `auth.password_confirmed_at`) or a
  passkey; Security shows "Set a password" with no current password, and delete/set-password
  need a recent confirmation (`App\Support\PasswordConfirmation`) instead (#4672). Factory
  users have a password; E2E's `steve@example.com` is Steam-only (hash "password" only so
  E2E can log in).
- `config/sanctum.php` `stateful` is empty on purpose: the API is bearer-token only.
- Privacy (#4641): `/privacy` + `/terms` (`LegalController`, bump `UPDATED` when the wording
  changes; contact = `HUB_CONTACT_EMAIL`, else the GitHub repo). Keep `legal/Privacy.vue` in step
  with what the app syncs (`docs/api.md`): new synced fields (e.g. map position) need a line
  there. Settings → Privacy exports `PrivacyController::exportFor()` (no secrets) and deletes
  synced jobs only. Sanctum tokens are a morph with no FK: `User::booted()` deletes them when
  the account goes; everything else cascades.
- `/download`: ProjectTrucks' latest GitHub release via `App\Support\LatestRelease` (GitHub API,
  cached 15 min, last good answer kept forever, only `github.com/PunterDigital/project-trucks/
  releases/download/` URLs accepted). `/download/windows[/msi]` are permanent links that 302 to
  the current installer. E2ESeeder puts a release in the cache so E2E never calls GitHub. Every
  "Get ProjectTrucks" link points here, not at the personal-apps downloads page. The install
  steps on the page name the app's real labels ("Install plugin", "Tracking", Settings →
  ProjectTrucks Hub): keep them in step with ProjectTrucks.

## Live map (#4687)

- `/map` (`MapController`, `pages/map/Index.vue`): Leaflet with a `CRS.Simple` whose
  transformation maps SCS world metres (lng = x east, lat = z south) onto the zoom-0 tile box
  from `public/map-tiles/ets2/TileMapInfo.json` (`App\Support\MapTiles`). Without that file the
  pins still work on a plain dark background (rough ETS2 bounds). Not `public/map/`: a folder
  named like the route would be served instead of Laravel.
- Pins: fresh `live_statuses` with `pos_x/pos_z`, public profiles with `show_on_map` only.
  Headings are SCS (0 = north, counter-clockwise), so CSS rotates by `-heading`. Popups are
  built from text nodes (names and cities come from drivers). `usePoll` reloads only
  `drivers` every 30 s.
- The API stores `position` only while `show_on_map` is on; switching it off in Settings →
  Profile also clears the stored position at once.
- Tiles: `node tools/map-tiles/generate.mjs --game "<ETS2 folder>"` (Windows, .NET 10 SDK)
  compiles ts-map's library at a pinned commit into `TileExporter` (console, no window), renders
  in several processes (GDI+ overlay bitmaps can't be shared between threads) and replaces
  `public/map-tiles/ets2`. Rerun after a game or map DLC update and commit the result.
  The first render (ETS2 1.59 + map DLCs on Shay's PC, Nov 14): 32 s with 8 processes, 5,461
  tiles to zoom 6, 17.8 MB, plus `Cities.json` (every city's x/z). Each tile is drawn with a
  128 px margin and cropped, so city names carry across tile edges. ts-map's own `Logger.cs` is
  swapped for `QuietLogger.cs`: the original locks `%LOCALAPPDATA%\ts-map\TsMap.log`, so parallel
  processes crashed. The tile layer's bounds sit a hair inside the grid (Leaflet asked for
  edge-touching tiles, 404s).
- Zoom 8 (#5207): tiles are rendered to zoom 8 (`--max-zoom 8`, the default), the map's
  closest zoom, so no level is a stretched copy. Blank tiles (every pixel the background
  `#181b22`, `HubPalette.BackgroundColor`) are not written: `generate.mjs` writes
  `TileIndex.json` (per zoom a base64 bitmask, bit `x·2^z + y`), `MapTiles::info()` passes it
  as `tiles.present`, and the page's `BlankAwareTiles` layer draws an empty div for a missing
  tile instead of requesting it (a 404 would be a console error). No index or a bad one =
  every tile requested. Render of Nov 21 (ETS2 1.59): 256 s with 8 processes, 20,231 of
  87,381 tiles kept (only 19% of zoom 8), 81 MB. Keep the map container's background equal
  to the palette's if either changes. `map.spec.ts` zooms to 8 near Fred Free and back out,
  and fails on any tile 404.
- E2E seeds Mona Map (job), Fred Free (free roam) and Pia Private with `updated_at` a day
  ahead so they stay fresh however late `map.spec.ts` runs. Leaflet markers are buttons named by
  their `title`: use `exact: true`, the list's "Show X on the map" buttons contain the name.

## Admin panel (#4664)

- Filament 5 at `/admin` (`App\Providers\Filament\AdminPanelProvider`), no Filament login: staff
  sign in on the site and use the user menu's "Admin" link (a plain `<a>`, the panel isn't
  Inertia; `LeaveInertia` turns stray Inertia visits into full loads). Assets are published by
  `filament:upgrade` on `composer install` and gitignored, as in Panel.
- Access: `users.is_staff` (never fillable), granted only with `php artisan hub:staff {email}
  [--revoke]`. `AdminAuthenticate` (a subclass of Filament's `Authenticate`, in its place)
  answers 404 to guests and drivers and sends staff without 2FA to Settings → Security. A
  separate middleware next to Filament's never ran first: Laravel's middleware priority sorts
  every `AuthenticatesRequests` middleware ahead. Shared `auth.user.is_staff` is only for the link.
- Users and companies bind by username / slug on the site; their admin routes are
  `/{record:id}` plus `$recordRouteKeyName = 'id'`. Without the `:id` in the route, Filament's
  own links used the username while the page looked up an id (404; E2E caught it).
- Every staff action goes through `App\Admin\Audit::record()` into `admin_audit_log`
  (read-only resource "Audit log"). New admin actions must call it.
- Resources live in `app/Filament/Resources/<Name>/`; `deliveries` is the "Jobs" resource
  (slug `jobs`). Hidden: use `getAttribute('hidden')`, as in the model.

## Admin stats dashboard (#4665)

- `App\Filament\Pages\Dashboard` (found by `discoverPages`, replaces Filament's own) with a
  Period picker (7/30/90 days, `HasFiltersForm`); widgets in `app/Filament/Widgets` read it via
  `InteractsWithPageFilters` → `Dashboard::days()`. Stat cards say "Up 12%" against the window
  before; charts are `DailyChart` subclasses (Chart.js line, grid colour set for dark mode);
  tables: most active drivers / companies this week (counted km since Monday 00:00 UTC),
  recent sign-ups, recently flagged jobs (app flag or Hub flag). No polling on any widget.
- Numbers come from `App\Admin\DailyStats`: one `daily_stats` row per closed UTC day and
  metric (`METRICS`; add new ones at the end), today always live. Closed days are final.
  `fill()` stores missing closed days (zeros before the first account without querying);
  the dashboard calls it for its window on load, `hub:daily-stats` runs it at 00:05 (needs
  the scheduler, #4762), and the migration ran it as the backfill. `--force` recomputes.
- Link requests are pruned a day after they expire, so `DeviceAuthorizationController::code`
  fills the last two days before pruning, or those counts would be lost without the scheduler.
- Active drivers over a window are counted distinct live (`deliveries` index on
  `created_at, user_id`), not summed from the daily rows. "Steam sign-up" = `steam_id` set when
  the day was rolled up. "Drivers with a linked app" = distinct users with a Sanctum token, now.
- E2E: `admin.spec.ts` checks the cards, chart headings and tables. The tables load lazily
  when scrolled into view (`mouse.wheel` in `toPass`). Seeded accounts are partly backdated
  (Tina a year), so don't assert a "newest sign-up" name.

## System pages (#4666)

- Admin nav group "System": `App\Filament\Pages\Database`, `Queue` and `Scheduler` (widgets
  in `app/Filament/Widgets`, custom-data tables via `->records()`).
- `App\Admin\QueueStats`: waiting / running jobs per queue from the database queue's `jobs`
  table ("worker looks down" once a due job has waited over 5 minutes). Throughput and run
  times are written by the worker: `JobProcessing` / `JobProcessed` / `JobFailed` listeners
  (`AppServiceProvider`) add to the hour's row in `queue_job_stats` (job class + queue). Jobs
  on the `sync` connection (bell rows, E2E's whole queue) aren't counted. A failing stats
  write is reported, never thrown into the job.
- Failed jobs: `App\Models\FailedJob` (read-only model over `failed_jobs`; `name` = payload
  `displayName`, also the audit label). Retry / Forget call `queue:retry` / `queue:forget`
  and write `queue.job_retried` / `queue.job_forgotten` to the audit log first.
- Tests: `Tests\Support\QueueProbeJob` on the `database` connection + `queue:work --once`
  fires the real worker events. E2ESeeder adds one failed `PostToDiscord` that
  `admin.spec.ts` forgets; that spec walks every admin page, so it has a 90 s timeout.
- `App\Admin\DatabaseStats`: MySQL/MariaDB read `information_schema.TABLES` (rows are InnoDB's
  estimate; uptime and `Threads_connected` from `SHOW GLOBAL STATUS`). Other drivers use
  `Schema::getTables()` (size only where SQLite has `dbstat`: Windows PHP's doesn't, so
  locally sizes are "—" and growth is in rows) plus `COUNT(*)`. Never a host or credentials.
- `db_table_snapshots`: one row per day and table, `table_name` '*' = whole database.
  `hub:db-snapshot` (00:10) or opening the Database page stores today's once. Growth = now vs
  the latest snapshot on or before 7 / 30 days ago ("—" until there is one).
- Checked against MySQL 8.4 in Docker (`php:8.4-cli` + `docker-php-ext-install pdo_mysql` on a
  private network; migrations, sizes, uptime, snapshots, growth all worked).
- `App\Admin\SchedulerStatus`: a heartbeat in the cache each time `schedule:run` starts
  (`CommandStarting` listener in `AppServiceProvider`), and each task's last run from
  `ScheduledTaskFinished` / `ScheduledTaskFailed`. Red after 10 minutes without a heartbeat.
  Laravel doesn't turn Symfony's command events into `CommandStarting` under unit tests, so
  `$this->artisan('schedule:run')` never beats: the test dispatches the event itself.
- E2E has no scheduler: the page says "Never run here".

## Storage and housekeeping (#4667)

- System → Storage (`App\Filament\Pages\Storage`, slug `storage`): `App\Admin\StorageStats`
  measures the disk holding `storage/` (`disk_total_space` / `disk_free_space`; "low" = under
  10% or 5 GB free, also a red "Low" nav badge), each upload folder on the public disk
  (avatars, logos, banners, `og` cards), `storage/logs`, the framework's cache / sessions /
  views folders and the database (`DatabaseStats::totalBytes()`). `.gitignore` placeholders
  aren't counted. Cache and sessions use the database store here, so those folders stay tiny.
- `storage_snapshots`: one row per day and item (`ITEMS` keys + `disk_total` / `disk_free`).
  `hub:storage-snapshot` (00:20) or opening the page stores today's once. The chart shows
  uploads / database / logs in MB with disk used on a right-hand axis; growth = now vs the
  latest snapshot on or before 30 days ago.
- `App\Admin\Housekeeping`: prune link requests expired over a day (rolls up the last two
  days' stats first; `DeviceAuthorizationController::code` calls the same method), prune live
  statuses not updated for 24 h (only fresh ones are ever shown), delete `*.log` files last
  written over 14 days ago (the `single` channel's `laravel.log` is never that old: only the
  `daily` channel's rotated files go). Page buttons write `housekeeping.*` audit rows;
  `hub:prune` (00:25) runs all three without auditing.
- Tests: `StoragePageTest` (fake public disk, temp folders for log and folder-size checks).
- System → Data usage (`DataUsage`, slug `data-usage`): `App\Http\Middleware\RecordApiUsage`
  wraps the whole `/api/v1` group and adds each request to the hour's `api_usage_stats` row
  per endpoint (route name without `api.`): requests, bytes received, 429s, other errors, and
  for `jobs.store` jobs synced / skipped (manual) / Hub-flagged / refused (every job in an
  error batch), read from the JSON answer. No per-request rows, nothing about who sent it.
  It is in the middleware **priority list** before `AuthenticatesRequests`
  (`bootstrap/app.php`): Laravel sorts priority middleware (auth, throttle) ahead of a route
  group's own, so without that 401s and 429s never reached it. A failing write is reported,
  never thrown into the API answer (`App\Admin\ApiUsage`, same insertOrIgnore +
  incrementEach as `queue_job_stats`).
- Per-driver data: the top 20 by stored jobs; the size estimate is their share of the
  `deliveries` table where its size is known (MySQL), "—" otherwise.
- Test-client quirk: once a request with a Sanctum token has worked, later requests in the
  same test stay signed in; send unauthenticated requests first (`DataUsagePageTest`).

## Moderation (#4651)

- Reports: "Report" button (`components/hub/ReportButton.vue`) on driver, company and job
  pages for signed-in viewers who aren't the subject (not their own profile/job, not their
  own company). `POST /reports` (`ReportController`, throttle 10/min) takes `type`
  (driver|company|job → `Report::TYPES`), `id`, `reason` (`ReportReason`), `details`. One open
  report per item per reporter. Plain morphs with class names: no morph map, since Sanctum's
  tokens are morphs too and a map would orphan every existing token.
- Admin: Reports queue (Open/Resolved/All tabs, red nav badge = open count), the item shown
  inline, "Open in admin" + "Resolve" (note). Staff buttons are on the item's own page and go
  through `App\Admin\Moderation` (each refuses a stale state and writes the audit log) via
  `App\Filament\ModerationAction` (modal with a required reason):
    - Users: Suspend / Lift suspension. Suspend = `users.suspended_at` + reason, tokens deleted,
      live status deleted, database sessions deleted. `SignOutSuspended` (web group) signs a
      suspended account out on its next request and the login page shows why; `AdminAuthenticate`
      and `canAccessPanel` turn suspended staff away; the device-token endpoint refuses them.
      Suspended drivers are off `User::scopeVisibleTo`/`isVisibleTo`, the job visibility scope,
      the map, the driver board, company member lists, and `counted` (their jobs don't count
      anywhere until lifted). Their profile is a 404, not the "private profile" page.
    - Jobs: Hide / Unhide (`deliveries.staff_hidden`: the driver still sees it with a note and
      can't show it again), Approve / Reject (see **Pending review** below; they replaced
      "Flag as cheated" and "Clear Hub flag"). Both set `staff_reviewed`, and
      `JobSyncController` skips `JobChecks` on them.
    - Companies: Reset name and description (name/tag/slug → "Company 12"/"C12"/"company-12",
      description, logo and banner removed), Remove member (never the owner).
- E2E (`moderation.spec.ts`): rex-report reports troll-tom; mo-mod (staff, its own TOTP
  secret) resolves it and suspends/lifts susie-suspend. Fortify refuses a reused TOTP code, so
  `logInWithTotp` (`tests/e2e/totp.ts`) waits for the next 30 s window before a second
  sign-in with the same secret. Filament's form modal labels end in "*": find fields with
  `getByRole('textbox', { name: /^Reason/ })`, `getByLabel(/^Reason/)` never matched.

## Pending review (#4743)

- A job flagged by the app (`flagged`) or the Hub's checks (`hub_flag_reason`) is **pending
  review** until staff decide: `deliveries.review` null → `approved` / `rejected`, with
  `review_note` and `reviewed_at`. `Delivery::reviewState()` gives pending / approved / rejected
  / null; `isCleared()` = null or approved. The flag columns stay as the record of what fired.
- "Cleared" is part of both `scopeVisibleTo` (other viewers) and `counted`: a pending or rejected
  job shows only to its driver (job page, profile list, feeds, company page, link previews) and
  counts nowhere. Approved jobs show and count even with the app's flag. Discord posts only
  cleared jobs; one approved later isn't posted.
- Driver's view: `JobStatusBadge` "Pending review" / "Rejected"; the job page says why
  (`review_reasons`, `Delivery::REVIEW_TIME` "Reviews usually take no more than 12 hours.") or
  shows the rejection note. Summaries carry `review` instead of the old `flagged`.
- Admin → Jobs: "Pending review" tab + amber nav badge (count), "Rejected" tab, Review column.
  `Moderation::approveJob` (only pending or rejected jobs) / `rejectJob` (any job not already
  rejected) write `job.approved` / `job.rejected` to the audit log and refresh levels.
- The sync API answers `review` per job (additive, `docs/api.md`).
- The migration turned old staff flags (`Moderation::STAFF_FLAG_PREFIX`) into rejections;
  nothing writes the prefix any more. No notification goes to the driver on a decision.
- E2E (`review.spec.ts`): Rhea Review's seeded Hub-flagged job; Ray Reviewer (staff, own TOTP
  secret) approves it. The admin Jobs nav link is now "Jobs 1" while anything is pending: match
  it by prefix.

## Notifications (#4643)

- One class, `App\Notifications\HubNotice` (named constructors per event), typed by
  `App\Enums\NoticeType`. It holds strings only (message, url, subject), never models, so a
  queued email still sends after the membership or application it was about is gone.
- Sent from `CompanyMembershipController` (apply → owner + managers, once per application;
  decide → applicant; open join → owner; role change → member, not when the role is unchanged;
  handover → new owner; remove → member), `Moderation::removeMember` (staff removal) and
  `DeviceAuthorizationController::token` (app linked, `afterCommit`). Suspended drivers get
  nothing (`via()` returns `[]`).
- The bell's database rows go on the `sync` connection (`viaConnections`), emails on the
  default queue: **production needs a queue worker** (Forge daemon `queue:work`). Without one
  the emails wait in `jobs`; the bell still works.
- Emails: `ApplicationReceived`, `ApplicationDecided`, `AppLinked` (`NoticeType::emails()`),
  on by default; `users.mail_opt_outs` lists the ones switched off in Settings → Notifications.
  Bell notices always show, except `bellOptional()` ones (company news, #5224).
- The bell (`components/hub/NotificationBell.vue`, in `AppSidebarHeader`) reads the shared
  `notifications` prop (`HandleInertiaRequests::notificationsFor()`: unread count + latest 15),
  so the 60 s auto-refresh updates it. Opening an item posts to `notifications.open` (marks
  read, redirects only to our own URLs); "Mark all read" → `notifications.read-all`.
- Account deletion removes notifications (morph, no FK: `User::booted()`); the privacy
  export includes them and the email opt-outs.
- E2E (`notifications.spec.ts`): abe-apply applies to nora-notify's Bell Freight. An open
  reka menu hides the rest of the page from the accessibility tree: press Escape before
  asserting the bell's accessible name.

## Uploads (#4644)

- Profile picture (Settings → Profile, `Settings\AvatarController`), company logo and banner
  (company edit page, `CompanyImageController`, owners/managers). `App\Support\ImageStore`
  decodes every upload with intervention/image 4 (GD), crops it (`ImageKind`: 256² avatar/logo,
  1500×400 banner) and re-encodes WebP, so nothing from the original file (EXIF, GPS, hidden
  bytes) is kept. PNG/JPEG/WebP, 2 MB (`ImageStore::RULES`). Random 40-char names; the old
  file is deleted on replace/remove, account deletion, company deletion and the staff
  "Reset name and description".
- Served by `MediaController` at `/media/{avatars|logos|banners}/{name}.webp` from the
  `public` disk: outside the web group (no cookies), cached a year (`immutable`). No
  `storage:link` needed on Forge. `storage/app/public` must survive deploys (it does: Forge
  shares `storage/`).
- Pages read `User::avatar()` (upload, else the Steam `avatar_url`), never `avatar_url`
  directly; companies carry `logo` in `summary()`. Removing with no upload clears the Steam
  picture (linking Steam again brings it back).
- E2E: `PUBLIC_DISK_ROOT` (config/filesystems.php) points the disk at a temp folder; Uma
  Upload owns Picture Perfect Haulage (`uploads.spec.ts`).

## Levels and achievements (#4645)

- `App\Achievements\Progress`: level from counted distance (level n needs 1,000·(n−1)^1.5 km,
  so level 2 = 1,000 km), a rank every 5 levels (`RANKS`), stored in `users.level` so cards and
  leaderboards (`publicSummary()` → `level`, `rank`) need no query. Achievements are the
  `Achievement` enum (add cases at the end); `driver_achievements` holds key + `unlocked_at`
  (the completed_at of the job that earned it; "Top ten" = the end of that week).
- `Progress::refresh($user)` recomputes everything and stores the difference: runs after a
  sync batch (bell notice per new one), API job delete, Settings → Privacy job delete, staff
  hide/unhide/flag/clear flag, and joining or founding a company. It reads
  `Delivery::scopeEarned()` = counted **including** suspended drivers' jobs, so a suspension
  hides progress without wiping it. A job that stops counting takes its achievements away;
  "Team player" (`permanent()`) stays after leaving.
- "Top ten": a finished Monday–Monday UTC week where fewer than 10 public drivers drove
  further. The HAVING compares with `CAST(? AS DECIMAL(12,3))`: SQLite compares a bare bound
  value as text and nobody was ever ahead. Awarded on the driver's next sync, or by the
  scheduled `hub:progress --notify` (Mondays 00:15, needs `schedule:run` on the server).
- `hub:progress` (silent) ran from the migration as the backfill; E2ESeeder calls it last.
- Profile: level + progress bar in the header, Jobs | Achievements tabs (`#achievements` opens
  the second, the bell links there). Joining a company now rings the bell twice (decision +
  "Team player"): `notifications.spec.ts` expects 2 unread.

## Kudos and comments (#4646)

- `delivery_kudos` (one per driver and job, unique) and `delivery_comments` (plain text, ≤
  `DeliveryComment::MAX_LENGTH` = 1,000, printed with `{{ }}` + `whitespace-pre-line`, never as
  HTML or links). Both cascade with the job and with the writer's account.
- `DeliveryReactionController`: give/take kudos (`POST`/`DELETE jobs/{id}/kudos`, 60/min),
  comment (10/min), delete comment (author or the job's driver). Every action checks
  `Delivery::scopeVisibleTo` first (404 otherwise), so hidden jobs and private profiles follow
  the job page's rules. No kudos on your own job (403; the UI shows only the count).
- Job lists get `kudos`, `kudos_given`, `comments` and `driver_id` in `summary()` from
  `Delivery::scopeWithReactions($viewer)` (Feed, driver profile, job page). A list that skips
  the scope shows 0. Suspended drivers' kudos and comments are left out of counts and lists.
- Bell only (no email): `KudosReceived` (once per giver and job, so take-back + give again
  doesn't ring twice) and `CommentReceived` (links to `#comments`). Nothing for your own job.
- Privacy export lists kudos given and comments written; the privacy page says comments show
  with your name to anyone who can see the job, even from a private profile.
- E2E: Kira Kudos reacts to Jack Job's Rotterdam → Lyon job (`reactions.spec.ts`). The job is
  short and two days old on purpose: an 840 km one topped the leaderboard and the feed, and
  `public.spec` / `sync.spec` expect other rows first.

## Company events (#4648)

- `company_events` (UTC `starts_at`, game, server, start/dest city + company, route notes,
  `max_drivers`, `open`, `cancelled_at`) and `event_rsvps` (going / maybe / not_going, one per
  driver and event). `CompanyEventController`; `/events` lists every company's upcoming ones,
  the company page shows the next five ("Upcoming events", "New event" for owners/managers).
- "Upcoming" = not cancelled and starting less than `UPCOMING_GRACE_HOURS` (3) ago. Answers
  close then, and on cancel. Every company is public, so every event is; members answer, and
  with `open` any signed-in driver can (`CompanyEvent::canAnswer`). A full event refuses new
  "going" answers (maybe still works).
- Times: the form's `datetime-local` is the browser's local time; a hidden `starts_at` input
  sends it as UTC ISO. Pages format with `formatEventTime` (viewer's zone). Create and changed
  start times must be in the future; an unchanged past one can still be edited.
- `.ics`: `/events/{id}.ics` (`App\Support\EventCalendar`, RFC 5545 escaping + 75-octet folding,
  a 2 h slot, `STATUS:CANCELLED` once cancelled).
- Cancelling rings the bell for going + maybe (`NoticeType::EventCancelled`, no email).
- Attendees: private-profile drivers show as "Private driver" to people who can't see them
  (`User::isVisibleTo`); suspended drivers are left out.
- E2E (`events.spec.ts`): Eve Events owns Convoy Club, Max Member answers, Otto Outsider can't.

## Charts (#4650)

- `App\Support\Charts`, counted jobs only. Driver profile: `charts.distance` + `charts.cargo`
  (top 8 by jobs, all time). Company page: `chart` = one series per driver, the top 5 by distance
  in the window plus "Others"; private drivers are "Private driver" to anyone who couldn't see
  them on the members list.
- Distance is sent as sparse `[unix seconds, km]` 15-minute **UTC** buckets for the last 31
  days (+1 day each side), and `components/hub/DistanceChart.vue` folds them into the viewer's
  own last 30 days with `Date`'s local getters. Every real time zone is a whole number of
  quarter hours from UTC, so no offset is sent and DST needs nothing. Bars are plain divs
  coloured with `var(--chart-n)` (light and dark), no chart library.
- Buckets are made in PHP from `completed_at` (no SQL date functions, so SQLite and MySQL agree).
- E2E (`charts.spec.ts`): Cora (23:30 UTC) and Cody (12:30 UTC) of Chart Haulage, eight days
  ago (before this week, accounts backdated); Playwright's `timezoneId` shows them on one UTC day and two Tokyo days.

## Link previews (#4652)

- `App\Support\LinkPreview`: Open Graph + Twitter tags and a 1200×630 PNG card for driver
  profiles, jobs, companies and events. Controllers pass `->withViewData('preview', fn () =>
  LinkPreview::meta(type, id))`; `app.blade.php` prints the tags. A closure so Inertia's JSON
  reloads (auto-refresh) never run its queries; Blade, not `<Head>`, because production runs
  without SSR (and crawlers don't run JS). With SSR (#5157) the tags still come from Blade.
- Built **as a guest** sees the item, whoever views the page: a company mate looking at a
  private profile gets no preview tags, so a pasted link can't show it. Not guest-visible
  (private, hidden, suspended) = no tags and a 404 card.
- Cards: `/og/{driver|job|company|event}/{id}.png` (`PreviewImageController`, outside the web
  group: no cookies). Drawn with GD via intervention/image and DejaVu Sans
  (`resources/fonts`, covers ä/ł/Cyrillic), cached on the public disk as
  `og/{type}/{id}-{hash}.png`. The hash covers the card's content (plus a layout version in
  `hash()`: bump it when the design changes) and is the `?v=` on `og:image`, so Discord fetches
  a changed card anew; the older file is deleted when the new one is drawn.
- E2E: `link-preview.spec.ts` (Nordic Haulage's tags and card, Jack's job, Pia gets none).

## Search tags, sitemap, robots (#5149)

- `App\Support\Seo::forRequest()` (called in `app.blade.php`) gives each page its `<title>`,
  meta description, canonical link and JSON-LD, by route name (`Seo::PAGES`). Blade prints
  them, so crawlers get them without SSR (SSR is #5157). Any route not in `PAGES`, and an item
  page (`ITEM_PAGES`) a guest can't see (no link preview), gets `noindex` and no canonical.
- Titles must equal the page's `<Head title>` (the app's `title` callback adds " - <name>"),
  or the tab changes after load: `SeoTest` and `seo.spec.ts` compare them. Item pages use the
  link preview's title and description, so the company page's Head is `Name [TAG]`. A new
  public page needs a `PAGES` entry (and `SITEMAP_PAGES` if it's static) plus the same title in its Head.
- Canonical and sitemap URLs are built on `APP_URL` (query strings dropped, `?page=n>1`
  kept), so a domain move (#5152) is the env change plus a 301 redirect.
- `/sitemap.xml` (`SitemapController`, outside the web group): static pages, drivers
  `visibleTo(null)` (no private or suspended), every company, events not cancelled. No jobs.
- `public/robots.txt` is static on purpose: Forge's nginx has a `location = /robots.txt` that
  never reaches PHP. Its `Sitemap:` line names the live domain: change it with the domain.
- `public/googlee9845bfde9df5b94.html` is Google Search Console's ownership check (HTML-file
  method, #5161). Keep it, byte for byte (no trailing newline): Google re-checks it, and the
  property loses verification if it goes. `seo.spec.ts` checks it.
- JSON-LD comes from PHP arrays (`Seo::encode`, `JSON_HEX_TAG`), never written in Blade:
  Blade would compile an `@context` key as a directive (AniTrack #4456).

## Server-side rendering (#5157)

- `npm run build` (and its alias `npm run build:ssr`) builds the client assets **and**
  `bootstrap/ssr/app.js` (#5165: Forge's deploy script still ran plain `npm run build`, so the
  daemon found no bundle; now either script name produces it). Inertia's Vite plugin wraps `app.ts`; no
  separate `ssr.ts`). The bundle is its own server: `node bootstrap/ssr/app.js`, listening on
  **127.0.0.1:13722**, baked in at build time in `vite.config.ts` (`INERTIA_SSR_PORT` overrides
  at build time). Not Inertia's default 13714, which another app's SSR daemon may hold on the
  same server (AniTrack moved to 13721 for that).
- `config/inertia.php`: `INERTIA_SSR_ENABLED` (default **false**), `INERTIA_SSR_URL` (default
  `http://127.0.0.1:13722`). Off, nothing calls the daemon, so PHPUnit and the main E2E server
  are unaffected. A leftover local `bootstrap/ssr` doesn't matter while it's off. With it on and
  the daemon down, Inertia falls back to client rendering.
- Production: Forge deploy script runs `npm run build` (or `build:ssr`), and a daemon runs
  `node bootstrap/ssr/app.js` (or `php8.4 artisan inertia:start-ssr`), restarted after each
  deploy (`php8.4 artisan inertia:stop-ssr` after `$ACTIVATE_RELEASE()`; the daemon brings it
  back). `.env` gets `INERTIA_SSR_ENABLED=true`. Shay's steps, card from #5157.
- E2E project `ssr` (`ssr.spec.ts`): a second `serve.mjs` on `E2E_PORT`+2 (own SQLite and
  upload folder) with SSR on, plus the bundle as a webServer (`/health`). Every public page
  and three signed-in ones must have their text inside `<div data-server-rendered="true"
  id="app">`, one `<title>`, and hydrate with no console error (Vue logs hydration
  mismatches as errors). CI's E2E job runs `npm run build:ssr`.
- New components: browser-only APIs (`window`, `document`, `localStorage`, Leaflet) only in
  `onMounted` or event handlers, never in `setup` or the template, or SSR breaks that page.

## Blog (#5150)

- `posts` (title, `slug` unique, `excerpt` ≤ 300, Markdown `body`, `cover_path`, `status`
  `PostStatus` draft|published, `published_at`). Public = `Post::scopePublished()` /
  `isPublic()`: published **and** `published_at` ≤ now. Everything else is a 404 on
  `/blog/{slug}`, staff included; staff use `/blog/preview/{id}` (`canAccessPanel`, else 404;
  noindex). A future `published_at` = Scheduled: it goes live by itself, nothing runs.
- `BlogController`: `/blog` (newest first, `Post::PER_PAGE`), `/blog/{post:slug}` (slug must
  match `Post::SLUG_PATTERN`, so `feed`/`preview` never reach it; `PostResource::RESERVED_SLUGS`
  refuses them as slugs), `/blog/feed` (RSS 2.0, last 20, excerpt only, outside the web group).
  `app.blade.php` links the feed on every page.
- Body: `Post::bodyHtml()` = `Str::markdown` with `html_input => strip` and
  `allow_unsafe_links => false`, sent as `post.html` and printed with `v-html` (safe because
  of that, nowhere else). No image uploads in the body (`fileAttachments(false)`).
- Admin: `PostResource` (List / Create / Edit; "Blog posts" in the nav). Title fills an empty
  slug on blur. Status is a native select; publishing with no time sets now
  (`withPublishTime`). Edit page: Preview (new tab), View on the blog, Delete. Audit actions
  `post.created`, `post.updated`, `post.published`, `post.unpublished`, `post.deleted`.
- Cover: `ImageKind::Cover` (`covers/`, 1200×630 WebP via `ImageStore`, through
  `saveUploadedFileUsing`), served by `MediaController`. The model deletes a replaced or
  removed cover and a deleted post's cover. Counted on the Storage page (`uploads_covers`).
- Search: `Seo::PAGES` `blog.index` / `blog.show` (an item page: indexable only with a link
  preview, i.e. public), `BlogPosting` JSON-LD (`Seo::article`, author = the site), sitemap
  lists published posts, link preview type `post` (card without stat panels: the excerpt gets
  their space).
- E2E (`blog.spec.ts`): the seeded `welcome` post; Bea Blogger (staff, own TOTP secret) creates a
  draft, checks a guest gets 404, previews it (script stripped), publishes. The Markdown editor
  is EasyMDE/CodeMirror: click `.CodeMirror` and type. `seo.spec` and `ssr.spec` include `/blog`
  and `/blog/welcome`.

- Repo drafts (#5153): scheduled Claude runs write posts as `resources/posts/<Y-m-d>-<slug>.php`
  (array: title, excerpt, body; format in that folder's README) in a PR. `App\Support\PostDrafts`
  turns each new file into a **draft** (audit `post.imported`); Shay publishes in the admin.
  Runs hourly (`hub:import-post-drafts`) and in `ListPosts::mount()` (the scheduler may be off).
  `post_imports` records each handled file by name (`imported` / `slug_taken`), so an edited
  file, an edited draft or a deleted draft is never touched again. Invalid files are reported
  (command exits 1) and retried once fixed. `config('blog.drafts_path')` (`BLOG_DRAFTS_PATH`);
  E2E points it at `tests/fixtures/posts`, so `blog.spec.ts` sees "Written in the repo".

## Guides (#5151)

- `/guides` and `/guides/{slug}` (`GuideController`): help pages that answer one ETS2 question
  each. They live in the repo, one file per guide in `resources/guides/<slug>.php` (an array:
  `title` = the page's Head title, `description` ≤ 160, `answer`, Markdown `body`, `faq` pairs,
  `updated`, `order`), loaded by `App\Support\Guides`. Not in `posts`: they describe what
  ProjectTrucks and the Hub do, so **a change to a feature or UI label they name changes the
  guide in the same PR** (and bumps `updated`). Each file says what it was checked against.
- Every claim was checked against both codebases (ProjectTrucks v0.11). Things that are _not_
  there, so guides must not claim them: ATS support in the app, a profit figure, JSON export
  from the app, a plugin uninstall button, convoy tracking. (Company invites exist since #5221;
  `start-or-join-a-vtc` says how they work.)
- Body Markdown goes through the blog's rules (`html_input => strip`). Links are site paths
  (`/download`, `/guides/...`); `GuideTest` GETs every one and fails on a 404.
- Search: `Seo::PAGES` `guides.index` / `guides.show` (the guide's own title/description),
  Article + FAQPage JSON-LD (`Seo::guideData`, the same questions the page shows), sitemap with
  `lastmod` = `updated`. Linked from the sidebar and its footer row (Guides · Privacy · Terms).
- JSON-LD is printed by Blade on full loads only; after an Inertia visit the head keeps the
  first page's. `guides.spec.ts` reads the FAQPage from the server HTML for that reason.

## Company Discord webhook (#4652)

- `companies.discord_webhook` (`encrypted` cast, in `$hidden`), `discord_webhook_disabled_at`,
  `discord_webhook_error`. Pages only get `discordSummary()` (set, last 4 characters, disabled
  state): the URL is the secret and never goes back to a browser. Only Discord's own webhook
  URLs pass `DiscordWebhook::URL_PATTERN`, so the Hub can't be made to post anywhere else.
- `CompanyDiscordController` (owners/managers): save (clears a disabled state), "Send test
  message" (posted **in the request**, so the toast says if it worked; success turns a
  disabled webhook back on), remove. Edit page section `#discord`.
- `App\Support\DiscordWebhook` builds the embeds; `App\Jobs\PostToDiscord` (queued, 4 tries,
  back-off 30 s / 2 min / 10 min) posts them and reads the webhook when it runs. 401/403/404 =
  deleted webhook, switched off at once; 429 waits `retry_after`; a final failure switches it
  off. Switching off rings the bell for owner + managers (`NoticeType::DiscordWebhookDisabled`).
  The stored error is our own wording: HTTP client exceptions name the full URL.
- Posted: a **new** synced job that counts (delivered/late, not flagged/hidden, driver not
  suspended) and finished in the last 24 h, at most `JOBS_PER_BATCH` (3) per sync, so a first
  sync's backlog doesn't flood the channel; joining (open join or accepted application); a new
  event (`<t:…:F>` so Discord shows the reader's time). `allowed_mentions` is empty: names never
  ping anyone. Needs the queue worker (#4762); without it posts wait in `jobs`.
- The privacy page says members' jobs go to the company's Discord even from a private profile.
- E2E (`discord.spec.ts`): Dina Discord saves, sees "…wxyz", removes. The test button isn't
  clicked (it would call Discord); PHPUnit covers it with `Http::fake`.
- vue-tsc here is 3.3.13, which breaks on `https://` in a template attribute
  (vuejs/language-tools#6240): put such strings in the script (the webhook placeholder).

## Sentry (#4653)

- `sentry/sentry-laravel` with AniTrack's `config/sentry.php`, `Integration::handles()` in
  `bootstrap/app.php`. Off unless `SENTRY_LARAVEL_DSN` is set: phpunit.xml forces it empty,
  `playwright.config.ts` passes it empty, `.env.example` leaves it blank. The config reads every
  value with `?:` because an empty `X=` line is `''`, not null (an empty DSN string must mean off).
- `send_default_pii` is hard-coded false; `AppServiceProvider` puts only the user id on the
  scope (`Authenticated` listener). `SentryTest` checks both. The privacy page says so.
- Production DSN: Forge `.env` (Shay's step on #4653); `php artisan sentry:test` on the server
  sends a test event.

## Mail theme (#4661)

- Every email (Fortify's verify / reset, every `HubNotice`) renders with the `hub` Markdown theme:
  `config/mail.php` `markdown`, files in `resources/views/vendor/mail` (only the ones we change:
  `html/header`, `html/message`, `text/message`, `html/themes/hub.css`; the rest fall back to
  Laravel's) and `vendor/notifications/email.blade.php` (salutation "Drive safe,").
- Slate header band with the app icon (`/apple-touch-icon.png`, a PNG: Gmail and Outlook drop
  SVG) and `app.name` as a text wordmark (production's is "Project Trucks"); amber primary
  button with slate text; footer = name + site link. Icon and footer links use `app.url`, so a
  queued mail and one sent during a request look the same.
- Verify / reset wording: `AppServiceProvider::configureAuthMail()` (`toMailUsing`; the reset
  link is built the same way as Laravel's own).
- `MailThemeTest` sends each kind through the array mailer and checks the HTML and plain-text
  parts (inlined colours, icon, footer, no "Laravel").

## E2E

Playwright (`tests/e2e`), headless Chromium, never takes focus. `serve.mjs` resets a throwaway
SQLite file in the OS temp dir, seeds `E2ESeeder` (add records at the END), runs
`php artisan serve` on 127.0.0.1:8775 (`E2E_PORT`). Accounts: `erin@example.com` (no company),
`olga@example.com` (owner of Nordic Haulage, on the road); password `password`.
`sam@example.com` is staff with 2FA: `admin.spec.ts` computes the TOTP from the seeded secret.
Filament's confirm modal is an `alertdialog`; retry the opening click until its heading shows.
`sync.spec.ts` plays the desktop app with Playwright's `request` (device code → approve in
the page → poll → POST jobs / PUT status). Every spec fails on a console error.
Specs share one database and run in file order: assert on named rows, not counts.
Fortify allows **5 logins a minute per email**: CI runs the whole suite in ~35 s, so a new spec
that logs in uses its own seeded account (a sixth `erin` login broke `sync.spec` in CI, #4687).

## Auto-refresh (#4723)

- `useAutoRefresh()` in `layouts/AppLayout.vue` (so every page but `auth/*`): an Inertia
  `router.reload({ async: true })` (props only, scroll and form state kept, no progress bar)
  60 s after the last page load while the tab is visible. Hidden tabs don't poll; coming back
  after more than a minute refreshes at once. It skips while a visit runs or a text field has
  focus. Pages with their own faster `usePoll` (the map) keep it.
- `auto-refresh.spec.ts` drives the minute with `page.clock` and fakes `document.visibilityState`;
  `rita-refresh@example.com` renames herself in a second context.

## Dark theme (#4658)

- The starter kit's `useAppearance` (cookie + localStorage, class on `<html>`, rendered by the
  server from the cookie) drives it: Settings → Appearance has Light / Dark / System, the user
  menu has a Light/Dark toggle (`data-test="theme-toggle"`, the menu stays open).
- `color-scheme` is set with the tokens in `app.css`, so native controls (checkbox, file
  button, date picker, scrollbars) follow. New status colours need a `dark:` partner
  (`text-green-600 dark:text-green-400`); `-500` accents and the slate hero panels work in both.
- Leaflet's controls and popups are styled under `.dark .hub-map` in `map/Index.vue`.
- The Filament admin panel has its own theme switch and isn't touched by this.
- E2E (`appearance.spec.ts`): Tina Theme; checks the painted background, not only the class.

## Follows (#4647)

- `follows` pivot (`follower_id`, `followed_id`, unique pair, cascades with either user);
  `User::following()` / `followers()`. Follow / Unfollow (`FollowController`,
  `POST|DELETE /drivers/{username}/follow`) on other drivers' profiles: following needs
  `isVisibleTo` (a private driver outside your company 404s, as their page would);
  unfollowing always works.
- A follow of someone you can no longer see stays stored but shows nowhere: the Following
  feed goes through `Feed::latestDeliveries` (visibility built in), and the profile counts
  (`followerCount()` / `followingCount()`) only count live follows. Counts only, no lists.
- Home's Latest deliveries has Everyone / Following / My company tabs for signed-in
  drivers (`?feed=`, saved in `users.home_feed`; My company only with a company; junk
  ignored). Guests get Everyone, no tabs. The privacy export lists who you follow.
- E2E: `follows.spec.ts` (Fay Follower follows Fred Followed).
- `PendingReviewTest` rolls back its migration by `--path`: a `--step 1` rollback broke as
  soon as a newer migration existed.

## Company goals (#4649)

- `company_goals` (company, `month` = UTC "YYYY-MM" string, `metric` = `distance_km` |
  `deliveries` | `income` (`CompanyGoal::METRICS`, the `Stats::totals` keys), `target`), unique
  per company + month + metric, cascades with the company. Progress is never stored.
- `App\Support\CompanyGoals`: `setTargets()` replaces the **current** month's targets (a blank
  box deletes that goal); `forPage()` gives the company page `goals` = this month's goals with
  progress from `Stats::totalsBetween()` (counted jobs in the UTC month), each member's share
  (private drivers "Private driver" as on the members list, jobs by drivers who left summed as
  "Former members"), and up to 12 past months with goals as hit / missed.
- `PUT companies/{slug}/goals` (`CompanyGoalController`, owners/managers, 20/min). Every company
  is public, so goals and shares are too. The panel ("Monthly goals", `components/hub/
  CompanyGoals.vue`) hides for outsiders when there's nothing to show.
- Tests freeze the clock mid-month (`CompanyGoalTest`). E2E (`goals.spec.ts`): Gina Goals owns
  Goal Getters; both members are private so their this-month jobs stay off the public feed.

## Company invite links (#5221)

- `company_invites`: `token_hash` (sha256 of a 40-char letters-and-digits token, unique),
  `expires_at` (null = never), `max_uses` (null = unlimited), `uses`, `revoked_at`,
  `created_by`. The plain token exists only in the flash right after `CompanyInvite::issue()`
  (`Inertia::flash('invite', {id, url})`); `CompanyInvites.vue` keeps it in a ref, because the
  auto-refresh's reload comes without the flash. Lost = make a new link.
- `CompanyInviteController`: store / revoke (owners and managers, edit page section
  `#invites`; the form offers 1 / 7 / never days and 1 / 5 / 25 / unlimited uses, the only
  values validation accepts; at most `MAX_ACTIVE` (20) live links). `GET /invite/{token}`
  is public (not in `Seo::PAGES`, so noindex) and puts itself in `url.intended` for guests,
  so Fortify's login and register come back to it. `POST /invite/{token}` (10/min) joins.
- A live link joins **whatever the recruitment setting** (Closed included). `claim()` takes
  a use with one conditional `UPDATE … SET uses = uses + 1 WHERE usable`, so two drivers
  can't both take a single-use link's last use; if the join then fails (they joined
  elsewhere meanwhile) `release()` gives it back. A member of the company opening its link
  uses nothing. Only live links are listed (`scopeUsable`).
- E2E (`invites.spec.ts`): Ivy Invite owns Invite Only Freight (closed); Ian Invitee joins
  with a single-use link, Gwen Guest signs in from the invite page. Revoke uses `confirm()`:
  `page.once('dialog', d => d.accept())`.

## Application questions (#5222)

- `companies.application_questions` (JSON list, null = none) and
  `company_applications.answers` (JSON snapshot). `App\Support\ApplicationQuestions`: `for()`
  reads the company's list (skips malformed rows), `save()` validates and stores the edit page's
  list (at most `MAX` 10; types `short_text` / `long_text` / `yes_no` / `choice` with 2 to 8
  distinct options; blank options dropped), `rules()` builds the Apply form's `answers.<id>`
  rules (attribute = the question's label), `snapshot()` stores `{question, type, answer}` per
  question so later edits never change old answers.
- Each question has an 8-character `id` that `save()` keeps across edits (the field name is
  `answers[<id>]`); a row without a valid id gets a new one.
- `PUT companies/{slug}/questions` (`CompanyApplicationQuestionController`, owners/managers,
  20/min), edit page section `#questions` (`ApplicationQuestionsEditor.vue`, `useForm`, options
  as one per line). Redirects to the edit page without a fragment (an Inertia redirect with one is
  a 409).
- Who sees answers: owners/managers (`applications.*.answers` on the company page) and the
  applicant (`viewer.pendingAnswers`, "Your application" panel). The Apply form's `questions`
  prop goes only to signed-in non-members of an Applications company. Privacy export includes
  `answers`.
- E2E (`application-questions.spec.ts`): Quinn Questions owns Question Time Haulage, Abby
  Answers applies.

## Company ranks (#5223)

- `company_ranks` (company, `name` ≤ 40, `color` `#rrggbb`, `min_km`), at most
  `CompanyRanks::MAX` (12), no DB unique on `min_km` (a swap of two thresholds would trip it
  mid-save; `save()` refuses duplicates and same names). `company_members.rank_id` = the
  current rank (stored), `pinned_rank_id` = a manual floor: the member gets the higher of the
  pin and what their distance reaches, so driving still promotes past a pin.
- Distance = `Delivery::earned()` (counted + suspended drivers') with the member's
  `user_id` **and** this `company_id`, i.e. jobs synced while in the company.
- `App\Support\CompanyRanks`: `save()` keeps ids of rows sent back with an `id` (pins
  survive a rename), deletes the rest (pins/ranks on them nulled first), then re-ranks
  everyone **silently**. `refreshUser($user, notify)`: `JobSyncController` calls it with
  `notify: true` before `Progress::refresh` (bell `RankPromoted` + `DiscordWebhook::rankPromoted`);
  `Progress::refresh` calls it silently, which covers job delete/hide, staff review and joining.
  `pin()` notifies like a sync. Only a move **up** (higher `min_km`) is announced.
- `PUT companies/{slug}/ranks` (owners/managers), `PUT companies/{slug}/members/{member}/rank`
  (`rank_id` or null; owner pins anyone, a manager pins drivers and themselves). Pages: company
  page `ranks` + member `rank` (`pinned_rank_id` only for managers), profile
  `driver.company_rank` (not `rank`: that's the personal level rank), edit page `#ranks`
  (`CompanyRanksEditor.vue` resets its rows from the saved props in `onSuccess`, or a second
  save would send new rows without their ids). `RankBadge.vue` puts the company's colour only on
  a dot, so any colour stays readable.
- E2E (`company-ranks.spec.ts`): Rhonda Ranks owns Rank and File Haulage, Rob Rookie (private,
  jobs 9-10 days old so public boards don't move) syncs with the seeded token
  `e2e-rob-rookie-sync` (Sanctum finds a token without `id|` by hash). The admin company list
  is newest first and paginated: `admin.spec` searches for Nordic, and new seeded companies
  are backdated.

## Company news (#5224)

- `company_posts` (company, `created_by` nullable on account deletion, `title` ≤ 120, plain-text
  `body` ≤ 5,000, `visibility` `members` | `public`, `pinned_at`), cascades with the company.
  `CompanyPost::scopeVisibleTo($viewer)` = public posts plus the viewer's own company's (a
  suspended member gets public only); `scopeFeedOrder()` = pinned first, then newest.
  `summary($viewer)` shows the author as "Private driver" when `isVisibleTo` says no.
- `CompanyNewsController` (owners/managers of **this** company, any of them can edit, pin or
  delete any of its posts): `companies/{slug}/news/create` + store (10/min), `news/{post}/edit`,
  update, `PUT news/{post}/pin` (`pinned` true unpins the company's others: one pinned post;
  pinning saves without timestamps so it doesn't show as "edited"), delete. Page `news/Edit.vue`.
- Pages: company page `news` (up to `CompanyPost::SHOWN`, "News" panel with "Post news"; the
  panel hides for non-managers when empty), Home `news` only on the My company tab
  (`HomeController::NEWS_SHOWN`). Bodies are printed as text: `CompanyNews.vue` splits out
  `http(s)` links into `<a rel="nofollow ugc …">`, never `v-html`.
- A new post rings every other member's bell (`NoticeType::CompanyNews`, no email, links to
  the company page without a fragment). Editing doesn't ring again. "Also post to Discord"
  (only offered while `discordActive()`) → `DiscordWebhook::newsPosted`.
- Bell opt-outs: `users.bell_opt_outs` lists the `NoticeType::bellOptional()` types switched off
  in Settings → Notifications ("Bell notifications", only company news so far);
  `User::wantsBell()` and `HubNotice::via()` (returns `[]`) honour it. The settings form sends
  `bell` next to `enabled`; a request without `bell` leaves it alone. The toast says
  "Notification settings saved." Privacy export: `news_posts` and `bell_notifications_off`.
- E2E (`news.spec.ts`, 90 s budget): Nell News owns Newsroom Haulage, Ned Reader drives for it.

## Quirks

- The starter kit's `bunny()` font build step was removed (a font download during a Forge
  build can fail the deploy): fonts load with a `<link>` in `app.blade.php`.
- Starter-kit pages reading `auth.user` must handle `null`: the sidebar and public pages
  render for guests.
- Nested Inertia layouts: page `defineOptions({ layout: { breadcrumbs } })` only.
