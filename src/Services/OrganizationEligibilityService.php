<?php

namespace Elig45\BagistoHelloAsso\Services;

use Elig45\BagistoHelloAsso\Models\Connection;

class OrganizationEligibilityService
{
    public function recordApiResult(Connection $connection, array $organization): ?bool
    {
        $eligible = $this->readEligibility($organization);

        if ($eligible !== null) {
            $connection->forceFill([
                'cash_in_eligible' => $eligible,
                'cash_in_checked_at' => now(),
            ])->save();
        }

        return $eligible;
    }

    public function applyNotification(Connection $connection, array $data): bool
    {
        if (! $this->matchesNotification($connection, $data)) {
            return false;
        }

        $connection->forceFill([
            'cash_in_eligible' => $this->notificationEligibility($data),
            'cash_in_checked_at' => now(),
        ])->save();

        return true;
    }

    public function matchesNotification(Connection $connection, array $data): bool
    {
        $organizationSlug = $data['organization_slug']
            ?? $data['organizationSlug']
            ?? data_get($data, 'organization.slug');

        return is_string($organizationSlug)
            && hash_equals((string) $connection->organization_slug, $organizationSlug)
            && is_bool($this->notificationEligibility($data));
    }

    protected function notificationEligibility(array $data): mixed
    {
        return $data['is_cashin_compliant'] ?? $data['isCashInCompliant'] ?? null;
    }

    protected function readEligibility(array $organization): ?bool
    {
        $value = $organization['isCashInCompliant'] ?? $organization['is_cashin_compliant'] ?? null;

        return is_bool($value) ? $value : null;
    }
}
