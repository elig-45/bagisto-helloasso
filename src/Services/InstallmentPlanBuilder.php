<?php

namespace Elig45\BagistoHelloAsso\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

class InstallmentPlanBuilder
{
    public function schedule(int $totalCents, int $count, int $initialPercent, ?CarbonImmutable $today = null): array
    {
        if (
            $totalCents < 100
            || $count < 2
            || $count > 12
            || $initialPercent < 1
            || $initialPercent > 99
        ) {
            throw new InvalidArgumentException('Invalid HelloAsso installment plan.');
        }

        $today ??= CarbonImmutable::today();
        $initial = (int) round($totalCents * $initialPercent / 100);
        $initial = min($totalCents - ($count - 1), max(1, $initial));
        $remaining = $totalCents - $initial;
        $baseTerm = intdiv($remaining, $count - 1);
        $remainder = $remaining % ($count - 1);
        $terms = [];

        for ($index = 1; $index < $count; $index++) {
            $date = $today->addMonthsNoOverflow($index);

            if ($date->day > 27) {
                $date = $date->day(27);
            }

            $terms[] = [
                'amount' => $baseTerm + ($index === $count - 1 ? $remainder : 0),
                'date' => $date->toDateString(),
            ];
        }

        return [
            'initialAmount' => $initial,
            'terms' => $terms,
        ];
    }
}
