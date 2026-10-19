<?php

use Elig45\BagistoHelloAsso\Listeners\ProtectOrderActions;

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
