<?php

use Elig45\BagistoHelloAsso\Models\CheckoutAttempt;
use Elig45\BagistoHelloAsso\Models\Connection;
use Elig45\BagistoHelloAsso\Models\WebhookReceipt;
use Elig45\BagistoHelloAsso\Services\HelloAssoApiClient;
use Elig45\BagistoHelloAsso\Services\HelloAssoCheckoutService;
use Elig45\BagistoHelloAsso\Services\OrderSettlementService;
use Webkul\Checkout\Repositories\CartRepository;

function webhookTestConnection(): Connection
{
    return Connection::create([
        'channel_code' => Connection::GLOBAL_KEY,
        'environment' => 'sandbox',
        'auth_mode' => 'association',
        'organization_slug' => 'mejf-sandbox',
        'client_id' => 'sandbox-client',
        'client_secret' => 'sandbox-secret',
        'webhook_signature_key' => 'partner-webhook-secret',
    ]);
}

function webhookTestAttempt(): CheckoutAttempt
{
    return CheckoutAttempt::create([
        'cart_id' => 1901,
        'channel_code' => 'mejf',
        'organization_slug' => 'mejf-sandbox',
        'environment' => 'sandbox',
        'auth_mode' => 'association',
        'checkout_intent_id' => 2901,
        'reference' => 'f23b57fc-d68a-4ecf-b85e-f8670b72ab4a',
        'total_amount' => 3500,
        'currency' => 'EUR',
        'status' => 'redirected',
    ]);
}

function callSignedWebhook(Closure $call, array $payload, string $secret = 'partner-webhook-secret')
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $body, $secret);

    return $call($body, $signature);
}

function bindWebhookControllerDependencies(OrderSettlementService $settlement, ?HelloAssoApiClient $api = null): void
{
    app()->instance(HelloAssoCheckoutService::class, Mockery::mock(HelloAssoCheckoutService::class));
    app()->instance(HelloAssoApiClient::class, $api ?? Mockery::mock(HelloAssoApiClient::class));
    app()->instance(OrderSettlementService::class, $settlement);
    app()->instance(CartRepository::class, new stdClass);
}

it('deduplicates a repeated signed payment notification before reconciling it twice', function () {
    $connection = webhookTestConnection();
    $attempt = webhookTestAttempt();
    $settlement = Mockery::mock(OrderSettlementService::class);
    bindWebhookControllerDependencies($settlement);

    $settlement->shouldReceive('reconcile')
        ->once()
        ->withArgs(fn ($actualAttempt, $actualConnection, $paymentId, $trusted) => $actualAttempt->is($attempt)
            && $actualConnection->is($connection)
            && $paymentId === 9901
            && $trusted === true
        )
        ->andReturn('payment_recorded');

    $payment = ['eventType' => 'Payment', 'data' => ['checkoutIntentId' => 2901, 'paymentId' => 9901]];
    $post = fn (string $body, string $signature) => $this->call('POST', '/helloasso/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HA_SIGNATURE' => $signature,
    ], $body);

    $first = callSignedWebhook($post, $payment);
    $duplicate = callSignedWebhook($post, $payment);

    expect($first->status())->toBe(200)
        ->and($first->json('status'))->toBe('processed')
        ->and($duplicate->status())->toBe(200)
        ->and($duplicate->json('status'))->toBe('duplicate')
        ->and(WebhookReceipt::count())->toBe(1)
        ->and(WebhookReceipt::first()->status)->toBe('processed');
});

it('processes a payment notification before its later order notification', function () {
    webhookTestConnection();
    webhookTestAttempt();
    $settlement = Mockery::mock(OrderSettlementService::class);
    bindWebhookControllerDependencies($settlement);

    $settlement->shouldReceive('reconcile')
        ->once()
        ->withArgs(fn ($attempt, $connection, $paymentId, $trusted) => $paymentId === 9901 && $trusted)
        ->andReturn('payment_recorded');
    $settlement->shouldReceive('reconcile')
        ->once()
        ->withArgs(fn ($attempt, $connection, $paymentId, $trusted) => $paymentId === null && ! $trusted)
        ->andReturn('order_ready');

    $post = fn (string $body, string $signature) => $this->call('POST', '/helloasso/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HA_SIGNATURE' => $signature,
    ], $body);
    $payment = callSignedWebhook($post, ['eventType' => 'Payment', 'data' => ['checkoutIntentId' => 2901, 'paymentId' => 9901]]);
    $order = callSignedWebhook($post, ['eventType' => 'Order', 'data' => ['order' => ['checkoutIntentId' => 2901]]]);

    expect($payment->json('status'))->toBe('processed')
        ->and($order->json('status'))->toBe('processed')
        ->and(WebhookReceipt::where('status', 'processed')->count())->toBe(2);
});

