<?php

namespace Elig45\BagistoHelloAsso\Services;

use Elig45\BagistoHelloAsso\Exceptions\RefundNotSupportedException;
use Elig45\BagistoHelloAsso\Models\CheckoutAttempt;
use Elig45\BagistoHelloAsso\Models\Connection;
use Elig45\BagistoHelloAsso\Models\HelloAssoPayment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        $order = $this->orders->findOrFail($orderId);
        $attempt = CheckoutAttempt::where('order_id', $orderId)->firstOrFail();
        $recorded = 0;

        foreach (HelloAssoPayment::where('checkout_attempt_id', $attempt->id)->get() as $localPayment) {
            $remote = $this->api->get($connection, '/payments/'.$localPayment->payment_id);
            $operations = $remote['refundOperations'] ?? [];
            $refunded = 0;

            foreach ($operations as $operation) {
                if (($operation['status'] ?? null) !== 'Processed') {
                    continue;
                }

                $amount = (int) ($operation['amount'] ?? 0);
                $refunded += $amount;
                $operationId = (string) ($operation['id'] ?? '');

                if ($operationId === '') {
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

        return $recorded;
    }

    public function requestRefund(int $orderId, Connection $connection, ?int $amountCents, string $comment): string
    {
        $order = $this->orders->findOrFail($orderId);
        $attempt = CheckoutAttempt::where('order_id', $orderId)->firstOrFail();

        $paid = HelloAssoPayment::where('checkout_attempt_id', $attempt->id)
            ->whereIn('state', ['Authorized', 'AuthorizedPreprod'])
            ->get();

        if ($paid->isEmpty()) {
            throw new HelloAssoApiException('No authorized HelloAsso payment is available to refund.');
        }

        $availableTotal = (int) $paid->sum(fn ($payment) => max(0, $payment->amount - $payment->refunded_amount));
        $remaining = $amountCents ?? $availableTotal;

        if ($remaining <= 0 || $remaining > $availableTotal) {
            throw new HelloAssoApiException('There is no refundable HelloAsso balance.');
        }

        if (! $this->refundPolicy->supports($connection->auth_mode, $remaining, $availableTotal)) {
            throw new RefundNotSupportedException(trans('helloasso::app.admin.association-partial-refund-unsupported'));
        }

        foreach ($paid as $payment) {
            $available = max(0, $payment->amount - $payment->refunded_amount);

            if (! $available || ! $remaining) {
                continue;
            }

            $refundAmount = min($available, $remaining);
            $isFullPaymentRefund = $refundAmount === $available;
            $query = [
                'comment' => $comment,
                'sendRefundMail' => true,
                'cancelOrder' => $connection->cancel_future_terms_on_full_refund
                    && $remaining === $refundAmount
                    && $refundAmount === $available,
            ];

            if (! $isFullPaymentRefund) {
                $query['amount'] = $refundAmount;
            }

            $response = $this->api->request(
                $connection,
                'POST',
                '/payments/'.$payment->payment_id.'/refund',
                $query,
            );

            $state = (string) ($response['state'] ?? 'Unknown');

            if ($state === 'Processed') {
                $this->syncOrder($orderId, $connection);
            }

            $remaining -= $refundAmount;
        }

        return 'request_sent';
    }

    public function cancelFuturePayments(int $orderId, Connection $connection): string
    {
        $order = $this->orders->findOrFail($orderId);
        $attempt = CheckoutAttempt::where('order_id', $orderId)->firstOrFail();

        if ($attempt->installment_count < 2 || ! $attempt->remote_order_id) {
            throw new HelloAssoApiException('This HelloAsso order has no future installments to cancel.');
        }

        $this->api->post($connection, '/orders/'.$attempt->remote_order_id.'/cancel', []);
        $attempt->update(['status' => 'canceled']);

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
