<?php

use Elig45\BagistoHelloAsso\Models\CheckoutAttempt;
use Illuminate\Support\Str;

it('stores installment plans as JSON and reads them back as arrays', function () {
    $plan = [
        'initialAmount' => 175,
        'terms' => [
            ['amount' => 175, 'date' => '2026-11-27'],
        ],
    ];

    $attempt = CheckoutAttempt::create([
        'cart_id' => 42,
        'channel_code' => 'mejf',
        'organization_slug' => 'mejf-test',
        'checkout_intent_id' => 900042,
        'reference' => (string) Str::uuid(),
        'installment_plan' => $plan,
        'total_amount' => 350,
        'currency' => 'EUR',
        'installment_count' => 2,
        'status' => 'redirected',
    ]);

    expect($attempt->fresh()->installment_plan)->toEqual($plan);
});
