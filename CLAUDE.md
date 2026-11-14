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
- `/download`: ProjectTrucks' latest GitHub release via `App\Support\LatestRelease` (GitHub API,
  cached 15 min, last good answer kept forever, only `github.com/PunterDigital/project-trucks/
  releases/download/` URLs accepted). `/download/windows[/msi]` are permanent links that 302 to
  the current installer. E2ESeeder puts a release in the cache so E2E never calls GitHub. Every
  "Get ProjectTrucks" link points here, not at the personal-apps downloads page. The install
  steps on the page name the app's real labels ("Install plugin", "Tracking", Settings →
  ProjectTrucks Hub): keep them in step with ProjectTrucks.

## E2E

Playwright (`tests/e2e`), headless Chromium, never takes focus. `serve.mjs` resets a throwaway
SQLite file in the OS temp dir, seeds `E2ESeeder` (add records at the END), runs
`php artisan serve` on 127.0.0.1:8775 (`E2E_PORT`). Accounts: `erin@example.com` (no company),
`olga@example.com` (owner of Nordic Haulage, on the road); password `password`.
`sync.spec.ts` plays the desktop app with Playwright's `request` (device code → approve in
the page → poll → POST jobs / PUT status). Every spec fails on a console error.
Specs share one database and run in file order: assert on named rows, not counts.

## Quirks

- The starter kit's `bunny()` font build step was removed (a font download during a Forge
  build can fail the deploy): fonts load with a `<link>` in `app.blade.php`.
- Starter-kit pages reading `auth.user` must handle `null`: the sidebar and public pages
  render for guests.
- Nested Inertia layouts: page `defineOptions({ layout: { breadcrumbs } })` only.
