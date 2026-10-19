<?php

use Elig45\BagistoHelloAsso\Models\Connection;
use Elig45\BagistoHelloAsso\Services\OrganizationEligibilityService;

it('stores explicit eligibility from the organization API response', function () {
    $connection = Connection::create([
        'channel_code' => Connection::GLOBAL_KEY,
        'environment' => 'production',
        'auth_mode' => 'association',
        'organization_slug' => 'mejf',
        'client_id' => 'client',
        'client_secret' => 'secret',
    ]);

    $result = (new OrganizationEligibilityService)->recordApiResult($connection, [
        'isCashInCompliant' => false,
    ]);

    expect($result)->toBeFalse()
        ->and($connection->fresh()->cash_in_eligible)->toBeFalse()
        ->and($connection->fresh()->cash_in_checked_at)->not->toBeNull();
});

it('leaves eligibility unknown when the API omits the restricted field', function () {
    $connection = Connection::create([
        'channel_code' => Connection::GLOBAL_KEY,
        'environment' => 'production',
        'auth_mode' => 'association',
        'organization_slug' => 'betar',
        'client_id' => 'client',
        'client_secret' => 'secret',
    ]);

    expect((new OrganizationEligibilityService)->recordApiResult($connection, ['name' => 'Betar']))
        ->toBeNull()
        ->and($connection->fresh()->cash_in_eligible)->toBeNull()
        ->and($connection->fresh()->cash_in_checked_at)->toBeNull();
});

it('applies a cash-in notification only to the configured organization and boolean state', function () {
    $connection = Connection::create([
        'channel_code' => Connection::GLOBAL_KEY,
        'environment' => 'production',
        'auth_mode' => 'partner',
        'organization_slug' => 'mejf',
        'client_id' => 'client',
        'client_secret' => 'secret',
    ]);
    $service = new OrganizationEligibilityService;

    expect($service->applyNotification($connection, [
        'organization_slug' => 'other-organization',
        'is_cashin_compliant' => false,
    ]))->toBeFalse()
        ->and($connection->fresh()->cash_in_eligible)->toBeNull();

    expect($service->applyNotification($connection, [
        'organization_slug' => 'mejf',
        'is_cashin_compliant' => false,
    ]))->toBeTrue()
        ->and($connection->fresh()->cash_in_eligible)->toBeFalse()
        ->and($connection->fresh()->cash_in_checked_at)->not->toBeNull();
});
