<?php

use Elig45\BagistoHelloAsso\Services\WebhookSignatureVerifier;

it('requires the exact HMAC signature for the raw notification body', function () {
    $body = '{"eventType":"Payment","data":{"checkoutIntentId":19}}';
    $key = 'partner-notification-signature';
    $signature = hash_hmac('sha256', $body, $key);
    $verifier = new WebhookSignatureVerifier;

    expect($verifier->isValid($body, $signature, $key))->toBeTrue()
        ->and($verifier->isValid($body, strtoupper($signature), $key))->toBeTrue()
        ->and($verifier->isValid($body.' ', $signature, $key))->toBeFalse()
        ->and($verifier->isValid($body, null, $key))->toBeFalse();
});
