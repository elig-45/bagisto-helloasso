<?php

use Elig45\BagistoHelloAsso\Models\CheckoutAttempt;
use Elig45\BagistoHelloAsso\Models\Connection;
use Elig45\BagistoHelloAsso\Services\OrderSettlementService;

it('requires an exact remote order total in cents', function () {
    $service = (new ReflectionClass(OrderSettlementService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(OrderSettlementService::class, 'orderTotalMatches');

    expect($method->invoke($service, ['amount' => ['total' => 12345]], 12345))->toBeTrue()
        ->and($method->invoke($service, ['amount' => ['total' => 12344]], 12345))->toBeFalse()
        ->and($method->invoke($service, ['amount' => []], 12345))->toBeFalse();
});

it('rejects checkout metadata that belongs to another channel or association', function () {
    $service = (new ReflectionClass(OrderSettlementService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(OrderSettlementService::class, 'intentMatchesAttempt');
    $attempt = new CheckoutAttempt([
        'checkout_intent_id' => 19,
        'reference' => '6a87b195-1833-4b42-a04a-f8149aa47f6f',
        'cart_id' => 44,
        'channel_code' => 'mejf',
        'organization_slug' => 'mejf-sandbox',
        'currency' => 'EUR',
    ]);
    $intent = [
        'id' => 19,
        'metadata' => [
            'bagisto_ref' => '6a87b195-1833-4b42-a04a-f8149aa47f6f',
            'cart_id' => '44',
            'channel_code' => 'mejf',
            'organization_slug' => 'mejf-sandbox',
            'currency' => 'EUR',
        ],
    ];

    expect($method->invoke($service, $attempt, $intent))->toBeTrue()
        ->and($method->invoke($service, $attempt, array_replace_recursive($intent, ['metadata' => ['channel_code' => 'betar']])))->toBeFalse()
        ->and($method->invoke($service, $attempt, array_replace_recursive($intent, ['metadata' => ['organization_slug' => 'other-org']])))->toBeFalse()
        ->and($method->invoke($service, $attempt, array_replace_recursive($intent, ['metadata' => ['currency' => 'USD']])))->toBeFalse()
        ->and($method->invoke($service, $attempt, array_replace($intent, ['id' => 20])))->toBeFalse();
});

it('does not authorize pending SEPA or production preproduction payments', function () {
    $service = (new ReflectionClass(OrderSettlementService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(OrderSettlementService::class, 'isAuthorized');
    $sandbox = new Connection(['environment' => 'sandbox']);
    $production = new Connection(['environment' => 'production']);

    expect($method->invoke($service, 'WaitingBankValidation', $sandbox))->toBeFalse()
        ->and($method->invoke($service, 'AuthorizedPreprod', $sandbox))->toBeTrue()
        ->and($method->invoke($service, 'AuthorizedPreprod', $production))->toBeFalse()
        ->and($method->invoke($service, 'Authorized', $production))->toBeTrue();
});
