# ProjectTrucks Hub

The online side of [ProjectTrucks](https://github.com/PunterDigital/project-trucks), the
Euro Truck Simulator 2 delivery tracker. Link the app to an account and your jobs sync here
automatically:

- **Driver profiles**: stats by week, month and all time, trucks, every synced job.
- **Live feed**: the latest deliveries and who is on the road right now.
- **Companies** (virtual trucking companies): create one, join or apply, owner / manager /
  driver roles, combined stats.
- **Leaderboards**: drivers and companies by distance, deliveries or income.
- **Privacy**: nothing syncs until you link; hide single jobs or make your profile private.

Sign in with email and password or with Steam. ProjectTrucks links with a short code you
approve on the website (no password in the app).

Download ProjectTrucks: https://project-trucks.shaypunter.co.uk/download

## Development

Laravel 13, Inertia 3 + Vue 3, Tailwind 4, Fortify, Sanctum, Socialite (Steam). PHP 8.4,
Node 24.

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate
composer run dev
```

| What            | Command                                              |
| --------------- | ---------------------------------------------------- |
| Tests           | `php artisan test`                                   |
| Style           | `vendor/bin/pint` / `npm run check:fix`              |
| Static analysis | `vendor/bin/phpstan analyse` / `npm run types:check` |
| E2E             | `npm run build && npm run test:e2e`                  |

Sign in through Steam uses Steam's OpenID provider and needs no key. An optional Steam Web API
key in `STEAM_API_KEY` (https://steamcommunity.com/dev/apikey) fills in players' Steam names and
avatars. `STEAM_LOGIN=false` turns Steam sign-in off.

The API ProjectTrucks talks to is documented in [docs/api.md](docs/api.md).

## Licence

MIT
