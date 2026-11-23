<?php

namespace Tests\Feature;

use App\Admin\Moderation;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use App\Support\ImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Avatars, company logos and banners (#4644). */
class ImageUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(ImageStore::DISK);
    }

    /** @return array{0: int, 1: int} */
    private function sizeOf(string $path): array
    {
        $info = getimagesizefromstring(Storage::disk(ImageStore::DISK)->get($path));
        $this->assertNotFalse($info);
        $this->assertSame('image/webp', $info['mime']);

        return [$info[0], $info[1]];
    }

    private function companyWith(CompanyRole $role): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $company->members()->create(['user_id' => $user->id, 'role' => $role]);

        return [$company, $user];
    }

    public function test_a_driver_uploads_an_avatar_resized_to_a_256_px_webp(): void
    {
        $user = User::factory()->create(['avatar_url' => 'https://avatars.steamstatic.com/a.jpg']);

        $this->actingAs($user)
            ->post(route('avatar.store'), ['avatar' => UploadedFile::fake()->image('me.png', 900, 600)])
            ->assertRedirect(route('profile.edit'));

        $path = $user->refresh()->avatar_path;
        $this->assertNotNull($path);
        $this->assertMatchesRegularExpression('#^avatars/'.ImageStore::FILE_PATTERN.'$#', $path);
        $this->assertSame([256, 256], $this->sizeOf($path));
        $this->assertSame('/media/'.$path, $user->avatar());
        $this->assertSame('/media/'.$path, $user->publicSummary()['avatar']);
    }

    public function test_nothing_from_the_original_file_is_kept(): void
    {
        $user = User::factory()->create();
        $original = UploadedFile::fake()->image('me.jpg', 300, 300);
        $jpeg = (string) file_get_contents($original->getPathname());
        // A JPEG comment segment right after the start marker: stands in for EXIF/GPS data.
        $secret = 'SECRET-LOCATION-51.5N';
        $comment = "\xFF\xFE".pack('n', strlen($secret) + 2).$secret;
        $file = UploadedFile::fake()->createWithContent('me.jpg', substr($jpeg, 0, 2).$comment.substr($jpeg, 2));

        $this->actingAs($user)->post(route('avatar.store'), ['avatar' => $file])->assertSessionHasNoErrors();

        $stored = Storage::disk(ImageStore::DISK)->get($user->refresh()->avatar_path);
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertStringStartsWith('RIFF', $stored);
    }

    public function test_replacing_an_avatar_deletes_the_old_file(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('avatar.store'), ['avatar' => UploadedFile::fake()->image('a.png', 300, 300)]);
        $first = $user->refresh()->avatar_path;

        $this->actingAs($user)->post(route('avatar.store'), ['avatar' => UploadedFile::fake()->image('b.webp', 300, 300)]);
        $second = $user->refresh()->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk(ImageStore::DISK)->assertMissing($first);
        Storage::disk(ImageStore::DISK)->assertExists($second);
    }

    public function test_removing_an_upload_falls_back_to_steam_then_removes_the_steam_picture(): void
    {
        $user = User::factory()->create(['avatar_url' => 'https://avatars.steamstatic.com/a.jpg']);
        $this->actingAs($user)->post(route('avatar.store'), ['avatar' => UploadedFile::fake()->image('a.png', 300, 300)]);
        $path = $user->refresh()->avatar_path;

        $this->actingAs($user)->delete(route('avatar.destroy'))->assertRedirect(route('profile.edit'));
        Storage::disk(ImageStore::DISK)->assertMissing($path);
        $this->assertSame('https://avatars.steamstatic.com/a.jpg', $user->refresh()->avatar());

        $this->actingAs($user)->delete(route('avatar.destroy'));
        $this->assertNull($user->refresh()->avatar());
    }

    public function test_only_small_png_jpeg_or_webp_images_are_accepted(): void
    {
        $user = User::factory()->create();

        foreach ([
            UploadedFile::fake()->image('big.png', 300, 300)->size(2049),
            UploadedFile::fake()->image('anim.gif', 300, 300),
            UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->createWithContent('fake.png', '<?php echo 1;'),
            // Over 4096 px a side (#5531): GD would decode it to hundreds of MB.
            UploadedFile::fake()->image('huge.png', 4097, 10),
        ] as $file) {
            $this->actingAs($user)->post(route('avatar.store'), ['avatar' => $file])->assertSessionHasErrors('avatar');
        }

        $this->assertNull($user->refresh()->avatar_path);
        $this->assertSame([], Storage::disk(ImageStore::DISK)->allFiles());
    }

    public function test_guests_cannot_upload(): void
    {
        $this->post(route('avatar.store'), ['avatar' => UploadedFile::fake()->image('a.png')])->assertRedirect(route('login'));
    }

    public function test_deleting_the_account_deletes_the_avatar(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('avatar.store'), ['avatar' => UploadedFile::fake()->image('a.png', 300, 300)]);
        $path = $user->refresh()->avatar_path;

        $user->delete();

        Storage::disk(ImageStore::DISK)->assertMissing($path);
    }

    public function test_managers_upload_a_logo_and_a_banner(): void
    {
        [$company, $manager] = $this->companyWith(CompanyRole::Manager);

        $this->actingAs($manager)
            ->post(route('companies.images.store', [$company, 'logo']), ['image' => UploadedFile::fake()->image('logo.png', 500, 300)])
            ->assertRedirect(route('companies.edit', $company));
        $this->actingAs($manager)
            ->post(route('companies.images.store', [$company, 'banner']), ['image' => UploadedFile::fake()->image('banner.jpg', 2000, 1000)])
            ->assertRedirect(route('companies.edit', $company));

        $company->refresh();
        $this->assertSame([256, 256], $this->sizeOf($company->logo_path));
        $this->assertSame([1500, 400], $this->sizeOf($company->banner_path));
        $this->assertStringStartsWith('logos/', $company->logo_path);
        $this->assertStringStartsWith('banners/', $company->banner_path);
        $this->assertSame('/media/'.$company->logo_path, $company->summary()['logo']);

        $this->get(route('companies.show', $company))->assertInertia(fn ($page) => $page
            ->where('company.logo', '/media/'.$company->logo_path)
            ->where('company.banner', '/media/'.$company->banner_path));
        $this->get(route('companies.index'))->assertInertia(fn ($page) => $page
            ->where('companies.data.0.logo', '/media/'.$company->logo_path));
    }

    public function test_drivers_and_outsiders_cannot_change_a_company_picture(): void
    {
        [$company] = $this->companyWith(CompanyRole::Owner);
        $driver = User::factory()->create();
        $company->members()->create(['user_id' => $driver->id, 'role' => CompanyRole::Driver]);
        $outsider = User::factory()->create();

        foreach ([$driver, $outsider] as $user) {
            $this->actingAs($user)
                ->post(route('companies.images.store', [$company, 'logo']), ['image' => UploadedFile::fake()->image('logo.png')])
                ->assertForbidden();
            $this->actingAs($user)->delete(route('companies.images.destroy', [$company, 'logo']))->assertForbidden();
        }

        $this->assertNull($company->refresh()->logo_path);
    }

    public function test_removing_a_logo_deletes_the_file(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);
        $this->actingAs($owner)->post(route('companies.images.store', [$company, 'logo']), ['image' => UploadedFile::fake()->image('a.png', 300, 300)]);
        $path = $company->refresh()->logo_path;

        $this->actingAs($owner)->delete(route('companies.images.destroy', [$company, 'logo']))->assertRedirect();

        $this->assertNull($company->refresh()->logo_path);
        Storage::disk(ImageStore::DISK)->assertMissing($path);
    }

    public function test_deleting_a_company_or_resetting_its_profile_deletes_its_pictures(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);
        $this->actingAs($owner)->post(route('companies.images.store', [$company, 'logo']), ['image' => UploadedFile::fake()->image('a.png', 300, 300)]);
        $this->actingAs($owner)->post(route('companies.images.store', [$company, 'banner']), ['image' => UploadedFile::fake()->image('b.png', 1600, 500)]);
        $company->refresh();
        [$logo, $banner] = [$company->logo_path, $company->banner_path];

        Moderation::resetCompanyProfile($company, 'Offensive logo');

        $company->refresh();
        $this->assertNull($company->logo_path);
        $this->assertNull($company->banner_path);
        Storage::disk(ImageStore::DISK)->assertMissing($logo);
        Storage::disk(ImageStore::DISK)->assertMissing($banner);

        $this->actingAs($owner)->post(route('companies.images.store', [$company, 'logo']), ['image' => UploadedFile::fake()->image('c.png', 300, 300)]);
        $again = $company->refresh()->logo_path;
        $company->delete();
        Storage::disk(ImageStore::DISK)->assertMissing($again);
    }

    public function test_media_serves_uploads_without_a_session_and_caches_them(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('avatar.store'), ['avatar' => UploadedFile::fake()->image('a.png', 300, 300)]);
        $url = $user->refresh()->avatar();
        auth()->logout();

        $response = $this->get($url);
        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/webp');
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_media_refuses_anything_but_stored_pictures(): void
    {
        Storage::disk(ImageStore::DISK)->put('other/secret.txt', 'x');

        $this->get('/media/avatars/'.str_repeat('a', 40).'.webp')->assertNotFound();
        $this->get('/media/other/secret.txt')->assertNotFound();
        $this->get('/media/avatars/..%2F..%2F.env')->assertNotFound();
        $this->get('/media/avatars/short.webp')->assertNotFound();
    }
}
