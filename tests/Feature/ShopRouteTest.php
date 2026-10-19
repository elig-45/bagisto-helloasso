<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('uses a nonreserved channel parameter for HelloAsso shop routes', function () {
    $routes = [
        ['POST', '/helloasso/plan/betar', 'helloasso.payment.plan'],
        ['GET', '/helloasso/betar/return', 'helloasso.payment.return'],
        ['GET', '/helloasso/betar/cancel', 'helloasso.payment.cancel'],
        ['GET', '/helloasso/betar/error', 'helloasso.payment.error'],
        ['POST', '/helloasso/betar/webhook', 'helloasso.payment.webhook.legacy'],
    ];

    foreach ($routes as [$method, $uri, $name]) {
        $route = Route::getRoutes()->match(Request::create($uri, $method));

        expect($route->getName())->toBe($name)
            ->and($route->parameterNames())->toContain('channelCode')
            ->and($route->parameterNames())->not->toContain('channel');
    }
});
