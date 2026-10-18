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
use Webkul\Sales\Repositories\OrderRepository;

class OrderController extends Controller
{
    public function __construct(protected OrderRepository $orders) {}

    public function sync(
        int $order,
        OrderSettlementService $settlement,
        RefundSyncService $refunds,
    ): RedirectResponse {
        $orderModel = $this->orders->findOrFail($order);
        abort_unless($orderModel->payment?->method === 'helloasso', 404);
        $channelCode = $orderModel->channel?->code;
        $connection = Connection::where('channel_code', $channelCode)->firstOrFail();

        try {
            $attempt = CheckoutAttempt::where('order_id', $orderModel->id)->firstOrFail();
            $settlement->reconcile($attempt, $connection);
            $refundCount = $refunds->syncOrder($orderModel->id, $connection);

            return redirect()->route('admin.sales.orders.view', $orderModel->id)
                ->with('success', trans('helloasso::app.admin.sync-complete', ['count' => $refundCount]));
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('admin.sales.orders.view', $orderModel->id)
                ->withErrors(['helloasso' => trans('helloasso::app.admin.sync-failed')]);
        }
    }

    public function refund(Request $request, int $order, RefundSyncService $refunds): RedirectResponse
    {
        $orderModel = $this->orders->findOrFail($order);
        abort_unless($orderModel->payment?->method === 'helloasso', 404);

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:'.(float) $orderModel->grand_total],
            'comment' => ['required', 'string', 'max:500'],
        ]);

        $connection = Connection::where('channel_code', $orderModel->channel?->code)->firstOrFail();
        $amount = isset($data['amount']) ? (int) round((float) $data['amount'] * 100) : null;

        try {
            $refunds->requestRefund($orderModel->id, $connection, $amount, $data['comment']);

            return redirect()->route('admin.sales.orders.view', $orderModel->id)
                ->with('success', trans('helloasso::app.admin.refund-requested'));
        } catch (RefundNotSupportedException $exception) {
            return redirect()->route('admin.sales.orders.view', $orderModel->id)
                ->withErrors(['helloasso' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('admin.sales.orders.view', $orderModel->id)
                ->withErrors(['helloasso' => trans('helloasso::app.admin.refund-check-remote')]);
        }
    }

    public function cancelFuturePayments(int $order, RefundSyncService $refunds): RedirectResponse
    {
        $orderModel = $this->orders->findOrFail($order);
        abort_unless($orderModel->payment?->method === 'helloasso', 404);
        $connection = Connection::where('channel_code', $orderModel->channel?->code)->firstOrFail();

        try {
            $status = $refunds->cancelFuturePayments($orderModel->id, $connection);
            $message = match ($status) {
                'remote_and_local_canceled' => trans('helloasso::app.admin.future-payments-and-order-canceled'),
                'remote_canceled_local_action_failed' => trans('helloasso::app.admin.future-payments-canceled-review-order'),
                default => trans('helloasso::app.admin.future-payments-canceled'),
            };

            return redirect()->route('admin.sales.orders.view', $orderModel->id)
                ->with('success', $message);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('admin.sales.orders.view', $orderModel->id)
                ->withErrors(['helloasso' => trans('helloasso::app.admin.cancel-future-payments-manual')]);
        }
    }
}
