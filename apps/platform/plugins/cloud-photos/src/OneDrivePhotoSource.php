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
use RuntimeException;

/**
 * The app folder of a OneDrive (`Apps/Family Home Base`), read over
 * Microsoft Graph. The consent covers that one folder and nothing else of
 * the drive: what the family puts there — by hand, or by moving pictures
 * from the camera roll — shows in the frame.
 */
final class OneDrivePhotoSource extends RemotePhotoSource implements DescribesSetup, UsesOAuth
{
    public const KEY = 'fahoba/cloud-photos-onedrive';

    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    /** Folders below the app folder are read this deep, and at most this many pictures. */
    private const DEPTH = 4;

    private const MAX_FILES = 5000;

    public function __construct(private readonly Outbound $outbound) {}

    public static function client(): OAuthClient
    {
        return new OAuthClient(
            'onedrive',
            'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            ['response_mode' => 'query', 'prompt' => 'select_account'],
        );
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'OneDrive';
    }

    public function scopes(): array
    {
        // There is no read-only variant of the app folder; it still reaches nothing outside it.
        return ['offline_access', 'User.Read', 'Files.ReadWrite.AppFolder'];
    }

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return self::client()->authorizationUrl($state, $redirectUri, $this->scopes());
    }

    public function exchangeCode(string $code, string $redirectUri): TokenSet
    {
        $tokens = self::client()->exchange($code, $redirectUri, $this->scopes());
        $me = $this->outbound->server()->withToken($tokens['access_token'])->get(self::GRAPH.'/me')->throw()->json();

        // Asked once, so the folder exists before anyone looks for it.
        $this->outbound->server()->withToken($tokens['access_token'])->get(self::GRAPH.'/me/drive/special/approot');

        return new TokenSet(
            accessToken: $tokens['access_token'],
            refreshToken: $tokens['refresh_token'],
            expiresIn: $tokens['expires_in'],
            accountLabel: (string) ($me['mail'] ?? $me['userPrincipalName'] ?? 'OneDrive'),
        );
    }

    public function freshAccessToken(ConnectedAccount $account): string
    {
        return self::client()->freshAccessToken($account, $this->scopes());
    }

    public function markAuthorizationLost(ConnectedAccount $account): never
    {
        self::client()->lost($account);
    }

    protected function listing(ConnectedAccount $account): array
    {
        $photos = [];
        $this->walk($this->freshAccessToken($account), self::GRAPH.'/me/drive/special/approot/children?$top=200', 0, $photos);

        return $photos;
    }

    protected function download(ConnectedAccount $account, string $id): ?string
    {
        $token = $this->freshAccessToken($account);
        $item = self::GRAPH.'/me/drive/items/'.rawurlencode($id);

        // A thumbnail the size of the frame; the original only where none is made.
        foreach (['/thumbnails/0/c1920x1920/content', '/content'] as $path) {
            $response = $this->outbound->server()->withToken($token)->get($item.$path);

            if ($response->successful()) {
                return $response->body();
            }
        }

        return null;
    }

    public function icon(): string
    {
        return 'heroicon-o-cloud';
    }

    public function description(): string
    {
        return __('Shows the pictures in the folder “Apps/Family Home Base” of your OneDrive.');
    }

    public function setupNotes(): array
    {
        return [
            __('Family Home Base sees only its own folder in OneDrive, not the rest of your files.'),
            __('After connecting, put pictures into “Apps/Family Home Base”, or move them there from the camera roll; subfolders count too.'),
            __('New pictures in the folder appear within ten minutes.'),
        ];
    }

    /** @param  array<int, Photo>  $photos */
    private function walk(string $token, string $url, int $depth, array &$photos): void
    {
        while ($url !== '' && count($photos) < self::MAX_FILES) {
            $response = $this->outbound->server()->withToken($token)->get($url);

            if ($response->status() === 401) {
                throw new RuntimeException('OneDrive refused the token.');
            }

            $page = $response->throw()->json();

            foreach ((array) ($page['value'] ?? []) as $item) {
                if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                    continue;
                }

                if (isset($item['folder']) && $depth < self::DEPTH) {
                    $this->walk($token, self::GRAPH.'/me/drive/items/'.rawurlencode($item['id']).'/children?$top=200', $depth + 1, $photos);

                    continue;
                }

                if (isset($item['image']) || isset($item['photo']) || str_starts_with((string) ($item['file']['mimeType'] ?? ''), 'image/')) {
                    $taken = $item['photo']['takenDateTime'] ?? null;
                    $photos[] = new Photo(
                        id: $item['id'],
                        takenOn: is_string($taken) && preg_match('/^\d{4}-\d{2}-\d{2}/', $taken) === 1 ? substr($taken, 0, 10) : null,
                    );
                }
            }

            $next = $page['@odata.nextLink'] ?? '';
            $url = is_string($next) && str_starts_with($next, self::GRAPH.'/') ? $next : '';
        }
    }
}
