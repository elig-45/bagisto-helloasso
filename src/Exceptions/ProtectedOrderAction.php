<?php

namespace Elig45\BagistoHelloAsso\Exceptions;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ProtectedOrderAction extends RuntimeException
{
    public function __construct(protected int $orderId)
    {
        parent::__construct('HelloAsso orders must be refunded or canceled through HelloAsso.');
    }

    public function render(Request $request): RedirectResponse
    {
        return redirect()->route('admin.sales.orders.view', $this->orderId)
            ->withErrors(['helloasso' => trans('helloasso::app.admin.use-helloasso-actions')]);
    }
}
