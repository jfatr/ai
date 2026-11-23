<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Tests\Fixtures\Agents\AssistantAgent;

test('an auth token is sent as a bearer token in place of the api key', function (): void {
    Http::fake(['api.anthropic.com/*' => $this->fakeTextResponse()]);

    (new AssistantAgent)->prompt('Hi', provider: Ai::build([
        'driver' => 'anthropic',
        'key' => 'unused-key',
        'auth_token' => 'bearer-token',
    ]));

    Http::assertSent(fn ($request): bool => $request->header('Authorization') === ['Bearer bearer-token']
        && ! $request->hasHeader('x-api-key'));
});

test('the api key is still used when no auth token is configured', function (): void {
    Http::fake(['api.anthropic.com/*' => $this->fakeTextResponse()]);

    (new AssistantAgent)->prompt('Hi', provider: Ai::build(['driver' => 'anthropic', 'key' => 'api-key']));

    Http::assertSent(fn ($request): bool => $request->header('x-api-key') === ['api-key']
        && ! $request->hasHeader('Authorization'));
});

test('credentials are not exposed as additional configuration', function (): void {
    $provider = Ai::build(['driver' => 'anthropic', 'auth_token' => 'bearer-token', 'oauth' => ['client_id' => null]]);

    expect($provider->additionalConfiguration())->not->toHaveKeys(['auth_token', 'oauth'])
        ->and($provider->providerCredentials())->toBe(['key' => null, 'token' => 'bearer-token']);
});
