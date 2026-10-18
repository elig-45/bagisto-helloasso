<?php

namespace Elig45\BagistoHelloAsso\Listeners;

use Webkul\Theme\ViewRenderEventManager;

class CheckoutViewListener
{
    public function handle(ViewRenderEventManager $manager): void
    {
        $manager->addTemplate('helloasso::checkout.plan-selector');
    }
}
