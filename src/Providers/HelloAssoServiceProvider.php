<?php

namespace Elig45\BagistoHelloAsso\Providers;

use Elig45\BagistoHelloAsso\Console\Commands\ReconcileCommand;
use Elig45\BagistoHelloAsso\Console\Commands\RefreshTokensCommand;
use Elig45\BagistoHelloAsso\Listeners\CheckoutViewListener;
use Elig45\BagistoHelloAsso\Listeners\OrderActionsListener;
use Elig45\BagistoHelloAsso\Listeners\ProtectOrderActions;
use Elig45\BagistoHelloAsso\Services\RefundSyncGuard;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class HelloAssoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ReconcileCommand::class,
                RefreshTokensCommand::class,
            ]);
        }

        $this->mergeConfigFrom(__DIR__.'/../../config/payment-methods.php', 'payment_methods');
        $this->mergeConfigFrom(__DIR__.'/../../config/helloasso.php', 'helloasso');
        $this->mergeConfigFrom(__DIR__.'/../../config/core.php', 'core');
        $this->mergeConfigFrom(__DIR__.'/../../config/acl.php', 'acl');
        $this->app->singleton(RefundSyncGuard::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/shop.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/admin.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'helloasso');
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'helloasso');
        $this->publishes([
            __DIR__.'/../Resources/assets' => public_path('vendor/helloasso'),
        ], 'helloasso-assets');

        Event::listen('bagisto.shop.checkout.payment-method.after', CheckoutViewListener::class);
        Event::listen('bagisto.admin.sales.order.page_action.after', OrderActionsListener::class);
        Event::listen('sales.refund.save.before', [ProtectOrderActions::class, 'beforeRefund']);
        Event::listen('sales.order.cancel.before', [ProtectOrderActions::class, 'beforeCancel']);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('helloasso:reconcile')->everyFiveMinutes()->withoutOverlapping();
            $schedule->command('helloasso:refresh-tokens')->daily()->withoutOverlapping();
        });
    }
}
