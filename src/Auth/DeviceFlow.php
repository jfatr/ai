<?php

namespace Laravel\Ai\Auth;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Exceptions\AiException;

/**
 * OAuth 2.0 Device Authorization Grant (RFC 8628) and token refresh (RFC 6749).
 */
class DeviceFlow
{
    /**
     * Request a device and user code from the authorization server.
     *
     * @param  array<string, mixed>  $oauth
     * @return array{device_code: string, user_code: string, verification_uri: string, verification_uri_complete?: string, expires_in?: int, interval?: int}
     */
    public function start(array $oauth): array
    {
        $response = Http::asForm()->acceptJson()->post($this->required($oauth, 'device_authorization_url'), array_filter([
            'client_id' => $this->required($oauth, 'client_id'),
            'scope' => $oauth['scope'] ?? null,
            'audience' => $oauth['audience'] ?? null,
        ]));

        if ($response->failed() || blank($response->json('device_code'))) {
            throw new AiException('Unable to start the device login: '.$this->describe($response));
        }

        $device = $response->json();

        // Some authorization servers use "verification_url" instead of the RFC name...
        $device['verification_uri'] ??= $device['verification_url'] ?? null;

        return $device;
    }

    /**
     * Poll the token endpoint until the user approves or the device code expires.
     *
     * @param  array<string, mixed>  $oauth
     * @param  array<string, mixed>  $device
     * @return array{access_token: string, refresh_token: string|null, expires_at: int|null}
     */
    public function poll(array $oauth, array $device): array
    {
        $interval = max(1, (int) ($device['interval'] ?? 5));
        $deadline = time() + (int) ($device['expires_in'] ?? 900);

        while (time() < $deadline) {
            Sleep::for($interval)->seconds();

            $response = Http::asForm()->acceptJson()->post($this->required($oauth, 'token_url'), array_filter([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
                'device_code' => $device['device_code'],
                'client_id' => $this->required($oauth, 'client_id'),
                'client_secret' => $oauth['client_secret'] ?? null,
            ]));

            if ($response->successful() && filled($response->json('access_token'))) {
                return $this->token($response);
            }

            match ($response->json('error')) {
                'authorization_pending' => null,
                'slow_down' => $interval += 5,
                default => throw new AiException('The device login failed: '.$this->describe($response)),
            };
        }

        throw new AiException('The device login expired before it was approved.');
    }

    /**
     * Exchange a refresh token for a new access token.
     *
     * @param  array<string, mixed>  $oauth
     * @return array{access_token: string, refresh_token: string|null, expires_at: int|null}
     */
    public function refresh(array $oauth, string $refreshToken): array
    {
        $response = Http::asForm()->acceptJson()->post($this->required($oauth, 'token_url'), array_filter([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->required($oauth, 'client_id'),
            'client_secret' => $oauth['client_secret'] ?? null,
            'scope' => $oauth['scope'] ?? null,
        ]));

        if ($response->failed() || blank($response->json('access_token'))) {
            throw new AiException('Unable to refresh the OAuth token: '.$this->describe($response));
        }

        // Servers that do not rotate refresh tokens omit it from the response...
        return ['refresh_token' => $response->json('refresh_token') ?? $refreshToken] + $this->token($response);
    }

    /**
     * Normalize a token endpoint response.
     *
     * @return array{access_token: string, refresh_token: string|null, expires_at: int|null}
     */
    protected function token(Response $response): array
    {
        return [
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_at' => is_numeric($response->json('expires_in'))
                ? time() + (int) $response->json('expires_in')
                : null,
        ];
    }

    /**
     * Get a required OAuth configuration value.
     *
     * @param  array<string, mixed>  $oauth
     */
    protected function required(array $oauth, string $key): string
    {
        return filled($oauth[$key] ?? null)
            ? (string) $oauth[$key]
            : throw new AiException("The provider's [oauth.{$key}] configuration value is missing.");
    }

    /**
     * Describe a failed authorization server response without echoing secrets.
     */
    protected function describe(Response $response): string
    {
        return trim(($response->json('error') ?? 'HTTP '.$response->status()).' '.($response->json('error_description') ?? ''));
    }
}
