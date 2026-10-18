<?php

namespace Elig45\BagistoHelloAsso\Payment;

use Elig45\BagistoHelloAsso\Models\Connection;
use Webkul\Payment\Payment\Payment;

class HelloAssoPayment extends Payment
{
    protected $code = 'helloasso';

    public function getRedirectUrl(): string
    {
        return route('helloasso.payment.redirect');
    }

    public function isAvailable(): bool
    {
        $channelCode = core()->getCurrentChannelCode();

        return parent::isAvailable()
            && $channelCode
            && Connection::where('channel_code', $channelCode)
                ->whereNotNull('organization_slug')
                ->whereNotNull('client_id')
                ->when(! config('helloasso.allow_production', true), fn ($query) => $query->where('environment', 'sandbox'))
                ->where(function ($query) {
                    $query->where('environment', '!=', 'production')
                        ->orWhereNull('cash_in_eligible')
                        ->orWhere('cash_in_eligible', true);
                })
                ->exists();
    }

    public function getTitle(): string
    {
        return (string) ($this->getConfigData('title') ?: 'HelloAsso');
    }

    public function getDescription(): string
    {
        return (string) ($this->getConfigData('description') ?: trans('helloasso::app.checkout.description'));
    }

    public function getImage(): string
    {
        return asset('vendor/helloasso/helloasso.svg');
    }
}
