<?php

namespace Tests\Feature;

use App\Support\LatestRelease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DownloadTest extends TestCase
{
    use RefreshDatabase;

    private const DL = 'https://github.com/PunterDigital/project-trucks/releases/download/v0.7.0/';

    /** @return array<string, mixed> GitHub's /releases/latest, trimmed. */
    private function release(array $overrides = []): array
    {
        return [
            'tag_name' => 'v0.7.0',
            'html_url' => 'https://github.com/PunterDigital/project-trucks/releases/tag/v0.7.0',
            'published_at' => '2026-11-14T13:56:02Z',
            'draft' => false,
            'prerelease' => false,
            'assets' => [
                ['name' => 'ProjectTrucks_0.7.0_x64_en-US.msi', 'size' => 6668288, 'browser_download_url' => self::DL.'ProjectTrucks_0.7.0_x64_en-US.msi', 'digest' => 'sha256:'.str_repeat('b', 64)],
                ['name' => 'ProjectTrucks_0.7.0_x64-setup.exe', 'size' => 4684067, 'browser_download_url' => self::DL.'ProjectTrucks_0.7.0_x64-setup.exe', 'digest' => 'sha256:'.str_repeat('a', 64)],
            ],
            ...$overrides,
        ];
    }

    public function test_the_page_shows_the_latest_release(): void
    {
        Http::fake(['api.github.com/*' => Http::response($this->release())]);

        $this->get(route('download'))->assertOk()->assertInertia(fn ($page) => $page
            ->component('download/Show')
            ->where('release.version', '0.7.0')
            ->where('release.installers.0.kind', 'exe')
            ->where('release.installers.0.name', 'ProjectTrucks_0.7.0_x64-setup.exe')
            ->where('release.installers.0.size', 4684067)
            ->where('release.installers.0.sha256', str_repeat('a', 64))
            ->where('release.installers.1.kind', 'msi'));
    }

    public function test_installer_links_redirect_straight_to_the_files(): void
    {
        Http::fake(['api.github.com/*' => Http::response($this->release())]);

        $this->get(route('download.windows'))->assertRedirect(self::DL.'ProjectTrucks_0.7.0_x64-setup.exe');
        $this->get(route('download.windows', 'msi'))->assertRedirect(self::DL.'ProjectTrucks_0.7.0_x64_en-US.msi');
        $this->get('/download/windows/zip')->assertNotFound();
    }

    public function test_github_is_asked_at_most_once_per_cache_window(): void
    {
        Http::fake(['api.github.com/*' => Http::response($this->release())]);

        $this->get(route('download'));
        $this->get(route('download.windows'));
        $this->get(route('download'));

        Http::assertSentCount(1);
    }

    public function test_a_github_outage_keeps_the_last_good_release(): void
    {
        Http::fake(['api.github.com/*' => Http::sequence()
            ->push($this->release())
            ->push('rate limited', 403)]);

        $this->get(route('download'));
        Cache::forget(LatestRelease::CACHE_KEY);

        $this->get(route('download'))->assertInertia(fn ($page) => $page->where('release.version', '0.7.0'));
        $this->get(route('download.windows'))->assertRedirect(self::DL.'ProjectTrucks_0.7.0_x64-setup.exe');
    }

    public function test_with_nothing_known_the_page_falls_back_to_github(): void
    {
        Http::fake(['api.github.com/*' => Http::response('down', 500)]);

        $this->get(route('download'))->assertInertia(fn ($page) => $page
            ->where('release', null)
            ->where('releasesPage', LatestRelease::RELEASES_PAGE));
        $this->get(route('download.windows'))->assertRedirect(route('download'));
    }

    public function test_only_github_release_downloads_of_project_trucks_are_linked(): void
    {
        Http::fake(['api.github.com/*' => Http::response($this->release(['assets' => [
            ['name' => 'ProjectTrucks_0.7.0_x64-setup.exe', 'size' => 1, 'browser_download_url' => 'https://evil.example.com/ProjectTrucks_0.7.0_x64-setup.exe'],
        ]]))]);

        $this->get(route('download'))->assertInertia(fn ($page) => $page->where('release', null));
    }

    public function test_drafts_and_prereleases_are_ignored(): void
    {
        Http::fake(['api.github.com/*' => Http::response($this->release(['prerelease' => true]))]);

        $this->get(route('download'))->assertInertia(fn ($page) => $page->where('release', null));
    }

    public function test_get_project_trucks_links_point_at_the_download_page(): void
    {
        Http::fake();

        $this->get(route('home'))->assertInertia(fn ($page) => $page->where('downloadUrl', route('download')));
    }
}
