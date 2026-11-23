<?php

namespace Laravel\Ai\Contracts;

interface TokenStore
{
    /**
     * Get the stored OAuth token for the given provider.
     *
     * @return array{access_token: string, refresh_token?: string|null, expires_at?: int|null}|null
     */
    public function get(string $provider): ?array;

    /**
     * Store the OAuth token for the given provider.
     *
     * @param  array{access_token: string, refresh_token?: string|null, expires_at?: int|null}  $token
     */
    public function put(string $provider, array $token): void;

    /**
     * Remove the stored OAuth token for the given provider.
     */
    public function forget(string $provider): void;
}
