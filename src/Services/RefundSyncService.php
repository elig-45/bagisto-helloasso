<?php

namespace Elig45\BagistoHelloAsso\Services;

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

                Cache::lock('helloasso.refund-operation.'.$localPayment->payment_id.'.'.$operationId, 120)
                    ->block(10, function () use ($localPayment, $operationId, $orderId, $order, $amount, &$recorded) {
                        $query = DB::table('helloasso_refund_operations')
                            ->where('payment_id', $localPayment->payment_id)
                            ->where('remote_refund_id', $operationId);
                        $local = $query->first();

                        if ($local?->local_refund_id) {
                            return;
                        }

                        DB::table('helloasso_refund_operations')->updateOrInsert([
                            'payment_id' => $localPayment->payment_id,
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
                            ->where('payment_id', $localPayment->payment_id)
                            ->where('remote_refund_id', $operationId)
                            ->update(['local_refund_id' => $refund->id, 'updated_at' => now()]);

                        $recorded++;
                    });
            }

            if (($remote['state'] ?? null) === 'Refunded' && $refunded === 0) {
                $refunded = (int) ($remote['amount'] ?? 0);
            }

            $localPayment->update([
                'state' => (string) ($remote['state'] ?? $localPayment->state),
                'refunded_amount' => $refunded,
                'remote_data' => $remote,
            ]);
        }

        return $recorded;
    }

    public function requestRefund(int $orderId, Connection $connection, ?int $amountCents, string $comment): string
    {
        $order = $this->orders->findOrFail($orderId);
        $attempt = CheckoutAttempt::where('order_id', $orderId)->firstOrFail();

        if ($connection->auth_mode === 'association') {
            throw new HelloAssoApiException('API refunds require a partner authorization. Use the HelloAsso back office.');
        }

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

        return $remaining > 0 ? 'partial_request_sent' : 'request_sent';
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

        if ($order->canCancel(true)) {
            $this->guard->run(fn () => $this->orders->cancel($order, true));

            return 'remote_and_local_canceled';
        }

        return 'remote_future_payments_canceled';
    }

    protected function recordLocalRefund(object $order, float $amount): object
    {
        return Cache::lock('helloasso.refund.'.$order->id, 30)->block(10, function () use ($order, $amount) {
            $order = $this->orders->find($order->id);
            $remaining = max(0, (float) $order->base_grand_total_invoiced - (float) $order->base_grand_total_refunded);
            $amount = min($amount, $remaining);

            if ($amount <= 0 || $amount < 0.01) {
                throw new HelloAssoApiException('The processed HelloAsso refund does not match the remaining Bagisto balance.');
            }

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
}
