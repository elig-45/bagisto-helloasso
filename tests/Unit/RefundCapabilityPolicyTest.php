<?php

use Elig45\BagistoHelloAsso\Services\RefundCapabilityPolicy;

it('allows full refunds with an association API key', function () {
    expect((new RefundCapabilityPolicy)->supports('association', 5000, 5000))->toBeTrue();
});

it('rejects partial refunds with an association API key', function () {
    expect((new RefundCapabilityPolicy)->supports('association', 2500, 5000))->toBeFalse();
});

it('keeps partial refunds available through a partner authorization', function () {
    expect((new RefundCapabilityPolicy)->supports('partner', 2500, 5000))->toBeTrue();
});
