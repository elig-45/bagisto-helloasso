<?php

namespace Elig45\BagistoHelloAsso\Services;

use Elig45\BagistoHelloAsso\Models\CheckoutAttempt;
use Elig45\BagistoHelloAsso\Models\Connection;
use Elig45\BagistoHelloAsso\Models\HelloAssoPayment;
use Illuminate\Support\Facades\Cache;
use Webkul\Checkout\Facades\Cart;
use Webkul\Checkout\Repositories\CartRepository;
use Webkul\Sales\Models\Invoice;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Repositories\InvoiceRepository;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Sales\Repositories\OrderTransactionRepository;

class OrderSettlementService
{
    public function __construct(
        protected HelloAssoApiClient $api,
        protected CartRepository $cartRepository,
        protected OrderRepository $orders,
        protected InvoiceRepository $invoices,
        protected OrderTransactionRepository $transactions,
    ) {}

    public function reconcile(CheckoutAttempt $attempt, Connection $connection): string
    {
        if (
            $attempt->organization_slug !== $connection->organization_slug
            || ! $attempt->checkout_intent_id
        ) {
            return 'invalid_attempt';
        }

        return Cache::lock('helloasso.attempt.'.$attempt->id, 600)->block(10, function () use ($attempt, $connection) {
            $attempt->refresh();

            return $this->reconcileLocked($attempt, $connection);
        });
    }

    protected function reconcileLocked(CheckoutAttempt $attempt, Connection $connection): string
    {
        if (
            $attempt->organization_slug !== $connection->organization_slug
            || ! $attempt->checkout_intent_id
        ) {
            return 'invalid_attempt';
        }

        $intent = $this->api->get(
            $connection,
            '/organizations/'.rawurlencode($connection->organization_slug).'/checkout-intents/'.$attempt->checkout_intent_id,
        );

        if (! $this->intentMatchesAttempt($attempt, $intent)) {
            $attempt->update(['status' => 'metadata_mismatch', 'last_checked_at' => now()]);

            return 'metadata_mismatch';
        }

        $remoteOrder = $intent['order'] ?? null;

        if (! is_array($remoteOrder)) {
            $attempt->update(['status' => 'pending', 'last_checked_at' => now()]);

            return 'pending';
        }

        if (
            ! $this->orderTotalMatches($remoteOrder, $attempt->total_amount)
            || (isset($remoteOrder['organizationSlug']) && $remoteOrder['organizationSlug'] !== $attempt->organization_slug)
            || (isset($remoteOrder['currency']) && strtoupper((string) $remoteOrder['currency']) !== $attempt->currency)
        ) {
            $attempt->update([
                'status' => 'amount_mismatch',
                'remote_order' => $this->safeOrderData($remoteOrder),
                'last_checked_at' => now(),
            ]);

            return 'amount_mismatch';
        }

        $remotePayments = $this->fetchPayments($attempt, $connection, $remoteOrder);
        $authorized = collect($remotePayments)->filter(fn (array $payment) => $this->isAuthorized($payment['state'], $connection))->values();
        $authorizedCents = (int) $authorized->sum('amount');
        $hasAuthorized = $authorized->isNotEmpty();
        $fullyPaid = $authorizedCents >= $attempt->total_amount;
        $hasSepaPayment = $this->hasSepaPayment($remotePayments);
        $hasPendingSepaPayment = $this->hasPendingSepaPayment($remotePayments);
        $remoteOrderId = (int) ($remoteOrder['id'] ?? 0) ?: null;

        $attempt->forceFill([
            'remote_order_id' => $remoteOrderId,
            'remote_order' => $this->safeOrderData($remoteOrder),
            'status' => $hasAuthorized ? 'partially_paid' : 'pending',
            'last_checked_at' => now(),
        ])->save();

        $policy = $connection->order_policy;
        $createOrder = $this->shouldCreateOrder($policy, $hasAuthorized, $fullyPaid, $hasPendingSepaPayment);

        if (! $createOrder) {
            return $hasAuthorized ? 'payment_recorded' : 'pending';
        }

        return Cache::lock('helloasso.order.'.$attempt->cart_id, 30)->block(10, function () use (
            $attempt,
            $connection,
            $remotePayments,
            $authorizedCents,
            $fullyPaid,
            $remoteOrderId,
        ) {
            $order = $this->orders->findOneWhere(['cart_id' => $attempt->cart_id]);

            if (! $order) {
                $snapshot = $attempt->cart_snapshot;

                if (! is_array($snapshot) || empty($snapshot['items'])) {
                    $attempt->update(['status' => 'snapshot_missing']);

                    return 'snapshot_missing';
                }

                $snapshot['payment']['additional'] = [
                    'helloasso_checkout_intent_id' => $attempt->checkout_intent_id,
                    'helloasso_order_id' => $remoteOrderId,
                    'helloasso_installment_count' => $attempt->installment_count,
                ];

                $order = $this->orders->create($snapshot);
                $this->orders->updateOrderStatus($order, Order::STATUS_PENDING_PAYMENT);

                $cart = $this->cartRepository->find($attempt->cart_id);

                if ($cart) {
                    Cart::setCart($cart);
                    Cart::deActivateCart();
                }
            }

            $attempt->update([
                'order_id' => $order->id,
                'status' => $fullyPaid ? 'paid' : ($authorizedCents > 0 ? 'partially_paid' : 'pending'),
                'cart_snapshot' => null,
            ]);

            $invoice = $order->invoices()->first();

            if (! $invoice && $order->canInvoice()) {
                $items = $order->items
                    ->filter(fn ($item) => $item->qty_to_invoice > 0)
                    ->mapWithKeys(fn ($item) => [$item->id => $item->qty_to_invoice])
                    ->all();

                if ($items) {
                    $invoice = $this->invoices->create(
                        ['order_id' => $order->id, 'invoice' => ['items' => $items]],
                        $fullyPaid ? Invoice::STATUS_PAID : Invoice::STATUS_PENDING_PAYMENT,
                    );
                }
            } elseif ($invoice && $fullyPaid && $invoice->state !== Invoice::STATUS_PAID) {
                $this->invoices->updateState($invoice, Invoice::STATUS_PAID);
            }

            foreach ($remotePayments as $payment) {
                $local = HelloAssoPayment::updateOrCreate(
                    [
                        'checkout_attempt_id' => $attempt->id,
                        'payment_id' => $payment['id'],
                    ],
                    [
                        'order_id' => $order->id,
                        'installment_number' => $payment['installmentNumber'] ?? null,
                        'amount' => $payment['amount'],
                        'state' => $payment['state'],
                        'payment_means' => $payment['paymentMeans'] ?? null,
                        'remote_data' => $payment,
                    ],
                );

                if ($invoice) {
                    $this->saveTransaction($order->id, $invoice->id, $local);
                }
            }

            $ready = $this->isOrderReady(
                $connection,
                $attempt,
                $remotePayments,
                $authorizedCents,
                $fullyPaid,
            );

            $this->orders->updateOrderStatus(
                $order,
                $ready ? Order::STATUS_PROCESSING : Order::STATUS_PENDING_PAYMENT,
            );

            return $ready ? 'order_ready' : 'order_pending_payment';
        });
    }

