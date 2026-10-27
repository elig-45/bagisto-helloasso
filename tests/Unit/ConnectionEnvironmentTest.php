<?php

use Elig45\BagistoHelloAsso\Models\CheckoutAttempt;
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

it('keeps existing production attempts manageable when new production checkouts are disabled', function () {
    config(['helloasso.allow_production' => false]);

    $connection = Connection::make([
        'environment' => 'production',
        'auth_mode' => 'association',
        'organization_slug' => 'mejf',
    ]);
    $attempt = CheckoutAttempt::make([
        'environment' => 'production',
        'auth_mode' => 'association',
        'organization_slug' => 'mejf',
    ]);

    expect($connection->isProductionAllowed())->toBeFalse()
        ->and($connection->canManageAttempt($attempt))->toBeTrue();
});
