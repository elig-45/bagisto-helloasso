<?php

use Elig45\BagistoHelloAsso\Models\CheckoutAttempt;
use Elig45\BagistoHelloAsso\Models\HelloAssoPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

it('shows planned installments and the remote refund operations on the order', function () {
    config(['view.compiled' => sys_get_temp_dir()]);
    app()->setLocale('fr');

    Route::post('/admin/sales/refunds/{order}', fn () => null)->name('admin.sales.refunds.store');
    Route::post('/admin/helloasso/orders/{order}/refund', fn () => null)->name('admin.helloasso.refund');
    Route::post('/admin/helloasso/orders/{order}/sync', fn () => null)->name('admin.helloasso.sync');

    $attempt = CheckoutAttempt::make([
        'installment_count' => 3,
        'installment_plan' => [
            'initialAmount' => 2000,
            'terms' => [
                ['amount' => 2000, 'date' => '2026-11-27'],
                ['amount' => 2000, 'date' => '2026-12-27'],
            ],
        ],
    ]);
    $attempt->created_at = Carbon::parse('2026-10-27 12:00:00');

    $payment = HelloAssoPayment::make([
        'installment_number' => 1,
        'amount' => 2000,
        'state' => 'Refused',
        'refunded_amount' => 500,
        'remote_data' => [
            'refundOperations' => [
                ['id' => 'refund-42', 'amount' => 500, 'status' => 'Processed'],
            ],
        ],
    ]);

    $html = view('helloasso::admin.order-actions', [
        'order' => (object) ['id' => 42],
        'attempt' => $attempt,
        'payments' => collect([$payment]),
    ])->render();

    expect($html)
        ->toContain('Calendrier prévu')
        ->toContain('Paiement initial')
        ->toContain('27/11/2026')
        ->toContain('27/12/2026')
        ->toContain('Refused')
        ->toContain('refund-42')
        ->toContain('Processed');
});