    protected function fetchPayments(CheckoutAttempt $attempt, Connection $connection, array $remoteOrder): array
    {
        $ids = [];

        foreach (($remoteOrder['items'] ?? []) as $item) {
            foreach (($item['payments'] ?? []) as $payment) {
                if (! empty($payment['id'])) {
                    $ids[(string) $payment['id']] = (int) $payment['id'];
                }
            }
        }

        foreach (($remoteOrder['payments'] ?? []) as $payment) {
            if (! empty($payment['id'])) {
                $ids[(string) $payment['id']] = (int) $payment['id'];
            }
        }

        $payments = [];

        foreach ($ids as $id) {
            $payment = $this->api->get($connection, '/payments/'.$id);

            $payments[] = [
                'id' => (int) ($payment['id'] ?? $id),
                'amount' => (int) ($payment['amount'] ?? 0),
                'state' => (string) ($payment['state'] ?? 'Unknown'),
                'paymentMeans' => $payment['paymentMeans'] ?? null,
                'installmentNumber' => $payment['installmentNumber'] ?? null,
                'refundOperations' => $payment['refundOperations'] ?? [],
                'paymentReceiptUrl' => $payment['paymentReceiptUrl'] ?? null,
                'date' => $payment['date'] ?? null,
            ];
        }

        return $payments;
    }

