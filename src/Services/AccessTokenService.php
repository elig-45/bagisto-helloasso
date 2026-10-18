<?php

namespace Elig45\BagistoHelloAsso\Services;

use Elig45\BagistoHelloAsso\Models\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AccessTokenService
{
    public function token(Connection $connection): string
    {
        if (
            $connection->access_token
            && $connection->token_expires_at?->isAfter(now()->addMinute())
        ) {
            return $connection->access_token;
        }

        return Cache::lock('helloasso.token.'.$connection->id, 15)->block(10, function () use ($connection) {
            $connection->refresh();

            if (
                $connection->access_token
                && $connection->token_expires_at?->isAfter(now()->addMinute())
            ) {
                return $connection->access_token;
            }

            $form = $connection->refresh_token
                ? [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $connection->refresh_token,
                ]
                : [
                    'grant_type' => 'client_credentials',
                    'client_id' => $connection->client_id,
                    'client_secret' => $connection->client_secret,
                ];

            $response = Http::asForm()
                ->acceptJson()
                ->timeout(20)
                ->post($connection->oauthBaseUrl().'/oauth2/token', $form);

            if (! $response->successful() || ! $response->json('access_token')) {
                throw new HelloAssoApiException(
                    'HelloAsso authentication failed.',
                    $response->status(),
                    [],
                );
            }

            $connection->forceFill([
                'access_token' => $response->json('access_token'),
                'refresh_token' => $response->json('refresh_token') ?: $connection->refresh_token,
                'token_expires_at' => now()->addSeconds((int) $response->json('expires_in', 1800)),
            ])->save();

            return $connection->access_token;
        });
    }
}
