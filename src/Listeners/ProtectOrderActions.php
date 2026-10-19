<?php

namespace Elig45\BagistoHelloAsso\Listeners;

use Elig45\BagistoHelloAsso\Exceptions\ProtectedOrderAction;
use Elig45\BagistoHelloAsso\Services\RefundSyncGuard;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Repositories\OrderRepository;

class ProtectOrderActions
{
    public function __construct(
        protected RefundSyncGuard $guard,
        protected OrderRepository $orders,
    ) {}

    public function beforeRefund(mixed ...$payload): void
    {
        $orderId = $this->findOrderId($payload);

        if (! $orderId) {
            return;
        }

        $order = $this->orders->find($orderId);

        if (
            $order?->payment?->method === 'helloasso'
            && ! $this->guard->isAllowed()
        ) {
            throw new ProtectedOrderAction((int) $order->id);
        }
    }

    protected function findOrderId(array $payload): ?int
    {
        foreach ($payload as $argument) {
            if ($argument instanceof Order) {
                return (int) $argument->id;
            }

            if (is_array($argument) && is_numeric($argument['order_id'] ?? null)) {
                return (int) $argument['order_id'];
            }
        }

        foreach ($payload as $argument) {
            if (is_int($argument) && $argument > 0) {
                return $argument;
            }

            if (is_string($argument) && ctype_digit($argument) && (int) $argument > 0) {
                return (int) $argument;
            }
        }

        return null;
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
