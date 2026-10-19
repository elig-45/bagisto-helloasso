<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('keeps legacy channel-specific HelloAsso test URLs routable', function () {
    $route = Route::getRoutes()->match(Request::create('/admin/helloasso/test/mejf', 'GET'));

    expect($route->getName())->toBe('admin.helloasso.test-legacy')
        ->and($route->getActionMethod())->toBe('testLegacy');
});