    protected function saveTransaction(int $orderId, int $invoiceId, HelloAssoPayment $payment): void
    {
        $transaction = $this->transactions->findOneWhere([
            'transaction_id' => (string) $payment->payment_id,
            'payment_method' => 'helloasso',
        ]);

        $data = [
            'transaction_id' => (string) $payment->payment_id,
            'status' => $payment->state,
            'type' => 'helloasso',
            'payment_method' => 'helloasso',
            'order_id' => $orderId,
            'invoice_id' => $invoiceId,
            'amount' => $payment->amount / 100,
            'data' => json_encode($payment->remote_data),
        ];

        if ($transaction) {
            $this->transactions->update($data, $transaction->id);
        } else {
            $this->transactions->create($data);
        }
    }

    protected function isAuthorized(string $state, Connection $connection): bool
    {
        return $state === 'Authorized'
            || ($connection->isSandbox() && $state === 'AuthorizedPreprod');
    }

    protected function hasSepaPayment(array $payments): bool
    {
        foreach ($payments as $payment) {
            if (strtoupper((string) ($payment['paymentMeans'] ?? '')) === 'SEPA') {
                return true;
            }
        }

        return false;
    }

    protected function hasPendingSepaPayment(array $payments): bool
    {
        foreach ($payments as $payment) {
            if (
                strtoupper((string) ($payment['paymentMeans'] ?? '')) === 'SEPA'
                && in_array($payment['state'] ?? '', [
                    'Init',
                    'Pending',
                    'Registered',
                    'Waiting',
                    'WaitingBankValidation',
                    'WaitingBankWithdraw',
                ], true)
            ) {
                return true;
            }
        }

        return false;
    }

    protected function isOrderReady(
        Connection $connection,
        CheckoutAttempt $attempt,
        array $payments,
        int $authorizedCents,
        bool $fullyPaid,
    ): bool {
        if ($attempt->installment_count > 1 && $this->hasSepaPayment($payments)) {
            return $fullyPaid;
        }

        return match ($connection->order_policy) {
            'full_amount' => $fullyPaid,
            'verified_return', 'first_authorized' => $authorizedCents > 0,
            default => $authorizedCents > 0,
        };
    }

    protected function shouldCreateOrder(
        string $policy,
        bool $hasAuthorized,
        bool $fullyPaid,
        bool $hasPendingSepaPayment,
    ): bool {
        return match ($policy) {
            'verified_return' => true,
            'first_authorized' => $hasAuthorized || $hasPendingSepaPayment,
            'full_amount' => $fullyPaid || $hasPendingSepaPayment,
            default => $hasAuthorized || $hasPendingSepaPayment,
        };
    }

    protected function orderTotalMatches(array $order, int $expectedCents): bool
    {
        $remoteTotal = data_get($order, 'amount.total')
            ?? data_get($order, 'amount.topay')
            ?? data_get($order, 'amount');

        return is_numeric($remoteTotal) && (int) $remoteTotal === $expectedCents;
    }

    protected function decodeMetadata(mixed $metadata): array
    {
        if (is_string($metadata)) {
            return (array) json_decode($metadata, true);
        }

        return is_array($metadata) ? $metadata : [];
    }

    protected function intentMatchesAttempt(CheckoutAttempt $attempt, array $intent): bool
    {
        if (! isset($intent['id']) || (int) $intent['id'] !== (int) $attempt->checkout_intent_id) {
            return false;
        }

        $metadata = $this->decodeMetadata($intent['metadata'] ?? []);
        $expected = [
            'bagisto_ref' => (string) $attempt->reference,
            'cart_id' => (string) $attempt->cart_id,
            'channel_code' => (string) $attempt->channel_code,
            'organization_slug' => (string) $attempt->organization_slug,
            'currency' => (string) $attempt->currency,
        ];

        foreach ($expected as $key => $value) {
            if ((string) ($metadata[$key] ?? '') !== $value) {
                return false;
            }
        }

        return true;
    }

    protected function safeOrderData(array $order): array
    {
        return array_filter([
            'id' => $order['id'] ?? null,
            'amount' => isset($order['amount']) ? array_intersect_key((array) $order['amount'], array_flip(['total', 'vat', 'discount'])) : null,
            'state' => $order['state'] ?? null,
            'organizationSlug' => $order['organizationSlug'] ?? null,
            'checkoutIntentId' => $order['checkoutIntentId'] ?? null,
            'formType' => $order['formType'] ?? null,
            'date' => $order['date'] ?? null,
        ], fn ($value) => $value !== null);
    }
}
