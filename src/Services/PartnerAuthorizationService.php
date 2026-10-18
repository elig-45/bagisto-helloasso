<?php

namespace Elig45\BagistoHelloAsso\Services;

use Elig45\BagistoHelloAsso\Models\Connection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PartnerAuthorizationService
{
    public function authorizationUrl(Connection $connection, string $redirectUri): string
    {
        $verifier = $this->base64Url(random_bytes(48));
        $state = Str::random(48);
        $challenge = $this->base64Url(hash('sha256', $verifier, true));

        session()->put('helloasso.oauth.'.$connection->channel_code, [
            'verifier' => $verifier,
            'state' => $state,
            'redirect_uri' => $redirectUri,
        ]);

        return 'https://auth.helloasso.com/authorize?'.http_build_query([
            'client_id' => $connection->client_id,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
        ]);
    }

    public function exchange(Connection $connection, string $code, string $verifier, string $redirectUri): array
    {
        $response = Http::asForm()
            ->withOptions(array_filter(['proxy' => config('helloasso.proxy')]))
            ->acceptJson()
            ->timeout(20)
            ->post($connection->oauthBaseUrl().'/oauth2/token', [
                'grant_type' => 'authorization_code',
                'client_id' => $connection->client_id,
                'client_secret' => $connection->client_secret,
                'code' => $code,
                'code_verifier' => $verifier,
                'redirect_uri' => $redirectUri,
            ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new HelloAssoApiException('HelloAsso authorization failed.', $response->status());
        }

        $connection->forceFill([
            'organization_slug' => $response->json('organization_slug'),
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'token_expires_at' => now()->addSeconds((int) $response->json('expires_in', 1800)),
        ])->save();

        return $response->json();
    }

    protected function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
