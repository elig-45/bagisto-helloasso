<?php

use Elig45\BagistoHelloAsso\Models\CheckoutAttempt;
use Elig45\BagistoHelloAsso\Models\Connection;
use Elig45\BagistoHelloAsso\Services\HelloAssoApiClient;
use Elig45\BagistoHelloAsso\Services\HelloAssoCheckoutService;
use Elig45\BagistoHelloAsso\Services\InstallmentPlanBuilder;

function checkoutServiceForTest(HelloAssoApiClient $api): HelloAssoCheckoutService
{
    return new class($api, new InstallmentPlanBuilder) extends HelloAssoCheckoutService
    {
        protected function cartSnapshot(object $cart): array
        {
            return $cart->snapshot;
        }
    };
}

function helloAssoCheckoutCart(array $overrides = []): object
{
    return (object) array_replace([
        'id' => 812,
        'cart_currency_code' => 'EUR',
        'grand_total' => '35.00',
        'customer_email' => 'shopper@example.test',
        'billing_address' => [
            'first_name' => 'Élodie',
            'last_name' => 'Martin',
            'email' => 'billing@example.test',
            'address' => ['10 rue de Paris'],
            'city' => 'Paris',
            'postcode' => '75001',
            'country' => 'FR',
        ],
        'snapshot' => [
            'items' => [['id' => 2, 'qty_ordered' => 1]],
            'payment' => ['method' => 'helloasso'],
        ],
    ], $overrides);
}

function sandboxConnection(array $overrides = []): Connection
{
    return Connection::create(array_replace([
        'channel_code' => Connection::GLOBAL_KEY,
        'environment' => 'sandbox',
        'auth_mode' => 'association',
        'organization_slug' => 'mejf-sandbox',
        'client_id' => 'sandbox-client',
        'client_secret' => 'sandbox-secret',
        'enable_sepa' => true,
        'installment_counts' => [3],
        'initial_percent' => 50,
    ], $overrides));
}

it('creates a euro checkout intent with payer data and a balanced installment plan', function () {
    $connection = sandboxConnection();
    $cart = helloAssoCheckoutCart();
    $capturedPayload = null;
    $api = Mockery::mock(HelloAssoApiClient::class);
    $api->shouldReceive('post')->once()->andReturnUsing(function ($actualConnection, $path, $payload) use ($connection, &$capturedPayload) {
        expect($actualConnection)->toBe($connection)
            ->and($path)->toBe('/organizations/mejf-sandbox/checkout-intents');

        $capturedPayload = $payload;

        return [
            'id' => 9001,
            'redirectUrl' => 'https://www.helloasso-sandbox.com/checkout/9001',
        ];
    });

    $attempt = checkoutServiceForTest($api)->start($connection, $cart, 'mejf', 3);
    $terms = $capturedPayload['terms'];

    expect($attempt->checkout_intent_id)->toBe(9001)
        ->and($attempt->total_amount)->toBe(3500)
        ->and($attempt->currency)->toBe('EUR')
        ->and($attempt->installment_count)->toBe(3)
        ->and($attempt->sepa_enabled)->toBeTrue()
        ->and($attempt->status)->toBe('redirected')
        ->and($attempt->cart_snapshot)->toBe($cart->snapshot)
        ->and($capturedPayload['totalAmount'])->toBe(3500)
        ->and($capturedPayload['initialAmount'])->toBe(1750)
        ->and(array_sum(array_column($terms, 'amount')) + $capturedPayload['initialAmount'])->toBe(3500)
        ->and($capturedPayload['payer'])->toMatchArray([
            'firstName' => 'Élodie',
            'lastName' => 'Martin',
            'email' => 'shopper@example.test',
            'country' => 'FRA',
        ])
        ->and($capturedPayload['paymentOptions'])->toBe(['enableSepa' => true])
        ->and($capturedPayload['metadata'])->toMatchArray([
            'cart_id' => '812',
            'channel_code' => 'mejf',
            'organization_slug' => 'mejf-sandbox',
            'currency' => 'EUR',
        ]);
});

it('reuses an unresolved checkout for an unchanged cart to avoid duplicate intents', function () {
    $connection = sandboxConnection();
    $api = Mockery::mock(HelloAssoApiClient::class);
    $api->shouldReceive('post')->once()->andReturn([
        'id' => 9002,
        'redirectUrl' => 'https://www.helloasso-sandbox.com/checkout/9002',
    ]);
    $service = checkoutServiceForTest($api);
    $cart = helloAssoCheckoutCart();

    $first = $service->start($connection, $cart, 'mejf');
    $second = $service->start($connection, $cart, 'mejf');

    expect($second->id)->toBe($first->id)
        ->and(CheckoutAttempt::query()->count())->toBe(1);
});

it('creates a fresh intent after HelloAsso has confirmed that the previous attempt was refused', function () {
    $connection = sandboxConnection();
    $api = Mockery::mock(HelloAssoApiClient::class);
    $api->shouldReceive('post')->twice()->andReturn(
        ['id' => 9003, 'redirectUrl' => 'https://www.helloasso-sandbox.com/checkout/9003'],
        ['id' => 9004, 'redirectUrl' => 'https://www.helloasso-sandbox.com/checkout/9004'],
    );
    $service = checkoutServiceForTest($api);
    $cart = helloAssoCheckoutCart();

    $first = $service->start($connection, $cart, 'mejf');
    $first->update(['status' => 'refused']);
    $second = $service->start($connection, $cart, 'mejf');

    expect($second->id)->not->toBe($first->id)
        ->and($second->checkout_intent_id)->toBe(9004)
        ->and(CheckoutAttempt::query()->count())->toBe(2);
});

it('refuses a redirect URL outside the selected HelloAsso environment', function () {
    $connection = sandboxConnection();
    $api = Mockery::mock(HelloAssoApiClient::class);
    $api->shouldReceive('post')->once()->andReturn([
        'id' => 9005,
        'redirectUrl' => 'https://attacker.example/checkout/9005',
    ]);

    expect(fn () => checkoutServiceForTest($api)->start($connection, helloAssoCheckoutCart(), 'mejf'))
        ->toThrow(RuntimeException::class)
        ->and(CheckoutAttempt::query()->count())->toBe(0);
});
