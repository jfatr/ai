<?php

namespace Laravel\Ai\Auth;

use Illuminate\Contracts\Cache\Factory as Cache;
use Illuminate\Contracts\Cache\LockProvider;
use Laravel\Ai\Contracts\TokenStore;

class OAuthCredentials
{
    /**
     * The number of seconds before expiry at which a token is refreshed.
     */
    protected const LEEWAY = 60;

    public function __construct(
        protected TokenStore $tokens,
        protected DeviceFlow $flow,
        protected Cache $cache) {}

    /**
     * Get a usable access token for the given provider, refreshing it when it is about to expire.
     *
     * @param  array<string, mixed>  $oauth
     */
    public function accessToken(string $provider, array $oauth): ?string
    {
        $token = $this->tokens->get($provider);

        if ($token === null) {
            return null;
        }

        if (! $this->expiring($token) || blank($token['refresh_token'] ?? null)) {
            return $token['access_token'];
        }

        // Refresh tokens may rotate, so only one process refreshes at a time...
        return $this->locked($provider, function () use ($provider, $oauth): string {
            $token = $this->tokens->get($provider);

            if ($token !== null && ! $this->expiring($token)) {
                return $token['access_token'];
            }

            $this->tokens->put($provider, $token = $this->flow->refresh($oauth, $token['refresh_token']));

            return $token['access_token'];
        });
    }

    /**
     * Determine if the given token has expired or is about to.
     *
     * @param  array<string, mixed>  $token
     */
    protected function expiring(array $token): bool
    {
        return isset($token['expires_at']) && time() >= $token['expires_at'] - self::LEEWAY;
    }

    /**
     * Run the callback while holding the provider's refresh lock, when the cache supports locks.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    protected function locked(string $provider, callable $callback): mixed
    {
        $store = $this->cache->store()->getStore();

        return $store instanceof LockProvider
            ? $store->lock('ai:oauth:'.sha1($provider), 30)->block(15, $callback)
            : $callback();
    }
}
