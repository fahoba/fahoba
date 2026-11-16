<?php

declare(strict_types=1);

namespace Fahoba\CloudPhotos;

use Fahoba\PluginApi\Connections\ConnectedAccount;
use Fahoba\PluginApi\Connections\DescribesSetup;
use Fahoba\PluginApi\Connections\TokenSet;
use Fahoba\PluginApi\Connections\UsesOAuth;
use Fahoba\PluginApi\Http\Outbound;
use Fahoba\PluginApi\Photos\Photo;
use Fahoba\PluginApi\Photos\RemotePhotoSource;

/**
 * The app folder of a Dropbox (`Apps/Family Home Base`). The server's app is
 * registered with the access type "App folder", so the consent reaches that
 * folder only. Dropbox reports no day taken without a deprecated option;
 * the pictures count as undated rather than as taken on their upload day.
 */
final class DropboxPhotoSource extends RemotePhotoSource implements DescribesSetup, UsesOAuth
{
    public const KEY = 'fahoba/cloud-photos-dropbox';

    private const API = 'https://api.dropboxapi.com/2';

    private const CONTENT = 'https://content.dropboxapi.com/2';

    private const MAX_FILES = 5000;

    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];

    public function __construct(private readonly Outbound $outbound) {}

    public static function client(): OAuthClient
    {
        return new OAuthClient(
            'dropbox',
            'https://www.dropbox.com/oauth2/authorize',
            'https://api.dropboxapi.com/oauth2/token',
            // Without it Dropbox hands out a token for four hours and no refresh token.
            ['token_access_type' => 'offline'],
        );
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Dropbox';
    }

    public function scopes(): array
    {
        return ['account_info.read', 'files.metadata.read', 'files.content.read'];
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return self::client()->authorizationUrl($state, $redirectUri, $this->scopes());
    }

    public function exchangeCode(string $code, string $redirectUri): TokenSet
    {
        $tokens = self::client()->exchange($code, $redirectUri, []);
        $me = $this->outbound->server()->withToken($tokens['access_token'])
            ->withBody('null', 'application/json')
            ->post(self::API.'/users/get_current_account')
            ->throw()
            ->json();

        return new TokenSet(
            accessToken: $tokens['access_token'],
            refreshToken: $tokens['refresh_token'],
            expiresIn: $tokens['expires_in'],
            accountLabel: (string) ($me['email'] ?? 'Dropbox'),
        );
    }

    public function freshAccessToken(ConnectedAccount $account): string
    {
        return self::client()->freshAccessToken($account, []);
    }

    public function markAuthorizationLost(ConnectedAccount $account): never
    {
        self::client()->lost($account);
    }

    protected function listing(ConnectedAccount $account): array
    {
        $token = $this->freshAccessToken($account);
        $page = $this->outbound->server()->withToken($token)
            ->post(self::API.'/files/list_folder', ['path' => '', 'recursive' => true, 'limit' => 2000])
            ->throw()
            ->json();
        $photos = [];

        while (true) {
            foreach ((array) ($page['entries'] ?? []) as $entry) {
                if (is_array($entry) && ($entry['.tag'] ?? null) === 'file' && is_string($entry['id'] ?? null)
                    && in_array(mb_strtolower(pathinfo((string) ($entry['name'] ?? ''), PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                    $photos[] = new Photo(id: $entry['id']);
                }
            }

            if (($page['has_more'] ?? false) !== true || count($photos) >= self::MAX_FILES) {
                return array_slice($photos, 0, self::MAX_FILES);
            }

            $page = $this->outbound->server()->withToken($token)
                ->post(self::API.'/files/list_folder/continue', ['cursor' => (string) $page['cursor']])
                ->throw()
                ->json();
        }
    }

    protected function download(ConnectedAccount $account, string $id): ?string
    {
        $response = $this->outbound->server()->withToken($this->freshAccessToken($account))
            ->withHeaders(['Dropbox-API-Arg' => (string) json_encode([
                'resource' => ['.tag' => 'path', 'path' => $id],
                'format' => 'jpeg',
                'size' => 'w2048h1536',
            ])])
            ->withBody('', 'application/octet-stream')
            ->post(self::CONTENT.'/files/get_thumbnail_v2');

        return $response->successful() ? $response->body() : null;
    }

    public function icon(): string
    {
        return 'heroicon-o-cloud';
    }

    public function description(): string
    {
        return __('Shows the pictures in the folder “Apps/Family Home Base” of your Dropbox.');
    }

    public function setupNotes(): array
    {
        return [
            __('Family Home Base sees only its own folder in Dropbox, not the rest of your files.'),
            __('After connecting, put pictures into “Apps/Family Home Base”; subfolders count too.'),
            __('New pictures in the folder appear within ten minutes.'),
        ];
    }
}
