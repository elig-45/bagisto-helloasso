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

    public function refund(Request $request, Order $order, RefundSyncService $refunds): RedirectResponse
    {
        abort_unless($order->payment?->method === 'helloasso', 404);

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:'.(float) $order->grand_total],
            'comment' => ['required', 'string', 'max:500'],
        ]);

        $connection = Connection::globalConnection();
        abort_unless($connection, 404);
        $amount = isset($data['amount']) ? (int) round((float) $data['amount'] * 100) : null;

        try {
            $refunds->requestRefund($order->id, $connection, $amount, $data['comment']);

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
