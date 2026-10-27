<?php

use Elig45\BagistoHelloAsso\Tests\TestCase;
use Illuminate\Routing\Controller;
use Webkul\Checkout\Repositories\CartRepository;

if (! class_exists(Webkul\Shop\Http\Controllers\Controller::class)) {
    class_alias(Controller::class, Webkul\Shop\Http\Controllers\Controller::class);
}

if (! class_exists(CartRepository::class)) {
    class_alias(stdClass::class, CartRepository::class);
}

uses(TestCase::class)->in('.');
