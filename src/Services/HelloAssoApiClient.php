<?php

namespace Elig45\BagistoHelloAsso\Services;

use Elig45\BagistoHelloAsso\Models\Connection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class HelloAssoApiClient
{
    public function __construct(protected AccessTokenService $tokens) {}

    public function get(Connection $connection, string $path): array
    {
        return $this->send($connection, 'GET', $path);
    }

    public function post(Connection $connection, string $path, array $data): array
    {
        return $this->send($connection, 'POST', $path, $data);
    }

    public function request(Connection $connection, string $method, string $path, array $query = []): array
    {
        return $this->send($connection, $method, $path, [], $query);
    }

    protected function send(
        Connection $connection,
        string $method,
        string $path,
        array $data = [],
        array $query = [],
    ): array {
        $path = '/'.ltrim($path, '/');
        $url = $connection->apiBaseUrl().$path;
        $token = $this->tokens->token($connection);

        $response = $this->requestWithToken($method, $url, $token, $data, $query);

        if ($response->status() === 401 && $connection->refresh_token) {
            $connection->forceFill(['token_expires_at' => now()->subMinute()])->save();

            $response = $this->requestWithToken($method, $url, $this->tokens->token($connection), $data, $query);
        }

        if (! $response->successful()) {
            $message = $response->json('message')
                ?? $response->json('error')
                ?? 'HelloAsso request failed.';

            throw new HelloAssoApiException(
                is_string($message) ? $message : 'HelloAsso request failed.',
                $response->status(),
                (array) $response->json(),
            );
        }

        return (array) $response->json();
    }

    protected function requestWithToken(
        string $method,
        string $url,
        string $token,
        array $data,
        array $query,
    ): Response {
        $request = Http::acceptJson()
            ->withOptions(array_filter(['proxy' => config('helloasso.proxy')]))
            ->withToken($token)
            ->timeout(25)
            ->connectTimeout(8);

        return match (strtoupper($method)) {
            'GET' => $request->get($url, $query),
            'POST' => $request->asJson()->post($query ? $url.'?'.http_build_query($query) : $url, $data),
            'PUT' => $request->asJson()->put($url, $data),
            default => throw new \InvalidArgumentException('Unsupported HelloAsso HTTP method.'),
        };
    }
}
