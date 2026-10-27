<?php

use Elig45\BagistoHelloAsso\Models\CheckoutAttempt;
use Elig45\BagistoHelloAsso\Services\RefundSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('removes payer and financial details from saved payment data', function () {
    $service = (new ReflectionClass(RefundSyncService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(RefundSyncService::class, 'safePaymentData');

    $data = $method->invoke($service, [
        'id' => 701,
        'amount' => 4500,
        'state' => 'Authorized',
        'paymentMeans' => 'Card',
        'installmentNumber' => 1,
        'checkoutIntentId' => 1901,
        'cashOutState' => 'TransferInProgress',
        'email' => 'payer@example.test',
        'firstName' => 'Ada',
        'cardNumber' => '4111111111111111',
        'refundOperations' => [[
            'id' => 801,
            'amount' => 4500,
            'status' => 'Processed',
            'comment' => 'private refund note',
        ]],
    ]);

    expect($data)->toBe([
        'id' => 701,
        'amount' => 4500,
        'state' => 'Authorized',
        'paymentMeans' => 'Card',
        'installmentNumber' => 1,
        'checkoutIntentId' => 1901,
        'cashOutState' => 'TransferInProgress',
        'refundOperations' => [['id' => 801, 'amount' => 4500, 'status' => 'Processed']],
    ]);
});

it('marks a refund request failed only after every remote operation has failed', function () {
    $attempt = CheckoutAttempt::create([
        'cart_id' => 71,
        'channel_code' => 'mejf',
        'organization_slug' => 'mejf-sandbox',
        'checkout_intent_id' => 1901,
        'reference' => (string) Str::uuid(),
        'total_amount' => 4500,
        'currency' => 'EUR',
        'status' => 'paid',
    ]);

    $failedKey = (string) Str::uuid();
    $pendingKey = (string) Str::uuid();
    $requests = [
        [
            'request_key' => $failedKey,
            'checkout_attempt_id' => $attempt->id,
            'order_id' => 71,
            'amount' => 4500,
            'refund_data' => json_encode(['items' => [], 'shipping' => 0, 'adjustment_refund' => 45, 'adjustment_fee' => 0], JSON_THROW_ON_ERROR),
            'comment' => 'Test rejection',
            'state' => 'processing',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'request_key' => $pendingKey,
            'checkout_attempt_id' => $attempt->id,
            'order_id' => 72,
            'amount' => 4500,
            'refund_data' => json_encode(['items' => [], 'shipping' => 0, 'adjustment_refund' => 45, 'adjustment_fee' => 0], JSON_THROW_ON_ERROR),
            'comment' => 'Pending refund',
            'state' => 'uncertain',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ];

    DB::table('helloasso_refund_requests')->insert($requests);
    DB::table('helloasso_refund_operations')->insert([
        [
            'payment_id' => 701,
            'remote_refund_id' => 'failed-operation',
            'order_id' => 71,
            'amount' => 4500,
            'state' => 'Refused',
            'request_key' => $failedKey,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'payment_id' => 702,
            'remote_refund_id' => 'refused-operation',
            'order_id' => 72,
            'amount' => 2500,
            'state' => 'Refused',
            'request_key' => $pendingKey,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'payment_id' => 703,
            'remote_refund_id' => 'processing-operation',
            'order_id' => 72,
            'amount' => 2000,
            'state' => 'Processing',
            'request_key' => $pendingKey,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $service = (new ReflectionClass(RefundSyncService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(RefundSyncService::class, 'settleFailedRefundRequests');
    $method->invoke($service, 71, (object) ['id' => 71]);

    expect(DB::table('helloasso_refund_requests')->where('request_key', $failedKey)->value('state'))->toBe('failed')
        ->and(DB::table('helloasso_refund_requests')->where('request_key', $pendingKey)->value('state'))->toBe('uncertain');
});
