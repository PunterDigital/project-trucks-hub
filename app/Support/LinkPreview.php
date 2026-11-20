<?php

namespace App\Support;

use App\Achievements\Progress;
use App\Enums\DeliveryStatus;
use App\Enums\RsvpStatus;
use App\Models\Company;
use App\Models\CompanyEvent;
use App\Models\Delivery;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Format;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\Typography\FontFactory;

/**
 * Link previews (#4652): Open Graph / Twitter tags and a generated 1200×630 card for driver
 * profiles, jobs, companies and events, so a link pasted into Discord shows what it is.
 *
 * Everything here is built **as a guest** sees it, whoever is viewing: a crawler is a guest,
 * and a mate who can see a private profile must not paste a preview that shows it to others.
 * Items a guest can't see get no preview (the site's default tags only).
 *
 * The card's image URL carries a hash of its content (`?v=`), and the rendered PNG is cached on
 * the public disk under that hash, so a changed card is drawn once and Discord fetches it anew.
 */
class LinkPreview
{
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    public const TYPES = ['driver', 'job', 'company', 'event', 'post'];

    private const DIR = 'og';

    private const BG = '#111827';

    private const PANEL = '#1f2937';

    private const ACCENT = '#f59e0b';

    private const TEXT = '#ffffff';

    private const MUTED = '#9ca3af';

    private const SOFT = '#d1d5db';

    /**
     * The tags for a page, or null when a guest couldn't see the item.
     *
     * @return array{title: string, description: string, url: string, image: string, image_alt: string}|null
     */
    public static function meta(string $type, int $id): ?array
    {
        $card = self::card($type, $id);
        if ($card === null) {
            return null;
        }

        $description = $card['subtitle'];
        $facts = array_map(fn (array $s) => $s[0].': '.$s[1], $card['stats']);
        if ($facts !== []) {
            $description = trim($description.'. '.implode(' · ', $facts), '. ');
        }

        return [
            'title' => $card['title'],
            'description' => $description,
            'url' => $card['url'],
            'image' => route('preview-image', ['type' => $type, 'id' => $id]).'?v='.self::hash($card),
            'image_alt' => $card['title'],
        ];
    }

    /**
     * What a card shows. Null when the item doesn't exist or a guest can't see it.
     *
     * @return array{title: string, subtitle: string, stats: list<array{0: string, 1: string}>, url: string, note: string|null}|null
     */
    public static function card(string $type, int $id): ?array
    {
        return match ($type) {
            'driver' => self::driverCard($id),
            'job' => self::jobCard($id),
            'company' => self::companyCard($id),
            'event' => self::eventCard($id),
            'post' => self::postCard($id),
            default => null,
        };
    }

    /**
     * The card as PNG bytes, drawn once per content hash and kept on the public disk.
     */
    public static function png(string $type, int $id): ?string
    {
        $card = self::card($type, $id);
        if ($card === null) {
            return null;
        }

        $disk = Storage::disk(ImageStore::DISK);
        $prefix = self::DIR.'/'.$type.'/'.$id.'-';
        $path = $prefix.self::hash($card).'.png';

        if ($disk->exists($path)) {
            return (string) $disk->get($path);
        }

        $png = self::render($card);

        // Older versions of this card are never asked for again.
        foreach ($disk->files(self::DIR.'/'.$type) as $old) {
            if (str_starts_with($old, $prefix)) {
                $disk->delete($old);
            }
        }
        $disk->put($path, $png);

        return $png;
    }

