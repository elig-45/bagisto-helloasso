<?php

namespace Elig45\BagistoHelloAsso\Services;

use Elig45\BagistoHelloAsso\Exceptions\RefundNotSupportedException;
use Elig45\BagistoHelloAsso\Models\CheckoutAttempt;
use Elig45\BagistoHelloAsso\Models\Connection;
use Elig45\BagistoHelloAsso\Models\HelloAssoPayment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Repositories\RefundRepository;

class RefundSyncService
{
    public function __construct(
        protected HelloAssoApiClient $api,
        protected OrderRepository $orders,
        protected RefundRepository $refunds,
        protected RefundSyncGuard $guard,
        protected RefundCapabilityPolicy $refundPolicy,
    ) {}

    public function syncOrder(int $orderId, Connection $connection): int
    {
        return Cache::lock('helloasso.refund-order.'.$orderId, 1800)->block(15, function () use ($orderId, $connection) {
            return $this->syncOrderLocked($orderId, $connection);
        });
    }

    protected function syncOrderLocked(int $orderId, Connection $connection): int
    {
        $order = $this->orders->findOrFail($orderId);
        $attempt = CheckoutAttempt::where('order_id', $orderId)->firstOrFail();
        abort_unless($connection->canManageAttempt($attempt), 409, 'HelloAsso connection does not match this payment.');
        $recorded = 0;

        foreach (HelloAssoPayment::where('checkout_attempt_id', $attempt->id)->get() as $localPayment) {
            $remote = $this->api->get($connection, '/payments/'.$localPayment->payment_id);

            if (
                (int) ($remote['id'] ?? 0) !== (int) $localPayment->payment_id
                || (int) ($remote['checkoutIntentId'] ?? 0) !== (int) $attempt->checkout_intent_id
                || (isset($remote['organizationSlug']) && $remote['organizationSlug'] !== $attempt->organization_slug)
                || (int) ($remote['amount'] ?? 0) !== (int) $localPayment->amount
            ) {
                throw new HelloAssoApiException('The remote payment does not match this Bagisto checkout.');
            }

            $operations = $remote['refundOperations'] ?? [];
            $refunded = 0;

            foreach ($operations as $operation) {
                $operationId = (string) ($operation['id'] ?? '');
                $amount = (int) ($operation['amount'] ?? 0);
                $state = (string) ($operation['status'] ?? 'Unknown');

                if ($operationId !== '') {
                    $pendingRequestOperation = DB::table('helloasso_refund_operations')
                        ->where('payment_id', $localPayment->payment_id)
                        ->whereNotNull('request_key')
                        ->whereIn('state', ['Submitting', 'Uncertain', 'Init', 'Processing', 'Unknown'])
                        ->where('amount', $amount)
                        ->first();

                    if ($pendingRequestOperation) {
                        DB::table('helloasso_refund_operations')
                            ->where('id', $pendingRequestOperation->id)
                            ->update([
                                'remote_refund_id' => $operationId,
                                'state' => $state,
                                'updated_at' => now(),
                            ]);
                    } else {
                        DB::table('helloasso_refund_operations')
                            ->where('payment_id', $localPayment->payment_id)
                            ->where('remote_refund_id', $operationId)
                            ->update(['state' => $state, 'updated_at' => now()]);
                    }
                }

                if (($operation['status'] ?? null) !== 'Processed') {
                    continue;
                }

                $refunded += $amount;

                if ($operationId === '') {
                    continue;
                }

                if (DB::table('helloasso_refund_operations')
                    ->where('payment_id', $localPayment->payment_id)
                    ->where('remote_refund_id', $operationId)
                    ->whereNotNull('request_key')
                    ->exists()) {
                    continue;
                }

                $recorded += (int) $this->importRefundOperation(
                    $orderId,
                    $order,
                    $localPayment,
                    $operationId,
                    $amount,
                );
            }

            if (($remote['state'] ?? null) === 'Refunded' && $refunded === 0) {
                $refunded = (int) ($remote['amount'] ?? 0);

                if ($refunded > 0) {
                    $recorded += (int) $this->importRefundOperation(
                        $orderId,
                        $order,
                        $localPayment,
                        'full-refund-confirmed',
                        $refunded,
                    );
                }
            }

            $localPayment->update([
                'state' => (string) ($remote['state'] ?? $localPayment->state),
                'refunded_amount' => $refunded,
                'remote_data' => $this->safePaymentData($remote),
            ]);
        }

        $this->settleFailedRefundRequests($orderId, $order);
        $recorded += $this->finalizeRefundRequests($orderId, $order);

        return $recorded;
    }

