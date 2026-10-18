<?php

use Carbon\CarbonImmutable;
use Elig45\BagistoHelloAsso\Services\InstallmentPlanBuilder;

it('distributes cents exactly and schedules each installment by the 27th', function () {
    $schedule = (new InstallmentPlanBuilder)->schedule(
        10001,
        4,
        40,
        CarbonImmutable::parse('2027-01-31'),
    );

    expect($schedule['initialAmount'])->toBe(4000)
        ->and(array_sum(array_column($schedule['terms'], 'amount')) + $schedule['initialAmount'])->toBe(10001)
        ->and(array_column($schedule['terms'], 'date'))->toBe(['2027-02-27', '2027-03-27', '2027-04-27'])
        ->and(array_column($schedule['terms'], 'amount'))->toBe([2000, 2000, 2001]);
});

it('supports at most twelve monthly terms without producing dates after the 27th', function () {
    $schedule = (new InstallmentPlanBuilder)->schedule(
        12000,
        12,
        25,
        CarbonImmutable::parse('2027-01-31'),
    );

    expect(count($schedule['terms']))->toBe(11)
        ->and(collect($schedule['terms'])->every(fn ($term) => (int) substr($term['date'], 8, 2) <= 27))->toBeTrue()
        ->and(collect($schedule['terms'])->pluck('date')->unique(fn ($date) => substr($date, 0, 7))->count())->toBe(11)
        ->and(array_sum(array_column($schedule['terms'], 'amount')) + $schedule['initialAmount'])->toBe(12000);
});

it('rejects invalid installment amounts and counts', function () {
    expect(fn () => (new InstallmentPlanBuilder)->schedule(99, 2, 50))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new InstallmentPlanBuilder)->schedule(1000, 13, 50))
        ->toThrow(InvalidArgumentException::class);
});