it('resolves a pending SEPA payment through its order when the payment API omits the checkout intent', function () {
    $connection = webhookTestConnection();
    $attempt = webhookTestAttempt();
    $api = Mockery::mock(HelloAssoApiClient::class);
    $api->shouldReceive('get')->once()
        ->withArgs(fn ($actualConnection, $path) => $actualConnection->is($connection) && $path === '/payments/9901')
        ->andReturn([
            'id' => 9901,
            'paymentMeans' => 'Sepa',
            'state' => 'WaitingBankValidation',
        ]);
    $api->shouldReceive('get')->once()
        ->withArgs(fn ($actualConnection, $path) => $actualConnection->is($connection) && $path === '/orders/22707')
        ->andReturn([
            'id' => 22707,
            'checkoutIntentId' => 2901,
            'organizationSlug' => 'mejf-sandbox',
        ]);

    $settlement = Mockery::mock(OrderSettlementService::class);
    $settlement->shouldReceive('reconcile')
        ->once()
        ->withArgs(fn ($actualAttempt, $actualConnection, $paymentId, $trusted) => $actualAttempt->is($attempt)
            && $actualConnection->is($connection)
            && $paymentId === 9901
            && $trusted === true
        )
        ->andReturn('order_pending_payment');
    bindWebhookControllerDependencies($settlement, $api);

    $payload = [
        'eventType' => 'Payment',
        'data' => [
            'order' => ['id' => 22707, 'organizationSlug' => 'mejf-sandbox'],
            'id' => 9901,
            'paymentMeans' => 'Sepa',
            'state' => 'WaitingBankValidation',
        ],
    ];
    $response = callSignedWebhook(
        fn (string $body, string $signature) => $this->call('POST', '/helloasso/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HA_SIGNATURE' => $signature,
        ], $body),
        $payload,
    );

    expect($response->status())->toBe(200)
        ->and($response->json('status'))->toBe('processed')
        ->and(WebhookReceipt::first()->status)->toBe('processed');
});

it('does not associate a payment with an order from another organization', function () {
    $connection = webhookTestConnection();
    webhookTestAttempt();
    $api = Mockery::mock(HelloAssoApiClient::class);
    $api->shouldReceive('get')->once()
        ->withArgs(fn ($actualConnection, $path) => $actualConnection->is($connection) && $path === '/payments/9902')
        ->andReturn(['id' => 9902, 'orderId' => 22708, 'state' => 'WaitingBankValidation']);
    $api->shouldReceive('get')->once()
        ->withArgs(fn ($actualConnection, $path) => $actualConnection->is($connection) && $path === '/orders/22708')
        ->andReturn([
            'id' => 22708,
            'checkoutIntentId' => 2901,
            'organizationSlug' => 'another-organization',
        ]);

    $settlement = Mockery::mock(OrderSettlementService::class);
    $settlement->shouldNotReceive('reconcile');
    bindWebhookControllerDependencies($settlement, $api);

    $response = callSignedWebhook(
        fn (string $body, string $signature) => $this->call('POST', '/helloasso/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HA_SIGNATURE' => $signature,
        ], $body),
        ['eventType' => 'Payment', 'data' => ['id' => 9902]],
    );

    expect($response->status())->toBe(200)
        ->and($response->json('status'))->toBe('checkout_not_found')
        ->and(WebhookReceipt::first()->status)->toBe('ignored');
});

it('rejects a forged notification and retries the same receipt after a transient settlement failure', function () {
    webhookTestConnection();
    webhookTestAttempt();
    $settlement = Mockery::mock(OrderSettlementService::class);
    bindWebhookControllerDependencies($settlement);

    $settlement->shouldReceive('reconcile')->twice()->andReturnUsing(function () {
        static $attempts = 0;

        if (++$attempts === 1) {
            throw new RuntimeException('simulated temporary API failure');
        }

        return 'payment_recorded';
    });

    $payload = ['eventType' => 'Payment', 'data' => ['checkoutIntentId' => 2901, 'paymentId' => 9901]];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $invalid = $this->call('POST', '/helloasso/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HA_SIGNATURE' => str_repeat('0', 64),
    ], $body);
    $signature = hash_hmac('sha256', $body, 'partner-webhook-secret');
    $post = fn () => $this->call('POST', '/helloasso/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HA_SIGNATURE' => $signature,
    ], $body);
    $failed = $post();
    $retried = $post();

    expect($invalid->status())->toBe(400)
        ->and($invalid->json('status'))->toBe('invalid_signature')
        ->and($failed->status())->toBe(500)
        ->and($failed->json('status'))->toBe('retry')
        ->and($retried->status())->toBe(200)
        ->and($retried->json('status'))->toBe('processed')
        ->and(WebhookReceipt::count())->toBe(1)
        ->and(WebhookReceipt::first()->status)->toBe('processed');
});
