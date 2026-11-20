<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyEvent;
use App\Models\User;
use App\Support\Seo;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * /sitemap.xml (#5149): the public pages, plus every driver, company and event a guest can
 * see. Jobs are left out (thin pages; they're still linked from profiles). URLs are built on
 * APP_URL, like the canonical links.
 */
class SitemapController extends Controller
{
    /** Keeps the file under the sitemap format's 50,000 URL limit. */
    public const PER_TYPE = 15000;

    public function __invoke(): Response
    {
        /** @var list<array{loc: string, lastmod: Carbon|null}> $urls */
        $urls = [];

        foreach (Seo::SITEMAP_PAGES as $name) {
            $urls[] = ['loc' => Seo::url((string) parse_url(route($name), PHP_URL_PATH)), 'lastmod' => null];
        }

        User::query()->visibleTo(null)
            ->select(['users.id', 'users.username', 'users.updated_at'])
            ->whereNotNull('users.username')
            ->orderBy('users.id')
            ->limit(self::PER_TYPE)
            ->get()
            ->each(function (User $driver) use (&$urls): void {
                $urls[] = ['loc' => Seo::url('drivers/'.rawurlencode((string) $driver->username)), 'lastmod' => $driver->updated_at];
            });

        Company::query()
            ->select(['id', 'slug', 'updated_at'])
            ->orderBy('id')
            ->limit(self::PER_TYPE)
            ->get()
            ->each(function (Company $company) use (&$urls): void {
                $urls[] = ['loc' => Seo::url('companies/'.rawurlencode((string) $company->slug)), 'lastmod' => $company->updated_at];
            });

        CompanyEvent::query()
            ->whereNull('cancelled_at')
            ->select(['id', 'updated_at'])
            ->orderByDesc('id')
            ->limit(self::PER_TYPE)
            ->get()
            ->each(function (CompanyEvent $event) use (&$urls): void {
                $urls[] = ['loc' => Seo::url('events/'.$event->id), 'lastmod' => $event->updated_at];
            });

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $url) {
            $xml .= '  <url><loc>'.htmlspecialchars($url['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc>';
            if ($url['lastmod'] !== null) {
                $xml .= '<lastmod>'.$url['lastmod']->copy()->utc()->format('Y-m-d\TH:i:s\Z').'</lastmod>';
            }
            $xml .= "</url>\n";
        }
        $xml .= "</urlset>\n";

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
