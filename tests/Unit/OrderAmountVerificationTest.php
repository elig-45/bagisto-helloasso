<?php

use Elig45\BagistoHelloAsso\Models\CheckoutAttempt;
use Elig45\BagistoHelloAsso\Models\Connection;
use Elig45\BagistoHelloAsso\Services\HelloAssoApiClient;
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

it('abandons unpaid card checkouts after 45 minutes and waits through SEPA webhook retries', function () {
    $service = (new ReflectionClass(OrderSettlementService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(OrderSettlementService::class, 'shouldAbandonUnpaidCheckout');

    expect($method->invoke($service, new CheckoutAttempt(['created_at' => now()->subMinutes(46), 'sepa_enabled' => false])))->toBeTrue()
        ->and($method->invoke($service, new CheckoutAttempt(['created_at' => now()->subMinutes(44), 'sepa_enabled' => false])))->toBeFalse()
        ->and($method->invoke($service, new CheckoutAttempt(['created_at' => now()->subHours(27), 'sepa_enabled' => true])))->toBeFalse()
        ->and($method->invoke($service, new CheckoutAttempt(['created_at' => now()->subHours(29), 'sepa_enabled' => true])))->toBeTrue()
        ->and($method->invoke($service, new CheckoutAttempt(['created_at' => now()->subHours(27)])))->toBeFalse();
});

it('marks a remotely checked unpaid card attempt abandoned so its cart can start a fresh checkout', function () {
    $connection = Connection::create([
        'channel_code' => Connection::GLOBAL_KEY,
        'environment' => 'sandbox',
        'auth_mode' => 'association',
        'organization_slug' => 'mejf-sandbox',
        'client_id' => 'sandbox-client',
        'client_secret' => 'sandbox-secret',
        'enable_sepa' => false,
    ]);
    $attempt = CheckoutAttempt::create([
        'cart_id' => 1901,
        'channel_code' => 'mejf',
        'organization_slug' => 'mejf-sandbox',
        'environment' => 'sandbox',
        'auth_mode' => 'association',
        'sepa_enabled' => false,
        'checkout_intent_id' => 2901,
        'reference' => 'f23b57fc-d68a-4ecf-b85e-f8670b72ab4a',
        'total_amount' => 3500,
        'currency' => 'EUR',
        'status' => 'pending',
        'created_at' => now()->subMinutes(46),
    ]);
    $api = Mockery::mock(HelloAssoApiClient::class);
    $api->shouldReceive('get')
        ->once()
        ->with($connection, '/organizations/mejf-sandbox/checkout-intents/2901')
        ->andReturn([
            'id' => 2901,
            'metadata' => [
                'bagisto_ref' => 'f23b57fc-d68a-4ecf-b85e-f8670b72ab4a',
                'cart_id' => '1901',
                'channel_code' => 'mejf',
                'organization_slug' => 'mejf-sandbox',
                'currency' => 'EUR',
            ],
        ]);
    $service = (new ReflectionClass(OrderSettlementService::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(OrderSettlementService::class, 'api'))->setValue($service, $api);

    expect($service->reconcile($attempt, $connection))->toBe('abandoned')
        ->and($attempt->fresh()->status)->toBe('abandoned');
});

it('matches each remote installment to the expected amount and checkout metadata', function () {
    $service = (new ReflectionClass(OrderSettlementService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(OrderSettlementService::class, 'paymentMatchesAttempt');
    $attempt = new CheckoutAttempt([
        'checkout_intent_id' => 1901,
        'organization_slug' => 'mejf-sandbox',
        'currency' => 'EUR',
        'installment_count' => 3,
        'installment_plan' => [
            'initialAmount' => 5000,
            'terms' => [
                ['amount' => 2500, 'date' => '2026-11-27'],
                ['amount' => 2500, 'date' => '2026-12-27'],
            ],
        ],
    ]);
    $payment = [
        'id' => 3001,
        'amount' => 5000,
        'checkoutIntentId' => 1901,
        'organizationSlug' => 'mejf-sandbox',
        'currency' => 'EUR',
    ];

    expect($method->invoke($service, $attempt, $payment))->toBeTrue()
        ->and($method->invoke($service, $attempt, array_replace($payment, ['amount' => 2500])))->toBeTrue()
        ->and($method->invoke($service, $attempt, array_replace($payment, ['amount' => 10000])))->toBeFalse()
        ->and($method->invoke($service, $attempt, array_replace($payment, ['amount' => 2499])))->toBeFalse()
        ->and($method->invoke($service, $attempt, array_replace($payment, ['checkoutIntentId' => 1902])))->toBeFalse()
        ->and($method->invoke($service, $attempt, array_replace($payment, ['organizationSlug' => 'other-org'])))->toBeFalse()
        ->and($method->invoke($service, $attempt, array_replace($payment, ['currency' => 'USD'])))->toBeFalse()
        ->and($method->invoke($service, $attempt, array_diff_key($payment, ['checkoutIntentId' => true])))->toBeFalse()
        ->and($method->invoke($service, $attempt, array_diff_key($payment, ['checkoutIntentId' => true]), false))->toBeTrue();
});

it('fetches each remote payment once when order items repeat payment identifiers', function () {
    $service = (new ReflectionClass(OrderSettlementService::class))->newInstanceWithoutConstructor();
    $api = Mockery::mock(HelloAssoApiClient::class);
    $property = new ReflectionProperty(OrderSettlementService::class, 'api');
    $property->setValue($service, $api);
    $attempt = new CheckoutAttempt;
    $connection = new Connection;

    $api->shouldReceive('get')->once()->with($connection, '/payments/501')->andReturn([
        'id' => 501,
        'amount' => 5000,
        'state' => 'Authorized',
        'paymentMeans' => 'Card',
    ]);
    $api->shouldReceive('get')->once()->with($connection, '/payments/502')->andReturn([
        'id' => 502,
        'amount' => 2500,
        'state' => 'Pending',
        'paymentMeans' => 'Sepa',
    ]);
    $api->shouldReceive('get')->once()->with($connection, '/payments/503')->andReturn([
        'id' => 503,
        'amount' => 2500,
        'state' => 'Pending',
        'paymentMeans' => 'Sepa',
    ]);

    $method = new ReflectionMethod(OrderSettlementService::class, 'fetchPayments');
    $payments = $method->invoke($service, $attempt, $connection, [
        'items' => [[
            'payments' => [['id' => 501], ['id' => 502]],
        ]],
        'payments' => [['id' => 502], ['id' => 503]],
    ]);

    expect(array_column($payments, 'id'))->toBe([501, 502, 503])
        ->and(array_column($payments, 'paymentMeans'))->toBe(['Card', 'Sepa', 'Sepa']);
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
