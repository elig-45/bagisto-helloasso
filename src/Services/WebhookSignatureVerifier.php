<?php

namespace Elig45\BagistoHelloAsso\Services;

class WebhookSignatureVerifier
{
    public function isValid(string $body, ?string $signature, string $key): bool
    {
        if (! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $body, $key), strtolower($signature));
    }
}
