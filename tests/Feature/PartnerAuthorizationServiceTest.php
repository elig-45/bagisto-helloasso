<?php

use Elig45\BagistoHelloAsso\Models\Connection;
use Elig45\BagistoHelloAsso\Services\PartnerAuthorizationService;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

it('uses PKCE state for partner authorization and saves the selected organization', function () {
    $connection = Connection::create([
        'channel_code' => 'mejf',
        'environment' => 'sandbox',
        'auth_mode' => 'partner',
        'client_id' => 'partner-client',
        'client_secret' => 'partner-secret',
    ]);
    $service = new PartnerAuthorizationService;
    $redirectUri = 'https://shop.example.test/admin/helloasso/callback/mejf';
    $authorizationUrl = $service->authorizationUrl($connection, $redirectUri);
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
    $state = session()->get('helloasso.oauth.mejf');
    $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $state['verifier'], true)), '+/', '-_'), '=');

    expect($query['state'])->toBe($state['state'])
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->toBe($expectedChallenge)
        ->and($query['redirect_uri'])->toBe($redirectUri);

    Http::fake(['*' => Http::response([
        'organization_slug' => 'mejf-sandbox',
        'access_token' => 'partner-access',
        'refresh_token' => 'partner-refresh',
        'expires_in' => 1800,
    ])]);

    $service->exchange($connection, 'authorization-code', $state['verifier'], $redirectUri);

    expect($connection->fresh()->organization_slug)->toBe('mejf-sandbox')
        ->and($connection->fresh()->refresh_token)->toBe('partner-refresh');

    Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/oauth2/token')
        && $request['grant_type'] === 'authorization_code'
        && $request['code_verifier'] === $state['verifier']);
});
