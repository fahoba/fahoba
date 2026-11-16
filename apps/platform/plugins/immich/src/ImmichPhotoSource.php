<?php

declare(strict_types=1);

namespace Fahoba\Immich;

use Fahoba\PluginApi\Connections\ConnectedAccount;
use Fahoba\PluginApi\Connections\CredentialField;
use Fahoba\PluginApi\Connections\DescribesSetup;
use Fahoba\PluginApi\Connections\UsesCredentials;
use Fahoba\PluginApi\Http\Outbound;
use Fahoba\PluginApi\Photos\Photo;
use Fahoba\PluginApi\Photos\RemotePhotoSource;
use Illuminate\Http\Client\Response;

/**
 * An album of the family's own Immich server.
 *
 * Read with an API key (Immich: Account settings → API keys, permissions
 * `album.read` and `asset.view` suffice). The frame gets Immich's preview
 * size, not the original — it is big enough for a tablet and a fraction of
 * the bytes. New pictures in the album show after the listing's ten minutes.
 *
 * The address is the household's, so it goes through the core's checked
 * outbound requests: an Immich only reachable at home cannot be read by a
 * server elsewhere anyway, and a private address is refused.
 */
final class ImmichPhotoSource extends RemotePhotoSource implements DescribesSetup, UsesCredentials
{
    public const KEY = 'fahoba/immich';

    public function __construct(private readonly Outbound $outbound) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Immich';
    }

    public function isConfigured(?ConnectedAccount $account): bool
    {
        return parent::isConfigured($account)
            && self::base($account?->credentials()['url'] ?? null) !== null
            && trim((string) ($account?->credentials()['api_key'] ?? '')) !== '';
    }

    protected function listing(ConnectedAccount $account): array
    {
        $credentials = $account->credentials();
        $album = $this->album($credentials) ?? throw new ImmichUnavailable(__('The album was not found on this Immich server.'));
        $assets = $this->json($credentials, '/albums/'.rawurlencode($album))['assets'] ?? [];
        $photos = [];

        foreach (is_array($assets) ? $assets : [] as $asset) {
            if (! is_array($asset) || ($asset['type'] ?? null) !== 'IMAGE' || ! is_string($asset['id'] ?? null) || ($asset['isTrashed'] ?? false) === true) {
                continue;
            }

            $taken = $asset['localDateTime'] ?? $asset['fileCreatedAt'] ?? null;

            $photos[] = new Photo(
                id: $asset['id'],
                takenOn: is_string($taken) && preg_match('/^\d{4}-\d{2}-\d{2}/', $taken) === 1 ? substr($taken, 0, 10) : null,
            );
        }

        return $photos;
    }

    protected function download(ConnectedAccount $account, string $id): ?string
    {
        $response = $this->request($account->credentials(), '/assets/'.rawurlencode($id).'/thumbnail?size=preview', 30);

        return $response?->successful() ? $response->body() : null;
    }

    public function icon(): string
    {
        return 'heroicon-o-photo';
    }

    public function description(): string
    {
        return __('Shows an album of your own Immich server in the photo frame.');
    }

    public function setupNotes(): array
    {
        return [
            __('Create an API key in Immich under Account settings → API keys; reading albums and assets is enough.'),
            __('The server must be reachable from the internet at this address — an address only valid at home is refused.'),
            __('New pictures in the album appear within ten minutes.'),
        ];
    }

    public function credentialFields(): array
    {
        return [
            new CredentialField(name: 'url', label: __('Address'), placeholder: 'https://photos.example.org'),
            new CredentialField(name: 'api_key', label: __('API key'), secret: true),
            new CredentialField(name: 'album', label: __('Album'), placeholder: __('Family'), hint: __('The album’s name as it is called in Immich.')),
        ];
    }

    public function verifyCredentials(array $credentials): ?string
    {
        if (self::base($credentials['url'] ?? null) === null) {
            return __('The address starts with https:// or http://.');
        }

        $response = $this->request($credentials, '/albums');

        if ($response === null) {
            return __('The server cannot be reached at this address, or the address is private.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return __('Immich does not accept this API key.');
        }

        if (! $response->successful()) {
            return __('There is no Immich server at this address.');
        }

        return $this->album($credentials) === null ? __('The album was not found on this Immich server.') : null;
    }

    public function accountLabel(array $credentials): string
    {
        return 'Immich – '.trim((string) ($credentials['album'] ?? ''));
    }

    /**
     * The album's ID by its name, among own and shared albums.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function album(array $credentials): ?string
    {
        $wanted = mb_strtolower(trim((string) ($credentials['album'] ?? '')));

        foreach (['/albums', '/albums?shared=true'] as $path) {
            foreach ($this->json($credentials, $path) as $album) {
                if (is_array($album) && is_string($album['id'] ?? null) && mb_strtolower(trim((string) ($album['albumName'] ?? ''))) === $wanted) {
                    return $album['id'];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<int|string, mixed>
     */
    private function json(array $credentials, string $path): array
    {
        $response = $this->request($credentials, $path);

        if ($response === null || ! $response->successful()) {
            throw new ImmichUnavailable(__('There is no Immich server at this address.'));
        }

        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /** @param  array<string, mixed>  $credentials */
    private function request(array $credentials, string $path, int $timeout = 15): ?Response
    {
        $base = self::base($credentials['url'] ?? null);

        if ($base === null) {
            return null;
        }

        return $this->outbound->householdSend('GET', $base.'/api'.$path, [
            'headers' => ['x-api-key' => trim((string) ($credentials['api_key'] ?? '')), 'Accept' => 'application/json'],
        ], $timeout);
    }

    /** The server's address without a trailing slash or `/api`. */
    private static function base(mixed $url): ?string
    {
        $url = rtrim(trim((string) $url), '/');

        if (! preg_match('#^https?://[^/\s]+#i', $url)) {
            return null;
        }

        return preg_replace('#/api$#', '', $url);
    }
}
