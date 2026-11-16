<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Enums\ConnectionStatus;
use App\Enums\ProviderArea;
use App\Integrations\ProviderRegistry;
use App\Models\Household;
use App\Models\ProviderAccount;
use App\Services\DevicePairingService;
use App\Support\HouseholdContext;
use Fahoba\CloudPhotos\DropboxPhotoSource;
use Fahoba\CloudPhotos\OneDrivePhotoSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * OneDrive and Dropbox: the app folder through the household's own consent,
 * offered only once the operator registered the server with the service.
 */
final class CloudPhotosTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = [
        'CLOUD_PHOTOS_ONEDRIVE_CLIENT_ID' => 'onedrive-client',
        'CLOUD_PHOTOS_ONEDRIVE_CLIENT_SECRET' => 'onedrive-secret',
        'CLOUD_PHOTOS_DROPBOX_APP_KEY' => 'dropbox-key',
        'CLOUD_PHOTOS_DROPBOX_APP_SECRET' => 'dropbox-secret',
    ];

    /** @var array<string, array{0: string|false, 1: mixed, 2: mixed}> */
    private array $previous = [];

    private Household $household;

    private string $token;

    protected function setUp(): void
    {
        // Read when the plugin registers, so before the application boots.
        if ($this->name() !== 'test_without_the_operators_keys_neither_is_offered') {
            foreach (self::KEYS as $name => $value) {
                $this->previous[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }

        parent::setUp();

        Storage::fake('local');
        $this->household = Household::factory()->create();
        app(HouseholdContext::class)->setId((int) $this->household->id);
        $device = app(DevicePairingService::class)->createCode($this->household, 'Küche');
        $this->token = app(DevicePairingService::class)->redeem($device->pairing_code)['token'];
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $name => [$process, $env, $server]) {
            $process === false ? putenv($name) : putenv("{$name}={$process}");

            if ($env === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }

            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
        }

        parent::tearDown();
    }

    private function picture(): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor(40, 30));

        return (string) ob_get_clean();
    }

    private function connect(string $provider, array $attributes = []): ProviderAccount
    {
        return ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
            'area' => ProviderArea::Photos,
            'provider' => $provider,
            'account_label' => 'anna@example.test',
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'credentials' => null,
            ...$attributes,
        ]);
    }

    public function test_without_the_operators_keys_neither_is_offered(): void
    {
        $offered = array_keys(app(ProviderRegistry::class)->connectableProviders(ProviderArea::Photos));

        $this->assertNotContains(OneDrivePhotoSource::KEY, $offered);
        $this->assertNotContains(DropboxPhotoSource::KEY, $offered);
    }

    public function test_the_consent_asks_only_for_the_app_folder(): void
    {
        $offered = app(ProviderRegistry::class)->connectableProviders(ProviderArea::Photos);
        $callback = route('connection.callback', ['area' => 'photos', 'provider' => DropboxPhotoSource::KEY]);

        $this->assertStringEndsWith('/auth/photos/fahoba/cloud-photos-dropbox/callback', $callback);
        $this->assertContains('Files.ReadWrite.AppFolder', $offered[OneDrivePhotoSource::KEY]->scopes());
        $this->assertNotContains('Files.Read', $offered[OneDrivePhotoSource::KEY]->scopes());
        $this->assertStringContainsString('token_access_type=offline', $offered[DropboxPhotoSource::KEY]->authorizationUrl('state', $callback));
    }

    public function test_onedrive_shows_the_app_folder_with_subfolders_and_dates(): void
    {
        Http::fake([
            'graph.microsoft.com/v1.0/me/drive/special/approot/children*' => Http::response([
                'value' => [
                    ['id' => 'F!1', 'name' => 'Urlaub', 'folder' => ['childCount' => 1]],
                    ['id' => 'A!2', 'name' => 'See.jpg', 'file' => ['mimeType' => 'image/jpeg'], 'photo' => ['takenDateTime' => '2025-07-14T09:00:00Z']],
                    ['id' => 'T!3', 'name' => 'notes.txt', 'file' => ['mimeType' => 'text/plain']],
                ],
            ]),
            'graph.microsoft.com/v1.0/me/drive/items/F%211/children*' => Http::response([
                'value' => [['id' => 'B!4', 'name' => 'Strand.jpg', 'file' => ['mimeType' => 'image/jpeg'], 'image' => []]],
            ]),
            'graph.microsoft.com/v1.0/me/drive/items/A%212/thumbnails/0/c1920x1920/content' => Http::response($this->picture()),
        ]);
        $this->connect(OneDrivePhotoSource::KEY);

        $photos = collect($this->withToken($this->token)->getJson('/api/wall/photos')->assertOk()->json('photos'));

        $this->assertEqualsCanonicalizing(['A!2', 'B!4'], $photos->pluck('id')->all());
        $this->assertSame('2025-07-14', $photos->firstWhere('id', 'A!2')['taken_on']);

        $this->withToken($this->token)
            ->get('/api/wall/photos/fahoba/cloud-photos-onedrive/A!2')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');
    }

    public function test_an_expired_token_is_refreshed_and_a_revoked_one_ends_the_connection(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::sequence()
                ->push(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600])
                ->push(['error' => 'invalid_grant'], 400),
            'graph.microsoft.com/*' => Http::response(['value' => []]),
        ]);
        $account = $this->connect(OneDrivePhotoSource::KEY, ['token_expires_at' => now()->subMinute()]);
        $source = app(OneDrivePhotoSource::class);

        $this->assertSame('new-access', $source->freshAccessToken($account));
        $this->assertSame('new-refresh', $account->fresh()->refresh_token);

        $account->forceFill(['token_expires_at' => now()->subMinute()])->save();
        $this->assertSame([], $source->all($account->fresh()));
        $this->assertSame(ConnectionStatus::InvalidGrant, $account->fresh()->status);
    }

    public function test_dropbox_pages_through_the_app_folder_and_serves_a_thumbnail(): void
    {
        Http::fake(function (Request $request) {
            return match ($request->url()) {
                'https://api.dropboxapi.com/2/files/list_folder' => Http::response([
                    'entries' => [
                        ['.tag' => 'folder', 'id' => 'id:f', 'name' => 'Urlaub'],
                        ['.tag' => 'file', 'id' => 'id:a', 'name' => 'See.JPG'],
                    ],
                    'has_more' => true,
                    'cursor' => 'next',
                ]),
                'https://api.dropboxapi.com/2/files/list_folder/continue' => Http::response([
                    'entries' => [['.tag' => 'file', 'id' => 'id:b', 'name' => 'Liste.pdf']],
                    'has_more' => false,
                ]),
                'https://content.dropboxapi.com/2/files/get_thumbnail_v2' => json_decode($request->header('Dropbox-API-Arg')[0], true)['resource']['path'] === 'id:a'
                    ? Http::response($this->picture())
                    : Http::response('', 409),
                default => Http::response('', 404),
            };
        });
        $this->connect(DropboxPhotoSource::KEY);

        $this->withToken($this->token)
            ->getJson('/api/wall/photos')
            ->assertOk()
            ->assertJsonCount(1, 'photos')
            ->assertJsonPath('photos.0.url', '/api/wall/photos/fahoba/cloud-photos-dropbox/id:a');

        $this->withToken($this->token)
            ->get('/api/wall/photos/fahoba/cloud-photos-dropbox/id:a')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');
    }
}
