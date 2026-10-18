<?php

use Elig45\BagistoHelloAsso\Payment\HelloAssoPayment;

return [
    'helloasso' => [
        'class' => HelloAssoPayment::class,
        'code' => 'helloasso',
        'title' => 'HelloAsso',
        'description' => 'Pay securely with HelloAsso.',
        'active' => false,
        'sort' => 10,
    ],
];
