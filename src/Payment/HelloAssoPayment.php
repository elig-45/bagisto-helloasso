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
        $connection = Connection::globalConnection();

        return parent::isAvailable()
            && $connection
            && $connection->organization_slug
            && $connection->client_id
            && $connection->isProductionAllowed()
            && ! ($connection->environment === 'production' && $connection->cash_in_eligible === false);
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
