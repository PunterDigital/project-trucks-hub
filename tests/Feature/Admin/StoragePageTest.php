<?php

namespace Tests\Feature\Admin;

use App\Admin\Housekeeping;
use App\Admin\StorageStats;
use App\Filament\Pages\Storage as StoragePage;
use App\Filament\Widgets\StorageItems;
use App\Filament\Widgets\StorageOverview;
use App\Filament\Widgets\StorageSizeChart;
use App\Models\AdminAuditLog;
use App\Models\DeviceAuthorization;
use App\Models\LiveStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class StoragePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-18 09:30:00', 'UTC'));
        Storage::fake('public');
    }

    private function staff(): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $user->forceFill(['is_staff' => true])->save();

        return $user;
    }

    private function linkRequest(string $code, CarbonImmutable $expires): DeviceAuthorization
    {
        return DeviceAuthorization::query()->create([
            'device_code_hash' => DeviceAuthorization::hashDeviceCode($code),
            'user_code' => strtoupper(substr(md5($code), 0, 8)),
            'client_name' => 'ProjectTrucks',
            'expires_at' => $expires,
        ]);
    }

    public function test_items_list_every_folder_and_the_database(): void
    {
        Storage::disk('public')->put('avatars/a.webp', str_repeat('a', 1000));
        Storage::disk('public')->put('avatars/b.webp', str_repeat('b', 500));
        Storage::disk('public')->put('og/driver/1-abc.png', str_repeat('d', 300));

        $items = collect(StorageStats::items())->keyBy('key');

        $this->assertSame(array_keys(StorageStats::ITEMS), $items->keys()->all());
        $this->assertSame(1500, $items['uploads_avatars']['bytes']);
        $this->assertSame(2, $items['uploads_avatars']['files']);
        $this->assertSame(0, $items['uploads_logos']['files']);
        $this->assertSame(300, $items['uploads_og']['bytes']);
        $this->assertNull($items['database']['files']);
        $this->assertGreaterThan(0, $items['database']['bytes']);
    }

    public function test_a_folder_skips_gitignore_placeholders_and_missing_folders_are_empty(): void
    {
        $dir = sys_get_temp_dir().'/hub-storage-'.uniqid();
        File::ensureDirectoryExists($dir.'/sub');
        file_put_contents($dir.'/.gitignore', "*\n");
        file_put_contents($dir.'/sub/x.bin', str_repeat('x', 42));

        try {
            $this->assertSame(['bytes' => 42, 'files' => 1], StorageStats::directory($dir));
            $this->assertSame(['bytes' => 0, 'files' => 0], StorageStats::directory($dir.'/nope'));
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_low_disk_means_under_ten_percent_or_under_five_gb(): void
    {
        $gb = 1024 * 1024 * 1024;

        $this->assertFalse(StorageStats::isLow(100 * $gb, 50 * $gb));
        $this->assertTrue(StorageStats::isLow(100 * $gb, 9 * $gb));
        $this->assertTrue(StorageStats::isLow(20 * $gb, 4 * $gb));
        $this->assertFalse(StorageStats::isLow(20 * $gb, 6 * $gb));
    }

    public function test_a_snapshot_is_stored_once_a_day_and_feeds_the_series(): void
    {
        Storage::disk('public')->put('avatars/a.webp', str_repeat('a', 2048));

        $this->assertTrue(StorageStats::snapshot());
        $this->assertFalse(StorageStats::snapshot());

        $today = DB::table('storage_snapshots')->where('date', '2026-11-18');
        $this->assertSame(count(StorageStats::ITEMS) + 2, $today->count());
        $this->assertSame(2048, (int) DB::table('storage_snapshots')->where(['date' => '2026-11-18', 'item' => 'uploads_avatars'])->value('bytes'));

        $series = StorageStats::series(30);
        $this->assertSame(['2026-11-18'], array_keys($series));
        $this->assertSame(2048, $series['2026-11-18']['uploads']);
        $this->assertNotNull($series['2026-11-18']['disk_used']);
    }

    public function test_growth_compares_with_the_snapshot_thirty_days_ago(): void
    {
        DB::table('storage_snapshots')->insert([
            'date' => '2026-10-15', 'item' => 'uploads_avatars', 'bytes' => 500, 'files' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Storage::disk('public')->put('avatars/a.webp', str_repeat('a', 1500));

        $items = collect(StorageStats::itemsWithGrowth())->keyBy('key');

        $this->assertSame(1000, $items['uploads_avatars']['growth_30']);
        $this->assertNull($items['logs']['growth_30']);
    }

    public function test_housekeeping_prunes_only_old_link_requests_and_stale_live_statuses(): void
    {
        $old = $this->linkRequest('old', CarbonImmutable::now()->subDays(2));
        $recent = $this->linkRequest('recent', CarbonImmutable::now()->subHours(2));
        [$gone, $offline, $driving] = User::factory()->count(3)->create();
        LiveStatus::query()->create(['user_id' => $gone->id, 'updated_at' => now()->subDays(2)]);
        LiveStatus::query()->create(['user_id' => $offline->id, 'updated_at' => now()->subHours(3)]);
        LiveStatus::query()->create(['user_id' => $driving->id, 'updated_at' => now()]);

        $this->assertSame(1, Housekeeping::pruneLinkRequests());
        $this->assertSame(1, Housekeeping::pruneLiveStatuses());

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        $this->assertSame([$offline->id, $driving->id], LiveStatus::query()->orderBy('user_id')->pluck('user_id')->all());
        $this->assertTrue(DB::table('daily_stats')->where('date', '2026-11-16')->exists(), 'closed days are rolled up before pruning');
    }

    public function test_old_logs_are_deleted_and_recent_ones_kept(): void
    {
        $dir = sys_get_temp_dir().'/hub-logs-'.uniqid();
        File::ensureDirectoryExists($dir);
        file_put_contents($dir.'/laravel-2026-10-01.log', str_repeat('x', 100));
        file_put_contents($dir.'/laravel.log', 'today');
        file_put_contents($dir.'/notes.txt', 'not a log');
        touch($dir.'/laravel-2026-10-01.log', now()->subDays(20)->getTimestamp());
        touch($dir.'/notes.txt', now()->subDays(20)->getTimestamp());
        touch($dir.'/laravel.log', now()->subHour()->getTimestamp());

        try {
            $this->assertSame(['files' => 1, 'bytes' => 100], Housekeeping::clearOldLogs($dir));
            $this->assertFileDoesNotExist($dir.'/laravel-2026-10-01.log');
            $this->assertFileExists($dir.'/laravel.log');
            $this->assertFileExists($dir.'/notes.txt');
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_the_prune_command_runs_every_step(): void
    {
        $this->linkRequest('old', CarbonImmutable::now()->subDays(3));

        $this->artisan('hub:prune')
            ->expectsOutput('Link requests pruned: 1')
            ->expectsOutput('Live statuses pruned: 0')
            ->assertSuccessful();
    }

    public function test_the_storage_page_takes_todays_snapshot(): void
    {
        $this->actingAs($this->staff())->get('/admin/storage')->assertOk()->assertSee('Storage');

        $this->assertTrue(DB::table('storage_snapshots')->where('date', '2026-11-18')->exists());
    }

    public function test_drivers_get_a_404(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/storage')->assertNotFound();
    }

    public function test_staff_see_the_storage_widgets(): void
    {
        $this->actingAs($this->staff());
        Storage::disk('public')->put('logos/l.webp', 'logo');

        Livewire::test(StorageOverview::class)->assertSee('Free space')->assertSee('Uploads')->assertSee('1 files');
        Livewire::test(StorageSizeChart::class)->assertSee('Storage, last 30 days (MB)');
        Livewire::test(StorageItems::class)->assertSee('Company logos')->assertSee('Compiled views')->assertSee('Database');
    }

    public function test_housekeeping_buttons_prune_and_write_the_audit_log(): void
    {
        $staff = $this->staff();
        $this->actingAs($staff);
        $this->linkRequest('old', CarbonImmutable::now()->subDays(2));
        LiveStatus::query()->create(['user_id' => User::factory()->create()->id, 'updated_at' => now()->subDays(2)]);

        Livewire::test(StoragePage::class)
            ->callAction('pruneLinkRequests')
            ->assertNotified('Link requests pruned: 1')
            ->callAction('pruneLiveStatuses')
            ->assertNotified('Live statuses pruned: 1');

        $this->assertSame(0, DeviceAuthorization::query()->count());
        $this->assertSame(0, LiveStatus::query()->count());

        $log = AdminAuditLog::query()->where('action', 'housekeeping.link_requests_pruned')->firstOrFail();
        $this->assertSame($staff->id, $log->staff_id);
        $this->assertSame(['deleted' => 1], $log->after);
        $this->assertTrue(AdminAuditLog::query()->where('action', 'housekeeping.live_statuses_pruned')->exists());
    }
}
