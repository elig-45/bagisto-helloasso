<?php

return [
    [
        'key' => 'sales.helloasso.settings',
        'name' => 'helloasso::app.admin.menu',
        'route' => [
            'admin.helloasso.settings',
            'admin.helloasso.save',
            'admin.helloasso.test',
            'admin.helloasso.test-legacy',
            'admin.helloasso.connect',
            'admin.helloasso.callback',
        ],
        'sort' => 20,
    ], [
        'key' => 'sales.helloasso.view',
        'name' => 'admin::app.acl.view',
        'route' => 'admin.helloasso.sync',
        'sort' => 21,
    ], [
        'key' => 'sales.helloasso.refunds',
        'name' => 'admin::app.acl.create',
        'route' => [
            'admin.helloasso.refund',
            'admin.helloasso.cancel-future-payments',
        ],
        'sort' => 22,
    ],
];
