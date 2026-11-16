<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Enums\ProviderArea;
use App\Models\Household;
use App\Models\ProviderAccount;
use App\Services\DevicePairingService;
use App\Support\HouseholdContext;
use App\Support\PublicAddressResolver;
use Fahoba\NextcloudPhotos\NextcloudPhotoSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeResolver;
use Tests\TestCase;

/**
 * A Nextcloud folder in the frame: read over WebDAV with an app password,
 * subfolders included, only pictures, the day taken from Nextcloud's metadata.
 */
final class NextcloudPhotosTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = '/remote.php/dav/files/anna/Photos/Familie/';

    private Household $household;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->app->instance(PublicAddressResolver::class, new FakeResolver(['cloud.example.test' => ['93.184.216.34']]));

        $this->household = Household::factory()->create();
        app(HouseholdContext::class)->setId((int) $this->household->id);
        $device = app(DevicePairingService::class)->createCode($this->household, 'Küche');
        $this->token = app(DevicePairingService::class)->redeem($device->pairing_code)['token'];

        Http::fake(function (Request $request) {
            if ($request->header('Authorization') !== ['Basic '.base64_encode('anna:app-pass')]) {
                return Http::response('', 401);
            }

            $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));

            return match (true) {
                $request->method() === 'PROPFIND' && $path === self::ROOT => Http::response($this->listing(self::ROOT, [
                    ['Urlaub/', null, null],
                    ['See 1.jpg', 'image/jpeg', '1752487200'],
                    ['notes.txt', 'text/plain', null],
                ]), 207),
                $request->method() === 'PROPFIND' && $path === self::ROOT.'Urlaub/' => Http::response($this->listing(self::ROOT.'Urlaub/', [
                    ['Strand.png', 'image/png', null],
                ]), 207),
                $request->method() === 'GET' && $path === self::ROOT.'Urlaub/Strand.png' => Http::response($this->picture()),
                default => Http::response('', 404),
            };
        });
    }

    /** @param  list<array{0: string, 1: string|null, 2: string|null}>  $entries */
    private function listing(string $folder, array $entries): string
    {
        $responses = '<d:response><d:href>'.$this->href($folder).'</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>';

        foreach ($entries as [$name, $type, $taken]) {
            $responses .= '<d:response><d:href>'.$this->href($folder.$name).'</d:href><d:propstat><d:prop>'
                .($type === null ? '<d:resourcetype><d:collection/></d:resourcetype>' : '<d:resourcetype/><d:getcontenttype>'.$type.'</d:getcontenttype>')
                .($taken === null ? '' : '<nc:metadata-photos-original_date_time>'.$taken.'</nc:metadata-photos-original_date_time>')
                .'</d:prop></d:propstat></d:response>';
        }

        return '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">'.$responses.'</d:multistatus>';
    }

    private function href(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function picture(): string
    {
        ob_start();
        imagepng(imagecreatetruecolor(40, 30));

        return (string) ob_get_clean();
    }

    private function connect(): void
    {
        ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
            'area' => ProviderArea::Photos,
            'provider' => NextcloudPhotoSource::KEY,
            'account_label' => 'Nextcloud – Photos/Familie',
            'access_token' => null,
            'refresh_token' => null,
            'credentials' => ['url' => 'https://cloud.example.test', 'user' => 'anna', 'password' => 'app-pass', 'folder' => 'Photos/Familie'],
        ]);
    }

    public function test_pictures_of_the_folder_and_its_subfolders_show(): void
    {
        $this->connect();

        $photos = collect($this->withToken($this->token)->getJson('/api/wall/photos')->assertOk()->json('photos'));

        $this->assertCount(2, $photos);
        $this->assertSame('2025-07-14', $photos->firstWhere('taken_on', '!==', null)['taken_on']);

        $beach = $photos->first(fn (array $photo): bool => $photo['taken_on'] === null);

        $this->withToken($this->token)->get($beach['url'])->assertOk()->assertHeader('Content-Type', 'image/webp');
    }

    public function test_the_wizard_checks_password_and_folder(): void
    {
        $source = app(NextcloudPhotoSource::class);
        $fields = ['url' => 'https://cloud.example.test/', 'user' => 'anna', 'password' => 'app-pass', 'folder' => 'Photos/Familie'];

        $this->assertNull($source->verifyCredentials($fields));
        $this->assertSame(__('Nextcloud does not accept this user name and app password.'), $source->verifyCredentials([...$fields, 'password' => 'wrong']));
        $this->assertSame(__('This folder does not exist in this Nextcloud.'), $source->verifyCredentials([...$fields, 'folder' => 'Gibt/es/nicht']));
    }

    /** `..` in the folder stays inside the user's files. */
    public function test_the_folder_never_leads_out_of_the_users_files(): void
    {
        $source = app(NextcloudPhotoSource::class);

        $source->verifyCredentials(['url' => 'https://cloud.example.test', 'user' => 'anna', 'password' => 'app-pass', 'folder' => '../../ben/Photos']);

        Http::assertSent(fn (Request $request): bool => rawurldecode((string) parse_url($request->url(), PHP_URL_PATH)) === '/remote.php/dav/files/anna/ben/Photos/');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '..'));
    }
}
