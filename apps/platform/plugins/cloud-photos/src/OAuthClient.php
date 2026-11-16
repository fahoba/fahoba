<?php

declare(strict_types=1);

namespace Fahoba\CloudPhotos;

use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Connections\AuthorizationLost;
use Fahoba\PluginApi\Connections\ConnectedAccount;
use Fahoba\PluginApi\Http\Outbound;

/**
 * The authorization code flow both services share: consent, code for
 * tokens, refresh before expiry. A refused refresh (`invalid_grant`) is the
 * normal end of a connection — revoked, password changed — and marks it lost.
 */
final readonly class OAuthClient
{
    /** @param  array<string, string>  $extra  further parameters of the consent page */
    public function __construct(
        private string $service,
        private string $authorizeUrl,
        private string $tokenUrl,
        private array $extra = [],
    ) {}

    public function isRegistered(): bool
    {
        return $this->config('client_id') !== '' && $this->config('client_secret') !== '';
    }

    /** @param  array<int, string>  $scopes */
    public function authorizationUrl(string $state, string $redirectUri, array $scopes): string
    {
        return $this->authorizeUrl.'?'.http_build_query([
            'client_id' => $this->config('client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
            ...($scopes === [] ? [] : ['scope' => implode(' ', $scopes)]),
            ...$this->extra,
        ]);
    }

    /**
     * @param  array<int, string>  $scopes
     * @return array{access_token: string, refresh_token: string|null, expires_in: int}
     */
    public function exchange(string $code, string $redirectUri, array $scopes): array
    {
        $payload = $this->outbound()->server()->asForm()->post($this->tokenUrl, [
            'code' => $code,
            'grant_type' => 'authorization_code',
            'client_id' => $this->config('client_id'),
            'client_secret' => $this->config('client_secret'),
            'redirect_uri' => $redirectUri,
            ...($scopes === [] ? [] : ['scope' => implode(' ', $scopes)]),
        ])->throw()->json();

        return [
            'access_token' => (string) ($payload['access_token'] ?? ''),
            'refresh_token' => is_string($payload['refresh_token'] ?? null) ? $payload['refresh_token'] : null,
            'expires_in' => (int) ($payload['expires_in'] ?? 3600),
        ];
    }

    /** @param  array<int, string>  $scopes */
    public function freshAccessToken(ConnectedAccount $account, array $scopes): string
    {
        if (! $account->isActive()) {
            throw new AuthorizationLost($account);
        }

        if (! $account->accessTokenHasExpired() && $account->accessToken() !== null) {
            return $account->accessToken();
        }

        if ($account->refreshToken() === null) {
            $this->lost($account);
        }

        $response = $this->outbound()->server()->asForm()->post($this->tokenUrl, [
            'refresh_token' => $account->refreshToken(),
            'grant_type' => 'refresh_token',
            'client_id' => $this->config('client_id'),
            'client_secret' => $this->config('client_secret'),
            ...($scopes === [] ? [] : ['scope' => implode(' ', $scopes)]),
        ]);

        if (in_array($response->status(), [400, 401], true) && $response->json('error') === 'invalid_grant') {
            $this->lost($account);
        }

        $payload = $response->throw()->json();

        $account->storeToken(
            (string) $payload['access_token'],
            CarbonImmutable::now()->addSeconds((int) ($payload['expires_in'] ?? 3600)),
            // Microsoft hands out a new refresh token with every refresh.
            is_string($payload['refresh_token'] ?? null) ? $payload['refresh_token'] : null,
        );

        return (string) $payload['access_token'];
    }

    public function lost(ConnectedAccount $account): never
    {
        $account->markInvalid();

        throw new AuthorizationLost($account);
    }

    private function outbound(): Outbound
    {
        return app(Outbound::class);
    }

    private function config(string $key): string
    {
        return trim((string) config("cloud-photos.{$this->service}.{$key}"));
    }
}
