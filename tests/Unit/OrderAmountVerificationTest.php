<?php

use Elig45\BagistoHelloAsso\Services\OrderSettlementService;

it('requires an exact remote order total in cents', function () {
    $service = (new ReflectionClass(OrderSettlementService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(OrderSettlementService::class, 'orderTotalMatches');

    expect($method->invoke($service, ['amount' => ['total' => 12345]], 12345))->toBeTrue()
        ->and($method->invoke($service, ['amount' => ['total' => 12344]], 12345))->toBeFalse()
        ->and($method->invoke($service, ['amount' => []], 12345))->toBeFalse();
});
