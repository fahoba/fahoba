<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Enums\ProviderArea;
use App\Models\Household;
use App\Models\ProviderAccount;
use App\Services\DevicePairingService;
use App\Services\Photos\CorePhotoCache;
use App\Support\HouseholdContext;
use App\Support\PublicAddressResolver;
use Fahoba\Immich\ImmichPhotoSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeResolver;
use Tests\TestCase;

/**
 * An Immich album in the frame: listed by API key, pictures fetched once as
 * Immich's preview, redrawn and kept, served under the plugin's source key.
 */
final class ImmichPhotosTest extends TestCase
{
    use RefreshDatabase;

    private const ASSET = '0b6e2f4e-6a1c-4c8e-9f2a-3f1b2c4d5e6f';

    private Household $household;

    private string $token;

    private int $downloads = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->app->instance(PublicAddressResolver::class, new FakeResolver([
            'photos.example.test' => ['93.184.216.34'],
            'nas.example.test' => ['192.168.1.5'],
        ]));

        $this->household = Household::factory()->create();
        app(HouseholdContext::class)->setId((int) $this->household->id);
        $device = app(DevicePairingService::class)->createCode($this->household, 'Küche');
        $this->token = app(DevicePairingService::class)->redeem($device->pairing_code)['token'];

        Http::fake(function (Request $request) {
            if ($request->header('x-api-key') !== ['secret']) {
                return Http::response(['message' => 'Invalid API key'], 401);
            }

            return match (true) {
                str_ends_with($request->url(), '/api/albums') => Http::response([['id' => 'a1', 'albumName' => 'Familie']]),
                str_ends_with($request->url(), '/api/albums?shared=true') => Http::response([]),
                str_ends_with($request->url(), '/api/albums/a1') => Http::response(['assets' => [
                    ['id' => self::ASSET, 'type' => 'IMAGE', 'localDateTime' => '2025-07-14T10:00:00.000Z'],
                    ['id' => 'video-1', 'type' => 'VIDEO', 'localDateTime' => '2025-07-14T10:00:00.000Z'],
                ]]),
                str_contains($request->url(), '/api/assets/'.self::ASSET.'/thumbnail') => $this->picture(),
                default => Http::response('', 404),
            };
        });
    }

    private function picture(): mixed
    {
        $this->downloads++;
        $canvas = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($canvas);

        return Http::response((string) ob_get_clean(), 200, ['Content-Type' => 'image/jpeg']);
    }

    private function connect(array $credentials = []): ProviderAccount
    {
        return ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
            'area' => ProviderArea::Photos,
            'provider' => ImmichPhotoSource::KEY,
            'account_label' => 'Immich – Familie',
            'access_token' => null,
            'refresh_token' => null,
            'credentials' => [...['url' => 'https://photos.example.test', 'api_key' => 'secret', 'album' => 'familie'], ...$credentials],
        ]);
    }

    public function test_the_album_shows_in_the_frame_and_is_fetched_once(): void
    {
        $account = $this->connect();

        $this->withToken($this->token)
            ->getJson('/api/wall/photos')
            ->assertOk()
            ->assertJsonCount(1, 'photos')
            ->assertJsonPath('photos.0.source', ImmichPhotoSource::KEY)
            ->assertJsonPath('photos.0.taken_on', '2025-07-14')
            ->assertJsonPath('photos.0.url', '/api/wall/photos/fahoba/immich/'.self::ASSET);

        foreach ([1, 2] as $ignored) {
            $this->withToken($this->token)
                ->get('/api/wall/photos/fahoba/immich/'.self::ASSET)
                ->assertOk()
                ->assertHeader('Content-Type', 'image/webp');
        }

        $this->assertSame(1, $this->downloads);
        Storage::disk('local')->assertExists(CorePhotoCache::folder((int) $this->household->id, (int) $account->id).'/'.sha1(self::ASSET).'.webp');
    }

    /** A guessed ID is not fetched with the family's key — only what the album lists. */
    public function test_an_asset_outside_the_album_is_not_served(): void
    {
        $this->connect();

        $this->withToken($this->token)
            ->get('/api/wall/photos/fahoba/immich/somebody-elses')
            ->assertNotFound();

        $this->assertSame(0, $this->downloads);
    }

    public function test_the_wizard_checks_key_album_and_address(): void
    {
        $source = app(ImmichPhotoSource::class);

        $this->assertNull($source->verifyCredentials(['url' => 'https://photos.example.test/', 'api_key' => 'secret', 'album' => 'Familie']));
        $this->assertSame(__('Immich does not accept this API key.'), $source->verifyCredentials(['url' => 'https://photos.example.test', 'api_key' => 'wrong', 'album' => 'Familie']));
        $this->assertSame(__('The album was not found on this Immich server.'), $source->verifyCredentials(['url' => 'https://photos.example.test', 'api_key' => 'secret', 'album' => 'Urlaub']));
        $this->assertSame(__('The server cannot be reached at this address, or the address is private.'), $source->verifyCredentials(['url' => 'https://nas.example.test', 'api_key' => 'secret', 'album' => 'Familie']));
    }

    /** Disconnected, the kept pictures go with the next clean-up — the family's photos stay nowhere. */
    public function test_a_removed_connection_leaves_no_pictures_behind(): void
    {
        $account = $this->connect();
        $this->withToken($this->token)->get('/api/wall/photos/fahoba/immich/'.self::ASSET)->assertOk();
        $folder = CorePhotoCache::folder((int) $this->household->id, (int) $account->id);

        $account->delete();
        Artisan::call('fahoba:purge-photo-cache');

        Storage::disk('local')->assertMissing($folder);
    }
}