    /**
     * @param  array{title: string, subtitle: string, stats: list<array{0: string, 1: string}>, url: string, note: string|null}  $card
     */
    public static function render(array $card): string
    {
        $bold = resource_path('fonts/DejaVuSans-Bold.ttf');
        $regular = resource_path('fonts/DejaVuSans.ttf');

        $image = ImageManager::usingDriver(GdDriver::class)
            ->createImage(self::WIDTH, self::HEIGHT)
            ->fill(self::BG);

        $image->drawRectangle(fn (RectangleFactory $r) => $r->at(0, 0)->size(self::WIDTH, 10)->background(self::ACCENT));

        $text = function (string $value, int $x, int $y, string $font, float $size, string $color, ?int $wrap = null, string $align = 'left') use ($image): void {
            $image->text($value, $x, $y, function (FontFactory $f) use ($font, $size, $color, $wrap, $align): void {
                $f->filename($font)->size($size)->color($color)->align($align, 'top')->lineHeight(1.25);
                if ($wrap !== null) {
                    $f->wrap($wrap);
                }
            });
        };

        $text('PROJECTTRUCKS HUB', 60, 52, $bold, 24, self::ACCENT);
        if ($card['note'] !== null) {
            $text(self::cut(mb_strtoupper($card['note']), 24), self::WIDTH - 60, 52, $bold, 24, self::SOFT, null, 'right');
        }

        // Two lines at most: 58 px DejaVu Bold fits ~28 characters a line.
        $text(self::cut($card['title'], 44), 60, 110, $bold, 58, self::TEXT, self::WIDTH - 120);
        if ($card['stats'] === []) {
            // No stat panels (a blog post): the subtitle gets their space, up to four lines.
            $text(self::cut($card['subtitle'], 200), 60, 290, $regular, 30, self::SOFT, self::WIDTH - 120);
        } else {
            $text(self::cut($card['subtitle'], 64), 60, 290, $regular, 28, self::SOFT);
        }

        foreach (array_slice($card['stats'], 0, 3) as $i => [$label, $value]) {
            $x = 60 + $i * 370;
            $image->drawRectangle(fn (RectangleFactory $r) => $r->at($x, 370)->size(340, 160)->background(self::PANEL));
            $text(mb_strtoupper($label), $x + 28, 398, $bold, 20, self::MUTED);
            $text(self::cut($value, 14), $x + 28, 448, $bold, 36, self::TEXT);
        }

        $host = (string) parse_url($card['url'], PHP_URL_HOST);
        $text($host, 60, 568, $regular, 22, self::MUTED);

        return (string) $image->encodeUsingFormat(Format::PNG);
    }

    /** @return array{title: string, subtitle: string, stats: list<array{0: string, 1: string}>, url: string, note: string|null}|null */
    private static function driverCard(int $id): ?array
    {
        $driver = User::query()->with('membership.company')->find($id);
        if ($driver === null || ! $driver->isVisibleTo(null)) {
            return null;
        }

        $all = Stats::totals($driver->deliveries()->getQuery());
        $week = Stats::totals($driver->deliveries()->getQuery(), Period::Week);
        $level = (int) ($driver->level ?? 1);
        $company = $driver->membership?->company;

        $subtitle = __('Level :level :rank', ['level' => $level, 'rank' => Progress::rank($level)]);
        if ($company !== null) {
            $subtitle .= ' · '.$company->name.' ['.$company->tag.']';
        }

        return [
            'title' => $driver->name,
            'subtitle' => $subtitle,
            'stats' => [
                [__('Jobs'), self::number($all['deliveries'])],
                [__('Distance'), self::km($all['distance_km'])],
                [__('This week'), self::km($week['distance_km'])],
            ],
            'url' => route('drivers.show', $driver),
            'note' => null,
        ];
    }

