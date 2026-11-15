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
  rules in `CompanyMembershipController`.
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
      can't show it again), Flag as cheated (`hub_flag_reason` = the reason after
      `Moderation::STAFF_FLAG_PREFIX`; the job page says staff flagged it), Clear Hub flag. Both
      flag actions set `staff_reviewed`, and `JobSyncController` skips `JobChecks` on them.
    - Companies: Reset name and description (name/tag/slug → "Company 12"/"C12"/"company-12",
      description, logo and banner removed), Remove member (never the owner).
- E2E (`moderation.spec.ts`): rex-report reports troll-tom; mo-mod (staff, its own TOTP
  secret) resolves it and suspends/lifts susie-suspend. Fortify refuses a reused TOTP code, so
  `logInWithTotp` (`tests/e2e/totp.ts`) waits for the next 30 s window before a second
  sign-in with the same secret. Filament's form modal labels end in "*": find fields with
  `getByRole('textbox', { name: /^Reason/ })`, `getByLabel(/^Reason/)` never matched.

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

## Quirks

- The starter kit's `bunny()` font build step was removed (a font download during a Forge
  build can fail the deploy): fonts load with a `<link>` in `app.blade.php`.
- Starter-kit pages reading `auth.user` must handle `null`: the sidebar and public pages
  render for guests.
- Nested Inertia layouts: page `defineOptions({ layout: { breadcrumbs } })` only.
