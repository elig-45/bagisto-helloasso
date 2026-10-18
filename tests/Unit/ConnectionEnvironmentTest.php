<?php

use Elig45\BagistoHelloAsso\Models\Connection;

it('allows production only when the deployment explicitly permits it', function () {
    config(['helloasso.allow_production' => false]);

    expect(Connection::make(['environment' => 'production'])->isProductionAllowed())->toBeFalse()
        ->and(Connection::make(['environment' => 'sandbox'])->isProductionAllowed())->toBeTrue();
});

it('allows production in deployments that have not enabled the sandbox guard', function () {
    config(['helloasso.allow_production' => true]);

    expect(Connection::make(['environment' => 'production'])->isProductionAllowed())->toBeTrue();
});
