<?php

use Elig45\BagistoHelloAsso\Models\Connection;
use Elig45\BagistoHelloAsso\Services\AccessTokenService;
use Elig45\BagistoHelloAsso\Services\HelloAssoApiClient;
use Elig45\BagistoHelloAsso\Services\HelloAssoApiException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

it('authenticates with association credentials and stores encrypted rotating tokens', function () {
    $connection = Connection::create([
        'channel_code' => 'mejf',
        'environment' => 'sandbox',
        'auth_mode' => 'association',
        'organization_slug' => 'mejf-test',
        'client_id' => 'sandbox-client',
        'client_secret' => 'sandbox-secret',
    ]);

    Http::fake(function (ClientRequest $request) {
        if (str_ends_with($request->url(), '/oauth2/token')) {
            return Http::response([
                'access_token' => 'access-one',
                'refresh_token' => 'refresh-one',
                'expires_in' => 1800,
            ]);
        }

        return Http::response(['id' => 9001]);
    });

    $response = (new HelloAssoApiClient(new AccessTokenService))
        ->get($connection, '/payments/9001');

    expect($response['id'])->toBe(9001)
        ->and($connection->fresh()->refresh_token)->toBe('refresh-one');

    Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/oauth2/token')
        && $request['grant_type'] === 'client_credentials'
        && $request['client_id'] === 'sandbox-client');
});

it('refreshes a token after an unauthorized response and retries once', function () {
    $connection = Connection::create([
        'channel_code' => 'betar',
        'environment' => 'sandbox',
        'auth_mode' => 'association',
        'organization_slug' => 'betar-test',
        'client_id' => 'sandbox-client',
        'client_secret' => 'sandbox-secret',
        'access_token' => 'expired-access',
        'refresh_token' => 'refresh-before',
        'token_expires_at' => now()->subMinute(),
    ]);

    $paymentRequests = 0;
    Http::fake(function (ClientRequest $request) use (&$paymentRequests) {
        if (str_ends_with($request->url(), '/oauth2/token')) {
            return Http::response([
                'access_token' => 'access-after-refresh',
                'refresh_token' => 'refresh-after-rotation',
                'expires_in' => 1800,
            ]);
        }

        $paymentRequests++;

        return $paymentRequests === 1
            ? Http::response(['message' => 'expired'], 401)
            : Http::response(['id' => 9002]);
    });

    $response = (new HelloAssoApiClient(new AccessTokenService))
        ->get($connection, '/payments/9002');

    expect($response['id'])->toBe(9002)
        ->and($paymentRequests)->toBe(2)
        ->and($connection->fresh()->access_token)->toBe('access-after-refresh')
        ->and($connection->fresh()->refresh_token)->toBe('refresh-after-rotation');

    Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/payments/9002')
        && $request->hasHeader('Authorization', 'Bearer access-after-refresh'));
});

it('preserves HelloAsso API failures as failed requests', function () {
    $connection = Connection::create([
        'channel_code' => 'mejf',
        'environment' => 'sandbox',
        'auth_mode' => 'association',
        'organization_slug' => 'mejf-test',
        'client_id' => 'sandbox-client',
        'access_token' => 'valid-access',
        'token_expires_at' => now()->addMinutes(20),
    ]);

    Http::fake(['*' => Http::response(['message' => 'Temporary API error'], 503)]);

    try {
        (new HelloAssoApiClient(new AccessTokenService))->get($connection, '/payments/9003');
        $this->fail('A failed HelloAsso response must not be treated as a successful payment lookup.');
    } catch (HelloAssoApiException $exception) {
        expect($exception->statusCode)->toBe(503);
    }
});
