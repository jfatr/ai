<?php

namespace Laravel\Ai\Auth;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Laravel\Ai\Contracts\TokenStore;
use Throwable;

class FileTokenStore implements TokenStore
{
    public function __construct(
        protected Filesystem $files,
        protected Encrypter $encrypter,
        protected string $directory) {}

    /**
     * {@inheritdoc}
     */
    public function get(string $provider): ?array
    {
        $path = $this->path($provider);

        if (! $this->files->exists($path)) {
            return null;
        }

        try {
            $token = $this->encrypter->decrypt($this->files->get($path));
        } catch (Throwable) {
            return null;
        }

        return is_array($token) && filled($token['access_token'] ?? null) ? $token : null;
    }

    /**
     * {@inheritdoc}
     */
    public function put(string $provider, array $token): void
    {
        $this->files->ensureDirectoryExists($this->directory, 0700);

        $this->files->put($path = $this->path($provider), $this->encrypter->encrypt($token), lock: true);

        $this->files->chmod($path, 0600);
    }

    /**
     * {@inheritdoc}
     */
    public function forget(string $provider): void
    {
        $this->files->delete($this->path($provider));
    }

    /**
     * Get the path of the token file for the given provider.
     */
    protected function path(string $provider): string
    {
        return $this->directory.'/'.sha1($provider).'.token';
    }
}
