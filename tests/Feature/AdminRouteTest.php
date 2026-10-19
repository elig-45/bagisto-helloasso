<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('keeps legacy channel-specific HelloAsso test URLs routable', function () {
    $route = Route::getRoutes()->match(Request::create('/admin/helloasso/test/mejf', 'GET'));

    expect($route->getName())->toBe('admin.helloasso.test-legacy')
        ->and($route->getActionMethod())->toBe('testLegacy');
});

it('registers order synchronization as a post action', function () {
    $route = Route::getRoutes()->match(Request::create('/admin/helloasso/orders/1/sync', 'POST'));

    expect($route->getName())->toBe('admin.helloasso.sync')
        ->and($route->getActionName())->toContain('OrderController@sync')
        ->and($route->parameterNames())->toBe(['order']);
});
