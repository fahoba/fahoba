<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Enums\ProviderArea;
use App\Models\Household;
use App\Models\ProviderAccount;
use App\Services\DevicePairingService;
use App\Support\HouseholdContext;
use App\Support\PublicAddressResolver;
use Fahoba\SharedAlbums\ICloudAlbumSource;
use Fahoba\SharedAlbums\SynologyAlbumSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeResolver;
use Tests\TestCase;

/**
 * Albums shared by a link: an iCloud shared album and a Synology Photos
 * share, read the way their web pages read them, without an account.
 */
final class SharedAlbumsTest extends TestCase
{
    use RefreshDatabase;

    private const ICLOUD = 'https://www.icloud.com/sharedalbum/de-de/#B0aGWZuqDGsAbcd';

    private const SYNOLOGY = 'https://nas.example.test/mo/sharing/AbCdEfGh';

    private Household $household;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->app->instance(PublicAddressResolver::class, new FakeResolver(['nas.example.test' => ['93.184.216.34']]));

        $this->household = Household::factory()->create();
        app(HouseholdContext::class)->setId((int) $this->household->id);
        $device = app(DevicePairingService::class)->createCode($this->household, 'Küche');
        $this->token = app(DevicePairingService::class)->redeem($device->pairing_code)['token'];
    }

    private function picture(): string
    {
        ob_start();
        imagepng(imagecreatetruecolor(40, 30));

        return (string) ob_get_clean();
    }

    private function connect(string $provider, string $url): void
    {
        ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
            'area' => ProviderArea::Photos,
            'provider' => $provider,
            'account_label' => 'Oma & Opa',
            'access_token' => null,
            'refresh_token' => null,
            'credentials' => ['url' => $url, 'name' => 'Oma & Opa'],
        ]);
    }

    private function fakeICloud(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                // Apple moves the album to another partition first.
                str_starts_with($url, 'https://p36-sharedstreams.icloud.com/') => Http::response(['X-Apple-MMe-Host' => 'p71-sharedstreams.icloud.com'], 330),
                $url === 'https://p71-sharedstreams.icloud.com/B0aGWZuqDGsAbcd/sharedstreams/webstream' => Http::response(['photos' => [
                    ['photoGuid' => 'guid-1', 'caption' => ' Am See ', 'dateCreated' => '2025-07-14T10:00:00Z', 'derivatives' => [
                        '342' => ['checksum' => 'small', 'width' => '342'],
                        '2048' => ['checksum' => 'large', 'width' => '2048'],
                        '4032' => ['checksum' => 'original', 'width' => '4032'],
                    ]],
                    ['photoGuid' => 'guid-2', 'mediaAssetType' => 'video', 'derivatives' => []],
                ]]),
                $url === 'https://p71-sharedstreams.icloud.com/B0aGWZuqDGsAbcd/sharedstreams/webasseturls' => Http::response(['items' => [
                    'large' => ['url_location' => 'cvws.icloud-content.com', 'url_path' => '/large.jpg'],
                    'original' => ['url_location' => 'evil.example.org', 'url_path' => '/x.jpg'],
                ]]),
                $url === 'https://cvws.icloud-content.com/large.jpg' => Http::response($this->picture()),
                default => Http::response('', 404),
            };
        });
    }

    private function fakeSynology(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET' && $path === '/mo/sharing/AbCdEfGh') {
                return Http::response('<html></html>', 200, ['Set-Cookie' => 'sharing_sid=sid-1; path=/; HttpOnly']);
            }

            if ($path !== '/mo/sharing/webapi/entry.cgi' || $request->header('Cookie') !== ['sharing_sid=sid-1']
                || $request->header('X-SYNO-SHARING') !== ['AbCdEfGh'] || ($request['passphrase'] ?? null) !== 'AbCdEfGh') {
                return Http::response(['success' => false], 200);
            }

            return match ($request['api'] ?? null) {
                'SYNO.Foto.Browse.Item' => Http::response(['success' => true, 'data' => ['list' => [
                    ['id' => 1, 'type' => 'photo', 'time' => 1752487200, 'additional' => ['thumbnail' => ['unit_id' => 11, 'cache_key' => '11_1752487200']]],
                    ['id' => 2, 'type' => 'video', 'time' => 1752487200, 'additional' => ['thumbnail' => ['unit_id' => 12, 'cache_key' => '12_1']]],
                ]]]),
                'SYNO.Foto.Thumbnail' => ($request['id'] ?? null) === '11' && ($request['cache_key'] ?? null) === '11_1752487200'
                    ? Http::response($this->picture(), 200, ['Content-Type' => 'image/jpeg'])
                    : Http::response(['success' => false], 200),
                default => Http::response(['success' => false], 200),
            };
        });
    }

    public function test_an_icloud_album_shows_its_pictures_without_videos(): void
    {
        $this->fakeICloud();
        $this->connect(ICloudAlbumSource::KEY, self::ICLOUD);

        $photos = collect($this->withToken($this->token)->getJson('/api/wall/photos')->assertOk()->json('photos'));

        $this->assertCount(1, $photos);
        $this->assertSame('2025-07-14', $photos->first()['taken_on']);
        $this->withToken($this->token)->get($photos->first()['url'])->assertOk()->assertHeader('Content-Type', 'image/webp');

        // The frame's size, never the original — and only from Apple's content hosts.
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://cvws.icloud-content.com/large.jpg');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'evil.example.org'));
    }

    public function test_the_wizard_checks_the_icloud_link(): void
    {
        $this->fakeICloud();
        $source = app(ICloudAlbumSource::class);

        $this->assertNull($source->verifyCredentials(['url' => self::ICLOUD]));
        $this->assertSame(__('This is not a link to a shared iCloud album.'), $source->verifyCredentials(['url' => 'https://www.icloud.com/photos/']));
        $this->assertSame(__('The album could not be read. Is “Public Website” switched on?'), $source->verifyCredentials(['url' => 'https://www.icloud.com/sharedalbum/#A5Unknown123']));
        $this->assertSame('Oma & Opa', $source->accountLabel(['name' => ' Oma & Opa ']));
    }

    public function test_a_synology_share_shows_its_pictures_without_videos(): void
    {
        $this->fakeSynology();
        $this->connect(SynologyAlbumSource::KEY, self::SYNOLOGY);

        $photos = collect($this->withToken($this->token)->getJson('/api/wall/photos')->assertOk()->json('photos'));

        $this->assertCount(1, $photos);
        $this->assertSame('2025-07-14', $photos->first()['taken_on']);
        $this->withToken($this->token)->get($photos->first()['url'])->assertOk()->assertHeader('Content-Type', 'image/webp');
    }

    public function test_the_wizard_checks_the_synology_link(): void
    {
        $this->fakeSynology();
        $source = app(SynologyAlbumSource::class);

        $this->assertNull($source->verifyCredentials(['url' => self::SYNOLOGY]));
        $this->assertSame(__('This is not a sharing link of Synology Photos.'), $source->verifyCredentials(['url' => 'https://nas.example.test/photo/']));
        $this->assertSame(__('The album could not be read. Is the link public and without a password?'), $source->verifyCredentials(['url' => 'https://nas.example.test/mo/sharing/Wrong123']));
    }

    /** A NAS address only valid at home is refused, like every household address. */
    public function test_a_synology_share_at_home_is_refused(): void
    {
        $this->app->instance(PublicAddressResolver::class, new FakeResolver(['nas.home.test' => ['192.168.1.10']]));
        $this->fakeSynology();

        $this->assertSame(
            __('The album could not be read. Is the link public and without a password?'),
            app(SynologyAlbumSource::class)->verifyCredentials(['url' => 'https://nas.home.test/mo/sharing/AbCdEfGh']),
        );
        Http::assertNothingSent();
    }
}
