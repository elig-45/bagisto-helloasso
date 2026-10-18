<?php

use Elig45\BagistoHelloAsso\Http\Controllers\Admin\OrderController;
use Elig45\BagistoHelloAsso\Http\Controllers\Admin\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'admin'])
    ->prefix(config('app.admin_url'))
    ->group(function () {
        Route::controller(SettingsController::class)->prefix('helloasso')->group(function () {
            Route::get('settings', 'index')->name('admin.helloasso.settings');
            Route::post('settings', 'save')->name('admin.helloasso.save');
            Route::post('test/{channel}', 'test')->name('admin.helloasso.test');
            Route::get('connect/{channel}', 'connect')->name('admin.helloasso.connect');
            Route::get('callback/{channel}', 'callback')->name('admin.helloasso.callback');
        });

        Route::controller(OrderController::class)->prefix('helloasso/orders')->group(function () {
            Route::post('{order}/sync', 'sync')->name('admin.helloasso.sync');
            Route::post('{order}/refund', 'refund')->name('admin.helloasso.refund');
            Route::post('{order}/cancel-future-payments', 'cancelFuturePayments')->name('admin.helloasso.cancel-future-payments');
        });
    });
