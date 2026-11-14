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

## Quirks

- The starter kit's `bunny()` font build step was removed (a font download during a Forge
  build can fail the deploy): fonts load with a `<link>` in `app.blade.php`.
- Starter-kit pages reading `auth.user` must handle `null`: the sidebar and public pages
  render for guests.
- Nested Inertia layouts: page `defineOptions({ layout: { breadcrumbs } })` only.
