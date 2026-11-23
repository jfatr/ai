<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Contracts\TokenStore;
use Laravel\Ai\Embeddings;

beforeEach(function (): void {
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('cache.default', 'array');
    config()->set('ai.oauth.path', $this->path = sys_get_temp_dir().'/ai-oauth-'.bin2hex(random_bytes(6)));
    config()->set('ai.providers.openai', [
        'driver' => 'openai',
        'key' => 'api-key',
        'oauth' => [
            'client_id' => 'client-123',
            'device_authorization_url' => 'https://auth.example.com/device',
            'token_url' => 'https://auth.example.com/token',
            'scope' => 'offline_access',
        ],
    ]);

    Sleep::fake();
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->path.'/*') ?: []);
    @rmdir($this->path);
});

function fakeDeviceLoginEmbeddings(): array
{
    return [
        'object' => 'list',
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2]]],
        'model' => 'text-embedding-3-small',
        'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1],
    ];
}

test('ai:login stores the token once the device code is approved', function (): void {
    Http::fake([
        'auth.example.com/device' => Http::response([
            'device_code' => 'device-code',
            'user_code' => 'ABCD-1234',
            'verification_uri' => 'https://auth.example.com/activate',
            'interval' => 1,
            'expires_in' => 600,
        ]),
        'auth.example.com/token' => Http::sequence()
            ->push(['error' => 'authorization_pending'], 400)
            ->push(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600]),
    ]);

    $this->artisan('ai:login', ['provider' => 'openai'])
        ->expectsOutputToContain('ABCD-1234')
        ->assertSuccessful();

    expect(app(TokenStore::class)->get('openai'))
        ->toMatchArray(['access_token' => 'access-1', 'refresh_token' => 'refresh-1']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://auth.example.com/token'
        && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:device_code'
        && $request['device_code'] === 'device-code'
        && $request['client_id'] === 'client-123');
});

test('ai:login fails when the provider has no oauth client', function (): void {
    config()->set('ai.providers.openai.oauth.client_id', null);

    $this->artisan('ai:login', ['provider' => 'openai'])->assertFailed();
});

test('ai:login fails when the user denies the request', function (): void {
    Http::fake([
        'auth.example.com/device' => Http::response(['device_code' => 'd', 'user_code' => 'u', 'verification_uri' => 'https://x.test']),
        'auth.example.com/token' => Http::response(['error' => 'access_denied'], 400),
    ]);

    $this->artisan('ai:login', ['provider' => 'openai'])->assertFailed();

    expect(app(TokenStore::class)->get('openai'))->toBeNull();
});

test('a stored token authenticates requests in place of the api key', function (): void {
    app(TokenStore::class)->put('openai', ['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_at' => time() + 3600]);

    Http::fake(['api.openai.com/*' => Http::response(fakeDeviceLoginEmbeddings())]);

    Embeddings::for(['Hello'])->generate(provider: 'openai');

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer access-1']);
    Http::assertSentCount(1);
});

test('an expiring token is refreshed and the rotated token is stored', function (): void {
    app(TokenStore::class)->put('openai', ['access_token' => 'old', 'refresh_token' => 'refresh-1', 'expires_at' => time() + 10]);

    Http::fake([
        'auth.example.com/token' => Http::response(['access_token' => 'new', 'refresh_token' => 'refresh-2', 'expires_in' => 3600]),
        'api.openai.com/*' => Http::response(fakeDeviceLoginEmbeddings()),
    ]);

    Embeddings::for(['Hello'])->generate(provider: 'openai');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://auth.example.com/token'
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'refresh-1');
    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer new']);

    expect(app(TokenStore::class)->get('openai'))->toMatchArray(['access_token' => 'new', 'refresh_token' => 'refresh-2']);
});

test('the api key is used until someone signs in, and again after ai:logout', function (): void {
    Http::fake(['api.openai.com/*' => Http::response(fakeDeviceLoginEmbeddings())]);

    app(TokenStore::class)->put('openai', ['access_token' => 'access-1']);

    $this->artisan('ai:logout', ['provider' => 'openai'])->assertSuccessful();

    Embeddings::for(['Hello'])->generate(provider: 'openai');

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer api-key']);
});

test('tokens are encrypted at rest', function (): void {
    app(TokenStore::class)->put('openai', ['access_token' => 'super-secret']);

    expect(file_get_contents(glob($this->path.'/*')[0]))->not->toContain('super-secret');
});
