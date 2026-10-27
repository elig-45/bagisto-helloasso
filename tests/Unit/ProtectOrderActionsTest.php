<?php

use Elig45\BagistoHelloAsso\Listeners\ProtectOrderActions;
use Elig45\BagistoHelloAsso\Models\CheckoutAttempt;
use Elig45\BagistoHelloAsso\Models\HelloAssoPayment;
use Illuminate\Support\Str;

it('finds the order id in Bagisto refund event arguments', function () {
    $listener = (new ReflectionClass(ProtectOrderActions::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ProtectOrderActions::class, 'findOrderId');

    expect($method->invoke($listener, ['csrf-token', ['refund' => ['items' => []]], 42]))
        ->toBe(42);
});

it('finds an order id in a nested refund event payload', function () {
    $listener = (new ReflectionClass(ProtectOrderActions::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ProtectOrderActions::class, 'findOrderId');

    expect($method->invoke($listener, [['order_id' => 42, 'refund' => ['items' => []]]]))
        ->toBe(42);
});

it('blocks fulfillment when any HelloAsso payment for the order is contested', function () {
    $attempt = CheckoutAttempt::create([
        'cart_id' => 73,
        'channel_code' => 'mejf',
        'organization_slug' => 'mejf-sandbox',
        'checkout_intent_id' => 1903,
        'order_id' => 43,
        'reference' => (string) Str::uuid(),
        'total_amount' => 4500,
        'currency' => 'EUR',
        'status' => 'paid',
        'fulfillment_ready' => true,
    ]);
    $payment = HelloAssoPayment::create([
        'checkout_attempt_id' => $attempt->id,
        'order_id' => 43,
        'payment_id' => 703,
        'amount' => 4500,
        'state' => 'Authorized',
    ]);
    $listener = (new ReflectionClass(ProtectOrderActions::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ProtectOrderActions::class, 'canFulfill');

    expect($method->invoke($listener, 43))->toBeTrue();

    $payment->update(['state' => 'Contested']);

    expect($method->invoke($listener, 43))->toBeFalse();

    $payment->update(['state' => 'Authorized']);
    $attempt->update(['fulfillment_ready' => false]);

    expect($method->invoke($listener, 43))->toBeFalse();
});
