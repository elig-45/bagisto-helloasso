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

it('fails closed for unknown environments and refuses to select an API host', function () {
    $connection = Connection::make(['environment' => 'unexpected']);

    expect($connection->isSandbox())->toBeFalse()
        ->and($connection->isProductionAllowed())->toBeFalse()
        ->and(fn () => $connection->apiBaseUrl())->toThrow(UnexpectedValueException::class)
        ->and(fn () => $connection->oauthBaseUrl())->toThrow(UnexpectedValueException::class);
});
