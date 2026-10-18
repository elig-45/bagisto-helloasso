<?php

namespace Elig45\BagistoHelloAsso\Listeners;

use Elig45\BagistoHelloAsso\Exceptions\ProtectedOrderAction;
use Elig45\BagistoHelloAsso\Services\RefundSyncGuard;
use Webkul\Sales\Models\Order;

class ProtectOrderActions
{
    public function __construct(protected RefundSyncGuard $guard) {}

    public function beforeRefund(array $data): void
    {
        $order = Order::find($data['order_id'] ?? null);

        if (
            $order?->payment?->method === 'helloasso'
            && ! $this->guard->isAllowed()
        ) {
            throw new ProtectedOrderAction((int) $order->id);
        }
    }

    public function beforeCancel(Order $order): void
    {
        if (
            $order->payment?->method === 'helloasso'
            && ! $this->guard->isAllowed()
        ) {
            throw new ProtectedOrderAction((int) $order->id);
        }
    }
}
