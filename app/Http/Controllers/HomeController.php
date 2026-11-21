<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\SteamController;
use App\Models\Company;
use App\Models\CompanyPost;
use App\Models\Delivery;
use App\Models\User;
use App\Support\Feed;
use App\Support\Leaderboard;
use App\Support\Period;
use App\Support\Stats;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class HomeController extends Controller
{
    /** The latest-deliveries tabs a signed-in driver can pick (#4647); guests see Everyone. */
    public const FEEDS = ['everyone', 'following', 'company'];

    /** Company news posts on the My company tab (#5224). */
    public const NEWS_SHOWN = 3;

    public function __invoke(Request $request): Response
    {
        $viewer = $request->user();
        $feed = $this->feed($request, $viewer);

        return Inertia::render('Home', [
            'canRegister' => Features::enabled(Features::registration()),
            'steamEnabled' => SteamController::enabled(),
            'totals' => [
                ...Stats::totals(Delivery::query()),
                'drivers' => User::query()->count(),
                'companies' => Company::query()->count(),
            ],
            'onTheRoad' => Feed::onTheRoad($viewer, 8),
            'feed' => $feed,
            // Tabs only for a signed-in driver; My company only with a company.
            'feeds' => match (true) {
                $viewer === null => [],
                $viewer->companyId() === null => ['everyone', 'following'],
                default => self::FEEDS,
            },
            'latest' => Feed::latestDeliveries($viewer, 12, $this->scope($feed, $viewer)),
            // Company news (#5224) on the My company tab: the pinned post, then the newest.
            'news' => $feed === 'company' && $viewer !== null
                ? CompanyPost::query()
                    ->where('company_id', $viewer->companyId())
                    ->visibleTo($viewer)
                    ->feedOrder()
                    ->with('author.membership', 'company')
                    ->limit(self::NEWS_SHOWN)
                    ->get()
                    ->map(fn (CompanyPost $p) => $p->summary($viewer))
                    ->values()
                    ->all()
                : [],
            'topDrivers' => Leaderboard::drivers(Period::Week, 'distance', 5),
            'downloadUrl' => route('download'),
        ]);
    }

    /**
     * The tab to show: `?feed=` when it is one the viewer can pick (and then remembered for
     * them), else the one they picked last, else Everyone.
     */
    private function feed(Request $request, ?User $viewer): string
    {
        if ($viewer === null) {
            return 'everyone';
        }

        $asked = $request->query('feed');
        $available = fn (?string $f) => in_array($f, self::FEEDS, true)
            && ($f !== 'company' || $viewer->companyId() !== null);

        if (is_string($asked) && $available($asked)) {
            if ($viewer->home_feed !== $asked) {
                $viewer->forceFill(['home_feed' => $asked])->save();
            }

            return $asked;
        }

        return $available($viewer->home_feed) ? (string) $viewer->home_feed : 'everyone';
    }

    /** @return (callable(Builder<Delivery>): mixed)|null */
    private function scope(string $feed, ?User $viewer): ?callable
    {
        if ($viewer === null) {
            return null;
        }

        return match ($feed) {
            // Feed::latestDeliveries already keeps out drivers the viewer can't see, so a
            // follow of someone who went out of sight just stops showing.
            'following' => fn (Builder $q) => $q->whereIn(
                'deliveries.user_id',
                DB::table('follows')->select('followed_id')->where('follower_id', $viewer->id),
            ),
            'company' => fn (Builder $q) => $q->where('deliveries.company_id', $viewer->companyId()),
            default => null,
        };
    }
}
