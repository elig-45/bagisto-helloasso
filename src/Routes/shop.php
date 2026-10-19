<?php

use Elig45\BagistoHelloAsso\Http\Controllers\Shop\CheckoutController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->prefix('helloasso')->group(function () {
    Route::get('redirect', [CheckoutController::class, 'redirect'])->name('helloasso.payment.redirect');
    Route::post('plan/{channel}', [CheckoutController::class, 'selectPlan'])->name('helloasso.payment.plan');
    Route::get('{channel}/return', [CheckoutController::class, 'returned'])->name('helloasso.payment.return');
    Route::get('{channel}/cancel', [CheckoutController::class, 'cancel'])->name('helloasso.payment.cancel');
    Route::get('{channel}/error', [CheckoutController::class, 'error'])->name('helloasso.payment.error');
});

Route::post('helloasso/webhook', [CheckoutController::class, 'webhook'])
    ->middleware('throttle:120,1')
    ->name('helloasso.payment.webhook');

Route::post('helloasso/{channel}/webhook', [CheckoutController::class, 'webhook'])
    ->middleware('throttle:120,1')
    ->name('helloasso.payment.webhook.legacy');
