<?php

namespace Elig45\BagistoHelloAsso\Listeners;

use Elig45\BagistoHelloAsso\Models\CheckoutAttempt;
use Elig45\BagistoHelloAsso\Models\HelloAssoPayment;
use Webkul\Theme\ViewRenderEventManager;

class OrderActionsListener
{
    public function handle(ViewRenderEventManager $manager): void
    {
        $order = $manager->getParam('order');

        if ($order?->payment?->method === 'helloasso') {
            $manager->addTemplate('helloasso::admin.order-actions', [
                'order' => $order,
                'attempt' => CheckoutAttempt::where('order_id', $order->id)->first(),
                'payments' => HelloAssoPayment::where('order_id', $order->id)
                    ->orderBy('installment_number')
                    ->get(),
            ]);
        }
    }
}