    public function requestRefund(
        int $orderId,
        Connection $connection,
        ?int $amountCents,
        string $comment,
        ?array $refundData = null,
    ): string {
        return Cache::lock('helloasso.refund-order.'.$orderId, 1800)->block(15, function () use ($orderId, $connection, $amountCents, $comment, $refundData) {
            return $this->requestRefundLocked($orderId, $connection, $amountCents, $comment, $refundData);
        });
    }

    protected function requestRefundLocked(
        int $orderId,
        Connection $connection,
        ?int $amountCents,
        string $comment,
        ?array $refundData,
    ): string {
        $order = $this->orders->findOrFail($orderId);
        $attempt = CheckoutAttempt::where('order_id', $orderId)->firstOrFail();
        abort_unless($connection->canManageAttempt($attempt), 409, 'HelloAsso connection does not match this payment.');

        $this->syncOrderLocked($orderId, $connection);

        if (DB::table('helloasso_refund_operations')
            ->where('order_id', $orderId)
            ->whereIn('state', ['Init', 'Processing', 'Unknown', 'Submitting', 'Uncertain'])
            ->exists()) {
            throw new HelloAssoApiException('A HelloAsso refund is already being processed or requires reconciliation.');
        }

        if (DB::table('helloasso_refund_requests')
            ->where('order_id', $orderId)
            ->whereIn('state', ['submitting', 'processing', 'uncertain'])
            ->whereNull('local_refund_id')
            ->exists()) {
            throw new HelloAssoApiException('A HelloAsso refund request is already being processed or requires reconciliation.');
        }

        $paid = HelloAssoPayment::where('checkout_attempt_id', $attempt->id)
            ->where(function ($query) use ($connection) {
                $query->where('state', 'Authorized');

                if ($connection->isSandbox()) {
                    $query->orWhere('state', 'AuthorizedPreprod');
                }
            })
            ->get();

        if ($paid->isEmpty()) {
            throw new HelloAssoApiException('No authorized HelloAsso payment is available to refund.');
        }

        $availableTotal = (int) $paid->sum(fn ($payment) => max(0, $payment->amount - $payment->refunded_amount));
        $remaining = $amountCents ?? $availableTotal;

        if ($remaining <= 0 || $remaining > $availableTotal) {
            throw new HelloAssoApiException('There is no refundable HelloAsso balance.');
        }

        if (! $refundData) {
            $refundData = [
                'items' => [],
                'shipping' => 0,
                'adjustment_refund' => core()->convertToBasePrice($remaining / 100, $order->order_currency_code),
                'adjustment_fee' => 0,
            ];
        }

        $summary = $this->refunds->getOrderItemsRefundSummary($refundData, $order->id);
        $baseRefund = (float) ($summary['grand_total']['price'] ?? 0);
        $orderCurrencyAmount = (int) round(core()->convertPrice($baseRefund, $order->order_currency_code) * 100);

        if ($baseRefund <= 0 || abs($orderCurrencyAmount - $remaining) > 1) {
            throw new HelloAssoApiException('The Bagisto refund details do not match the requested HelloAsso amount.');
        }

        if (! $this->refundPolicy->supports($connection->auth_mode, $remaining, $availableTotal)) {
            throw new RefundNotSupportedException(trans('helloasso::app.admin.association-partial-refund-unsupported'));
        }

        $remotelyAvailable = [];

        foreach ($paid as $payment) {
            $remote = $this->api->get($connection, '/payments/'.$payment->payment_id);

            if (
                (int) ($remote['id'] ?? 0) !== (int) $payment->payment_id
                || (int) ($remote['checkoutIntentId'] ?? 0) !== (int) $attempt->checkout_intent_id
                || (isset($remote['organizationSlug']) && $remote['organizationSlug'] !== $attempt->organization_slug)
                || (isset($remote['currency']) && strtoupper((string) $remote['currency']) !== $attempt->currency)
                || (int) ($remote['amount'] ?? 0) !== (int) $payment->amount
                || ! in_array($remote['state'] ?? '', ['Authorized', 'AuthorizedPreprod'], true)
            ) {
                throw new HelloAssoApiException('The remote payment does not match an authorized payment for this Bagisto checkout.');
            }

            $remoteOperations = (array) ($remote['refundOperations'] ?? []);
            $pendingRefund = collect($remoteOperations)->contains(fn (array $operation) => in_array(
                $operation['status'] ?? '',
                ['Unknown', 'Init', 'Processing'],
                true,
            ));

            if ($pendingRefund) {
                throw new HelloAssoApiException('A HelloAsso refund is already being processed.');
            }

            $refundedCents = (int) collect($remoteOperations)
                ->filter(fn (array $operation) => ($operation['status'] ?? '') === 'Processed')
                ->sum(fn (array $operation) => (int) ($operation['amount'] ?? 0));

            $remotelyAvailable[] = [
                'payment' => $payment,
                'available' => max(0, (int) $payment->amount - $refundedCents),
            ];
        }

        $remoteAvailableTotal = (int) collect($remotelyAvailable)->sum('available');

        if ($remaining > $remoteAvailableTotal) {
            throw new HelloAssoApiException('The remote refundable balance has changed. Synchronize this order and retry.');
        }

        $requestKey = (string) Str::uuid();
        DB::table('helloasso_refund_requests')->insert([
            'request_key' => $requestKey,
            'checkout_attempt_id' => $attempt->id,
            'order_id' => $orderId,
            'amount' => $remaining,
            'refund_data' => json_encode($refundData, JSON_THROW_ON_ERROR),
            'comment' => $comment,
            'state' => 'submitting',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $requestedCents = $remaining;
        $submittedCents = 0;

        foreach ($remotelyAvailable as $remotePayment) {
            $payment = $remotePayment['payment'];
            $available = $remotePayment['available'];

            if (! $available || ! $remaining) {
                continue;
            }

            $refundAmount = min($available, $remaining);
            $isFullPaymentRefund = $refundAmount === $available;
            $query = [
                'comment' => $comment,
                'sendRefundMail' => true,
                'cancelOrder' => $connection->cancel_future_terms_on_full_refund
                    && $requestedCents === $availableTotal
                    && $remaining === $refundAmount
                    && $refundAmount === $available,
            ];

            if (! $isFullPaymentRefund) {
                $query['amount'] = $refundAmount;
            }

            $placeholder = 'request-'.$requestKey.'-'.$payment->payment_id;
            $operationRecord = DB::table('helloasso_refund_operations')->insertGetId([
                'payment_id' => $payment->payment_id,
                'remote_refund_id' => $placeholder,
                'order_id' => $orderId,
                'amount' => $refundAmount,
                'state' => 'Submitting',
                'request_key' => $requestKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            try {
                $response = $this->api->request(
                    $connection,
                    'POST',
                    '/payments/'.$payment->payment_id.'/refund',
                    $query,
                );
            } catch (\Throwable $exception) {
                $definitiveRejection = $exception instanceof HelloAssoApiException
                    && $exception->statusCode >= 400
                    && $exception->statusCode < 500;

                if ($definitiveRejection) {
                    DB::table('helloasso_refund_operations')->where('id', $operationRecord)->delete();

                    if ($submittedCents > 0) {
                        $this->shrinkRefundRequestToConfirmedOperations($requestKey, $order, $submittedCents);
                    } else {
                        DB::table('helloasso_refund_requests')->where('request_key', $requestKey)->update([
                            'state' => 'failed',
                            'updated_at' => now(),
                        ]);
                    }
                } else {
                    DB::table('helloasso_refund_operations')->where('id', $operationRecord)->update([
                        'state' => 'Uncertain',
                        'updated_at' => now(),
                    ]);
                    $this->shrinkRefundRequestToConfirmedOperations($requestKey, $order, $submittedCents + $refundAmount);
                }

                throw $exception;
            }

            $state = (string) ($response['state'] ?? 'Unknown');
            $remoteRefundId = (string) ($response['id'] ?? '');

            if (in_array($state, ['Error', 'InternalError', 'Refused', 'Aborted', 'Canceled'], true)) {
                DB::table('helloasso_refund_operations')->where('id', $operationRecord)->update([
                    'remote_refund_id' => $remoteRefundId !== '' ? $remoteRefundId : $placeholder,
                    'state' => $state,
                    'updated_at' => now(),
                ]);

                if ($submittedCents > 0) {
                    $this->shrinkRefundRequestToConfirmedOperations($requestKey, $order, $submittedCents);
                } else {
                    DB::table('helloasso_refund_requests')->where('request_key', $requestKey)->update([
                        'state' => 'failed',
                        'updated_at' => now(),
                    ]);
                }

                throw new HelloAssoApiException('HelloAsso could not process this refund operation.');
            }

            if (! in_array($state, ['Init', 'Processing', 'Processed'], true)) {
                DB::table('helloasso_refund_operations')->where('id', $operationRecord)->update([
                    'state' => 'Uncertain',
                    'updated_at' => now(),
                ]);
                $this->shrinkRefundRequestToConfirmedOperations($requestKey, $order, $submittedCents + $refundAmount);

                throw new HelloAssoApiException('HelloAsso returned an unknown refund state that requires reconciliation.');
            }

            DB::table('helloasso_refund_operations')->where('id', $operationRecord)->update([
                'remote_refund_id' => $remoteRefundId !== '' ? $remoteRefundId : $placeholder,
                'state' => $state,
                'updated_at' => now(),
            ]);

            if ($remoteRefundId === '') {
                DB::table('helloasso_refund_operations')->where('id', $operationRecord)->update(['state' => 'Uncertain']);
                $this->shrinkRefundRequestToConfirmedOperations($requestKey, $order, $submittedCents + $refundAmount);

                throw new HelloAssoApiException('HelloAsso did not return the refund operation identifier.');
            }

            if ($state === 'Processed') {
                $this->syncOrderLocked($orderId, $connection);
            }

            $submittedCents += $refundAmount;
            $remaining -= $refundAmount;
        }

        DB::table('helloasso_refund_requests')->where('request_key', $requestKey)->update([
            'state' => $submittedCents === $requestedCents ? 'processing' : 'uncertain',
            'updated_at' => now(),
        ]);

        $this->syncOrderLocked($orderId, $connection);

        return 'request_sent';
    }

    public function cancelFuturePayments(int $orderId, Connection $connection): string
    {
        return Cache::lock('helloasso.refund-order.'.$orderId, 1800)->block(15, function () use ($orderId, $connection) {
            return $this->cancelFuturePaymentsLocked($orderId, $connection);
        });
    }

    protected function cancelFuturePaymentsLocked(int $orderId, Connection $connection): string
    {
        $order = $this->orders->findOrFail($orderId);
        $attempt = CheckoutAttempt::where('order_id', $orderId)->firstOrFail();
        abort_unless($connection->canManageAttempt($attempt), 409, 'HelloAsso connection does not match this payment.');

        if ($attempt->installment_count < 2 || ! $attempt->remote_order_id) {
            throw new HelloAssoApiException('This HelloAsso order has no future installments to cancel.');
        }

        if ($attempt->future_payments_canceled) {
            return 'remote_future_payments_canceled';
        }

        if (DB::table('helloasso_refund_operations')
            ->where('order_id', $orderId)
            ->whereIn('state', ['Init', 'Processing', 'Unknown', 'Submitting', 'Uncertain'])
            ->exists()) {
            throw new HelloAssoApiException('A HelloAsso refund is already being processed or requires reconciliation.');
        }

        $remoteOrder = $this->api->get($connection, '/orders/'.$attempt->remote_order_id);

        if (
            (int) ($remoteOrder['id'] ?? 0) !== (int) $attempt->remote_order_id
            || (isset($remoteOrder['organizationSlug']) && $remoteOrder['organizationSlug'] !== $attempt->organization_slug)
            || (isset($remoteOrder['checkoutIntentId']) && (int) $remoteOrder['checkoutIntentId'] !== (int) $attempt->checkout_intent_id)
        ) {
            throw new HelloAssoApiException('The remote order does not match this Bagisto checkout.');
        }

        $this->api->post($connection, '/orders/'.$attempt->remote_order_id.'/cancel', []);
        $attempt->update(['future_payments_canceled' => true]);

        if (! $order->canCancel(true)) {
            return 'remote_future_payments_canceled';
        }

        try {
            if ($this->guard->run(fn () => $this->orders->cancel($order, true))) {
                return 'remote_and_local_canceled';
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        return 'remote_canceled_local_action_failed';
    }

    protected function shrinkRefundRequestToConfirmedOperations(
        string $requestKey,
        object $order,
        int $expectedCents,
    ): void {
        if ($expectedCents <= 0) {
            DB::table('helloasso_refund_requests')->where('request_key', $requestKey)->update([
                'amount' => 0,
                'state' => 'failed',
                'updated_at' => now(),
            ]);

            return;
        }

        $baseAmount = core()->convertToBasePrice($expectedCents / 100, $order->order_currency_code);
        $data = [
            'items' => [],
            'shipping' => 0,
            'adjustment_refund' => $baseAmount,
            'adjustment_fee' => 0,
        ];

        DB::table('helloasso_refund_requests')->where('request_key', $requestKey)->update([
            'amount' => $expectedCents,
            'refund_data' => json_encode($data, JSON_THROW_ON_ERROR),
            'state' => 'uncertain',
            'updated_at' => now(),
        ]);
    }

    protected function recordLocalRefund(object $order, float $amount): object
    {
        return Cache::lock('helloasso.refund.'.$order->id, 30)->block(10, function () use ($order, $amount) {
            $order = $this->orders->find($order->id);
            $remaining = max(0, (float) $order->base_grand_total_invoiced - (float) $order->base_grand_total_refunded);

            if ($amount <= 0 || $amount < 0.01 || $amount > $remaining + 0.005) {
                throw new HelloAssoApiException('The processed HelloAsso refund does not match the remaining Bagisto balance.');
            }

            $amount = min($amount, $remaining);

            $isFull = $amount >= $remaining;
            $data = [
                'items' => [],
                'shipping' => 0,
                'adjustment_refund' => $amount,
                'adjustment_fee' => 0,
            ];

            if ($isFull) {
                $items = $order->items
                    ->filter(fn ($item) => $item->qty_to_refund > 0)
                    ->mapWithKeys(fn ($item) => [$item->id => $item->qty_to_refund])
                    ->all();
                $shipping = max(0, (float) $order->base_shipping_invoiced - (float) $order->base_shipping_refunded);
                $data = [
                    'items' => $items,
                    'shipping' => $shipping,
                    'adjustment_refund' => $amount,
                    'adjustment_fee' => 0,
                ];

                $summary = $this->refunds->getOrderItemsRefundSummary($data, $order->id);
                $itemsTotal = $summary['grand_total']['price'] ?? 0;
                $data['adjustment_refund'] = max(0, $amount - $itemsTotal);
                $data['adjustment_fee'] = max(0, $itemsTotal - $amount);
            }

            return $this->guard->run(fn () => $this->refunds->create([
                'order_id' => $order->id,
                'refund' => $data,
            ]));
        });
    }

    protected function finalizeRefundRequests(int $orderId, object $order): int
    {
        $recorded = 0;
        $requests = DB::table('helloasso_refund_requests')
            ->where('order_id', $orderId)
            ->whereIn('state', ['processing', 'uncertain'])
            ->whereNull('local_refund_id')
            ->get();

        foreach ($requests as $refundRequest) {
            $operations = DB::table('helloasso_refund_operations')
                ->where('request_key', $refundRequest->request_key)
                ->get();
            $pending = $operations->contains(fn ($operation) => in_array(
                $operation->state,
                ['Submitting', 'Uncertain', 'Init', 'Processing', 'Unknown'],
                true,
            ));
            $processedAmount = (int) $operations
                ->where('state', 'Processed')
                ->sum('amount');

            if (
                $operations->isEmpty()
                || $pending
                || $processedAmount !== (int) $refundRequest->amount
            ) {
                continue;
            }

            $created = Cache::lock('helloasso.refund-request.'.$refundRequest->request_key, 120)
                ->block(10, function () use ($refundRequest, $order) {
                    return DB::transaction(function () use ($refundRequest, $order) {
                        $current = DB::table('helloasso_refund_requests')
                            ->where('id', $refundRequest->id)
                            ->lockForUpdate()
                            ->first();

                        if (! $current || $current->local_refund_id) {
                            return false;
                        }

                        $data = json_decode($current->refund_data, true, 512, JSON_THROW_ON_ERROR);
                        $summary = $this->refunds->getOrderItemsRefundSummary($data, $order->id);
                        $baseAmount = (float) ($summary['grand_total']['price'] ?? 0);
                        $expected = (int) round(core()->convertPrice($baseAmount, $order->order_currency_code) * 100);

                        if ($baseAmount <= 0 || abs($expected - (int) $current->amount) > 1) {
                            throw new HelloAssoApiException('The confirmed remote refund does not match the stored Bagisto refund details.');
                        }

                        $refund = $this->guard->run(fn () => $this->refunds->create([
                            'order_id' => $order->id,
                            'refund' => $data,
                        ]));

                        DB::table('helloasso_refund_requests')
                            ->where('id', $current->id)
                            ->update([
                                'state' => 'processed',
                                'local_refund_id' => $refund->id,
                                'updated_at' => now(),
                            ]);

                        DB::table('helloasso_refund_operations')
                            ->where('request_key', $current->request_key)
                            ->update(['local_refund_id' => $refund->id, 'updated_at' => now()]);

                        return true;
                    });
                });

            $recorded += (int) $created;
        }

        return $recorded;
    }

    protected function settleFailedRefundRequests(int $orderId, object $order): void
    {
        $requests = DB::table('helloasso_refund_requests')
            ->where('order_id', $orderId)
            ->whereIn('state', ['processing', 'uncertain'])
            ->whereNull('local_refund_id')
            ->get();

        foreach ($requests as $request) {
            $operations = DB::table('helloasso_refund_operations')
                ->where('request_key', $request->request_key)
                ->get();

            $hasFailure = $operations->contains(fn ($operation) => in_array(
                $operation->state,
                ['Error', 'InternalError', 'Refused', 'Aborted', 'Canceled'],
                true,
            ));
            $hasPending = $operations->contains(fn ($operation) => in_array(
                $operation->state,
                ['Submitting', 'Uncertain', 'Init', 'Processing', 'Unknown'],
                true,
            ));

            if (! $hasFailure || $hasPending) {
                continue;
            }

            $processedCents = (int) $operations->where('state', 'Processed')->sum('amount');

            if ($processedCents > 0) {
                $this->shrinkRefundRequestToConfirmedOperations($request->request_key, $order, $processedCents);
            } else {
                DB::table('helloasso_refund_requests')->where('id', $request->id)->update([
                    'state' => 'failed',
                    'updated_at' => now(),
                ]);
            }
        }
    }

    protected function safePaymentData(array $payment): array
    {
        $refundOperations = array_map(fn (array $operation) => array_filter([
            'id' => $operation['id'] ?? null,
            'amount' => $operation['amount'] ?? null,
            'status' => $operation['status'] ?? null,
        ], fn ($value) => $value !== null), $payment['refundOperations'] ?? []);

        return array_filter([
            'id' => $payment['id'] ?? null,
            'amount' => $payment['amount'] ?? null,
            'state' => $payment['state'] ?? null,
            'paymentMeans' => $payment['paymentMeans'] ?? null,
            'installmentNumber' => $payment['installmentNumber'] ?? null,
            'checkoutIntentId' => $payment['checkoutIntentId'] ?? null,
            'cashOutState' => $payment['cashOutState'] ?? null,
            'refundOperations' => $refundOperations,
            'paymentReceiptUrl' => $payment['paymentReceiptUrl'] ?? null,
            'date' => $payment['date'] ?? null,
        ], fn ($value) => $value !== null);
    }

    protected function importRefundOperation(
        int $orderId,
        object $order,
        HelloAssoPayment $payment,
        string $operationId,
        int $amount,
    ): bool {
        if ($operationId === '' || $amount <= 0) {
            return false;
        }

        return Cache::lock('helloasso.refund-operation.'.$payment->payment_id.'.'.$operationId, 120)
            ->block(10, function () use ($payment, $operationId, $orderId, $order, $amount) {
                $query = DB::table('helloasso_refund_operations')
                    ->where('payment_id', $payment->payment_id)
                    ->where('remote_refund_id', $operationId);
                $local = $query->first();

                if ($local?->local_refund_id) {
                    return false;
                }

                DB::table('helloasso_refund_operations')->updateOrInsert([
                    'payment_id' => $payment->payment_id,
                    'remote_refund_id' => $operationId,
                ], [
                    'order_id' => $orderId,
                    'amount' => $amount,
                    'state' => 'Processed',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);

                $baseAmount = core()->convertToBasePrice($amount / 100, $order->order_currency_code);
                $refund = $this->recordLocalRefund($order, $baseAmount);

                DB::table('helloasso_refund_operations')
                    ->where('payment_id', $payment->payment_id)
                    ->where('remote_refund_id', $operationId)
                    ->update(['local_refund_id' => $refund->id, 'updated_at' => now()]);

                return true;
            });
    }
}
