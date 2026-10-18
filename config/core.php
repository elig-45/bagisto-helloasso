<?php

return [
    [
        'key' => 'sales.payment_methods.helloasso',
        'name' => 'helloasso::app.admin.payment-method',
        'info' => 'helloasso::app.admin.payment-method-info',
        'sort' => 15,
        'fields' => [
            [
                'name' => 'active',
                'title' => 'admin::app.configuration.index.sales.payment-methods.status',
                'type' => 'boolean',
                'channel_based' => true,
                'locale_based' => false,
                'default' => 0,
            ], [
                'name' => 'title',
                'title' => 'admin::app.configuration.index.sales.payment-methods.title',
                'type' => 'text',
                'depends' => 'active:1',
                'validation' => 'required_if:active,1',
                'channel_based' => true,
                'locale_based' => true,
                'default' => 'HelloAsso',
            ], [
                'name' => 'description',
                'title' => 'admin::app.configuration.index.sales.payment-methods.description',
                'type' => 'textarea',
                'depends' => 'active:1',
                'channel_based' => true,
                'locale_based' => true,
                'default' => 'Paiement sécurisé avec HelloAsso.',
            ], [
                'name' => 'sort',
                'title' => 'admin::app.configuration.index.sales.payment-methods.sort-order',
                'type' => 'number',
                'depends' => 'active:1',
                'validation' => 'required_if:active,1|integer|min:1',
                'channel_based' => true,
                'locale_based' => false,
                'default' => 10,
            ],
        ],
    ],
];
