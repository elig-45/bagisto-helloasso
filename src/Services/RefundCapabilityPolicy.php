<?php

namespace Elig45\BagistoHelloAsso\Services;

class RefundCapabilityPolicy
{
    public function supports(string $authMode, int $requestedAmount, int $availableAmount): bool
    {
        return $authMode !== 'association' || $requestedAmount === $availableAmount;
    }
}
