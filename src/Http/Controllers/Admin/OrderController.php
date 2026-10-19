<?php

namespace Elig45\BagistoHelloAsso\Http\Controllers\Admin;

use Elig45\BagistoHelloAsso\Exceptions\RefundNotSupportedException;
use Elig45\BagistoHelloAsso\Models\CheckoutAttempt;
use Elig45\BagistoHelloAsso\Models\Connection;
use Elig45\BagistoHelloAsso\Services\OrderSettlementService;
use Elig45\BagistoHelloAsso\Services\RefundSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Repositories\RefundRepository;

class OrderController extends Controller
{
    public function sync(
        Order $order,
        OrderSettlementService $settlement,
        RefundSyncService $refunds,
    ): RedirectResponse {
        abort_unless($order->payment?->method === 'helloasso', 404);
        $connection = Connection::globalConnection();
        abort_unless($connection, 404);

        try {
            $attempt = CheckoutAttempt::where('order_id', $order->id)->firstOrFail();
            abort_unless($connection->canManageAttempt($attempt), 409);
            $settlement->reconcile($attempt, $connection);
            $refundCount = $refunds->syncOrder($order->id, $connection);

            return redirect()->route('admin.sales.orders.view', $order->id)
                ->with('success', trans('helloasso::app.admin.sync-complete', ['count' => $refundCount]));
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('admin.sales.orders.view', $order->id)
                ->withErrors(['helloasso' => trans('helloasso::app.admin.sync-failed')]);
        }
    }

    public function refund(
        Request $request,
        Order $order,
        RefundSyncService $refunds,
        RefundRepository $refundRepository,
    ): RedirectResponse {
        abort_unless($order->payment?->method === 'helloasso', 404);

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:'.(float) $order->grand_total],
            'comment' => ['required', 'string', 'max:500'],
            'refund' => ['sometimes', 'array'],
            'refund.items' => ['sometimes', 'array'],
            'refund.items.*' => ['required', 'numeric', 'min:0'],
            'refund.shipping' => ['sometimes', 'numeric', 'min:0', 'max:'.max(0, (float) $order->base_shipping_invoiced - (float) $order->base_shipping_refunded)],
            'refund.adjustment_refund' => ['sometimes', 'numeric', 'min:0'],
            'refund.adjustment_fee' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $connection = Connection::globalConnection();
        abort_unless($connection, 404);
        $attempt = CheckoutAttempt::where('order_id', $order->id)->firstOrFail();
        abort_unless($connection->canManageAttempt($attempt), 409);
        $amount = isset($data['amount']) ? (int) round((float) $data['amount'] * 100) : null;

        if (isset($data['refund'])) {
            $refundData = array_replace([
                'items' => [],
                'shipping' => 0,
                'adjustment_refund' => 0,
                'adjustment_fee' => 0,
            ], $data['refund']);

            try {
                $summary = $refundRepository->getOrderItemsRefundSummary($refundData, $order->id);
            } catch (\Throwable $exception) {
                return redirect()->route('admin.sales.orders.view', $order->id)
                    ->withErrors(['helloasso' => $exception->getMessage()]);
            }

            $orderItemIds = $order->items->pluck('id')->map(fn ($id) => (string) $id)->all();

            if (array_diff(array_map('strval', array_keys($refundData['items'])), $orderItemIds)) {
                return redirect()->route('admin.sales.orders.view', $order->id)
                    ->withErrors(['helloasso' => trans('admin::app.sales.refunds.create.invalid-qty')]);
            }

            $baseAmount = (float) ($summary['grand_total']['price'] ?? 0);
            $maxRefundAmount = max(0, (float) $order->base_grand_total_invoiced - (float) $order->base_grand_total_refunded);

            if ($baseAmount <= 0) {
                return redirect()->route('admin.sales.orders.view', $order->id)
                    ->withErrors(['helloasso' => trans('admin::app.sales.refunds.create.invalid-refund-amount-error')]);
            }

            if ($baseAmount > $maxRefundAmount + 0.005) {
                return redirect()->route('admin.sales.orders.view', $order->id)
                    ->withErrors(['helloasso' => trans('admin::app.sales.refunds.create.refund-limit-error', [
                        'amount' => core()->formatBasePrice($maxRefundAmount),
                    ])]);
            }

            $amount = (int) round(core()->convertPrice($baseAmount, $order->order_currency_code) * 100);
        }

        try {
            $refunds->requestRefund($order->id, $connection, $amount, $data['comment'], $data['refund'] ?? null);

            return redirect()->route('admin.sales.orders.view', $order->id)
                ->with('success', trans('helloasso::app.admin.refund-requested'));
        } catch (RefundNotSupportedException $exception) {
            return redirect()->route('admin.sales.orders.view', $order->id)
                ->withErrors(['helloasso' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('admin.sales.orders.view', $order->id)
                ->withErrors(['helloasso' => trans('helloasso::app.admin.refund-check-remote')]);
        }
    }

    public function cancelFuturePayments(Order $order, RefundSyncService $refunds): RedirectResponse
    {
        abort_unless($order->payment?->method === 'helloasso', 404);
        $connection = Connection::globalConnection();
        abort_unless($connection, 404);
        $attempt = CheckoutAttempt::where('order_id', $order->id)->firstOrFail();
        abort_unless($connection->canManageAttempt($attempt), 409);

        try {
            $status = $refunds->cancelFuturePayments($order->id, $connection);
            $message = match ($status) {
                'remote_and_local_canceled' => trans('helloasso::app.admin.future-payments-and-order-canceled'),
                'remote_canceled_local_action_failed' => trans('helloasso::app.admin.future-payments-canceled-review-order'),
                default => trans('helloasso::app.admin.future-payments-canceled'),
            };

            return redirect()->route('admin.sales.orders.view', $order->id)
                ->with('success', $message);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('admin.sales.orders.view', $order->id)
                ->withErrors(['helloasso' => trans('helloasso::app.admin.cancel-future-payments-manual')]);
        }
    }
}
