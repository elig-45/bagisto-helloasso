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

it('creates a pending Bagisto order for a SEPA payment awaiting bank validation', function () {
    $service = (new ReflectionClass(OrderSettlementService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(OrderSettlementService::class, 'shouldCreateOrder');

    expect($method->invoke($service, 'first_authorized', false, false, true))->toBeTrue()
        ->and($method->invoke($service, 'full_amount', false, false, true))->toBeTrue()
        ->and($method->invoke($service, 'first_authorized', false, false, false))->toBeFalse();
});

it('recognizes waiting SEPA states without treating refused payments as pending', function () {
    $service = (new ReflectionClass(OrderSettlementService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(OrderSettlementService::class, 'hasPendingSepaPayment');

    expect($method->invoke($service, [['paymentMeans' => 'Sepa', 'state' => 'WaitingBankValidation']]))->toBeTrue()
        ->and($method->invoke($service, [['paymentMeans' => 'Sepa', 'state' => 'Refused']]))->toBeFalse()
        ->and($method->invoke($service, [['paymentMeans' => 'Card', 'state' => 'Pending']]))->toBeFalse();
});

it('recognizes a declined checkout only when every known payment is refused', function () {
    $service = (new ReflectionClass(OrderSettlementService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(OrderSettlementService::class, 'hasOnlyRefusedPayments');

    expect($method->invoke($service, [['state' => 'Refused']]))->toBeTrue()
        ->and($method->invoke($service, [['state' => 'Refused'], ['state' => 'Refused']]))->toBeTrue()
        ->and($method->invoke($service, []))->toBeFalse()
        ->and($method->invoke($service, [['state' => 'Refused'], ['state' => 'Pending']]))->toBeFalse()
        ->and($method->invoke($service, [['state' => 'Refused'], ['state' => 'Authorized']]))->toBeFalse();
});

it('keeps SEPA installment orders pending until the full amount is authorized', function () {
    $service = (new ReflectionClass(OrderSettlementService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(OrderSettlementService::class, 'isOrderReady');
    $connection = new Connection(['order_policy' => 'first_authorized']);
    $installmentAttempt = new CheckoutAttempt(['installment_count' => 3]);
    $sepaPayments = [
        ['paymentMeans' => 'Sepa', 'state' => 'Authorized', 'amount' => 5000],
        ['paymentMeans' => 'Sepa', 'state' => 'Pending', 'amount' => 2500],
        ['paymentMeans' => 'Sepa', 'state' => 'Pending', 'amount' => 2500],
    ];

    expect($method->invoke($service, $connection, $installmentAttempt, $sepaPayments, 5000, false))->toBeFalse()
        ->and($method->invoke($service, $connection, $installmentAttempt, $sepaPayments, 10000, true))->toBeTrue();
});

it('keeps single SEPA and card installment readiness on the configured policy', function () {
    $service = (new ReflectionClass(OrderSettlementService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(OrderSettlementService::class, 'isOrderReady');
    $connection = new Connection(['order_policy' => 'first_authorized']);
    $singlePaymentAttempt = new CheckoutAttempt(['installment_count' => 1]);
    $cardInstallmentAttempt = new CheckoutAttempt(['installment_count' => 3]);

    expect($method->invoke($service, $connection, $singlePaymentAttempt, [['paymentMeans' => 'Sepa']], 10000, false))->toBeTrue()
        ->and($method->invoke($service, $connection, $cardInstallmentAttempt, [['paymentMeans' => 'Card']], 5000, false))->toBeTrue();
});