    /** @return array{title: string, subtitle: string, stats: list<array{0: string, 1: string}>, url: string, note: string|null}|null */
    private static function jobCard(int $id): ?array
    {
        $job = Delivery::query()->visibleTo(null)->with('user')->find($id);
        if ($job === null) {
            return null;
        }

        $cargo = $job->cargo_name ?: __('Cargo');
        if ($job->cargo_mass_kg) {
            $cargo .= ' ('.self::number(round($job->cargo_mass_kg / 1000, 1)).' t)';
        }

        $status = match (true) {
            $job->status === DeliveryStatus::Late => __('Late'),
            $job->status === DeliveryStatus::Cancelled => __('Cancelled'),
            default => __('Delivered'),
        };

        $stats = [[__('Distance'), self::km((float) $job->distance_km)], [__('Status'), $status]];
        if ($job->truckName() !== null) {
            $stats[] = [__('Truck'), (string) $job->truckName()];
        }

        return [
            'title' => ($job->source_city ?: '?').' → '.($job->dest_city ?: '?'),
            'subtitle' => $cargo.' · '.__('driven by :name', ['name' => $job->user->name]),
            'stats' => $stats,
            'url' => route('jobs.show', $job),
            'note' => $job->completed_at->format('j M Y'),
        ];
    }

    /** @return array{title: string, subtitle: string, stats: list<array{0: string, 1: string}>, url: string, note: string|null}|null */
    private static function companyCard(int $id): ?array
    {
        $company = Company::query()->find($id);
        if ($company === null) {
            return null;
        }

        $all = Stats::totals($company->deliveries()->getQuery());
        $members = $company->members()->whereHas('user', fn (Builder $u) => $u->whereNull('suspended_at'))->count();
        $description = trim((string) $company->description);

        return [
            'title' => $company->name.' ['.$company->tag.']',
            'subtitle' => $description !== '' ? (string) preg_replace('/\s+/', ' ', $description) : __('Virtual trucking company'),
            'stats' => [
                [__('Drivers'), self::number($members)],
                [__('Jobs'), self::number($all['deliveries'])],
                [__('Distance'), self::km($all['distance_km'])],
            ],
            'url' => route('companies.show', $company),
            'note' => null,
        ];
    }

    /** @return array{title: string, subtitle: string, stats: list<array{0: string, 1: string}>, url: string, note: string|null}|null */
    private static function eventCard(int $id): ?array
    {
        $event = CompanyEvent::query()->with('company')->find($id);
        if ($event === null) {
            return null;
        }

        $going = $event->rsvps()
            ->where('status', RsvpStatus::Going)
            ->whereHas('user', fn (Builder $u) => $u->whereNull('suspended_at'))
            ->count();

        return [
            'title' => $event->title,
            'subtitle' => $event->company->name.' · '.(CompanyEvent::GAMES[$event->game] ?? $event->game)
                .($event->server ? ' · '.$event->server : ''),
            'stats' => [
                [__('Starts (UTC)'), $event->starts_at->utc()->format('j M H:i')],
                [__('From'), $event->start_city],
                [__('Going'), $event->max_drivers ? $going.' / '.$event->max_drivers : (string) $going],
            ],
            'url' => route('events.show', $event),
            'note' => $event->cancelled_at !== null ? __('Cancelled') : __('Event'),
        ];
    }

    /**
     * A published blog post (#5150). Drafts and future-dated posts have no card.
     *
     * @return array{title: string, subtitle: string, stats: list<array{0: string, 1: string}>, url: string, note: string|null}|null
     */
    private static function postCard(int $id): ?array
    {
        $post = Post::query()->published()->find($id);
        if ($post === null) {
            return null;
        }

        return [
            'title' => $post->title,
            'subtitle' => (string) preg_replace('/\s+/', ' ', trim($post->excerpt)),
            'stats' => [],
            'url' => route('blog.show', $post->slug),
            'note' => __('Blog'),
        ];
    }

    /** @param  array<string, mixed>  $card */
    private static function hash(array $card): string
    {
        // The layout version is part of the hash, so a design change redraws every card.
        return substr(hash('sha256', 'v1|'.json_encode($card)), 0, 16);
    }

    private static function cut(string $value, int $chars): string
    {
        return mb_strlen($value) > $chars ? rtrim(mb_substr($value, 0, $chars - 1)).'…' : $value;
    }

    private static function number(int|float $value): string
    {
        return number_format($value, is_float($value) && floor($value) != $value ? 1 : 0);
    }

    private static function km(float $km): string
    {
        return number_format(round($km)).' km';
    }
}
