<?php

use Elig45\BagistoHelloAsso\Http\Controllers\Shop\CheckoutController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->prefix('helloasso')->group(function () {
    Route::get('redirect', [CheckoutController::class, 'redirect'])->name('helloasso.payment.redirect');
    Route::post('plan/{channelCode}', [CheckoutController::class, 'selectPlan'])->name('helloasso.payment.plan');
    Route::get('{channelCode}/return', [CheckoutController::class, 'returned'])->name('helloasso.payment.return');
    Route::get('{channelCode}/cancel', [CheckoutController::class, 'cancel'])->name('helloasso.payment.cancel');
    Route::get('{channelCode}/error', [CheckoutController::class, 'error'])->name('helloasso.payment.error');
});

Route::post('helloasso/webhook', [CheckoutController::class, 'webhook'])
    ->middleware('throttle:120,1')
    ->name('helloasso.payment.webhook');

Route::post('helloasso/{channelCode}/webhook', [CheckoutController::class, 'webhook'])
    ->middleware('throttle:120,1')
    ->name('helloasso.payment.webhook.legacy');
