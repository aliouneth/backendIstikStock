<?php

namespace App\Providers;

use App\Billing\CheckoutService;
use App\Billing\ManualPaymentGateway;
use App\Billing\PaymentGateway;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, function () {
            $driver = config('billing.driver', 'manual');

            // Add the Stripe driver here when it is implemented; everything
            // downstream (checkout, webhook confirmation, plan granting) is
            // already driver agnostic.
            return match ($driver) {
                'manual' => new ManualPaymentGateway,
                default => throw new RuntimeException("Unknown BILLING_DRIVER [{$driver}]. Implement it in AppServiceProvider."),
            };
        });

        $this->app->singleton(CheckoutService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Stay under the 1000-byte InnoDB index limit of the local MySQL
        // server when using utf8mb4 (255 * 4 = 1020 > 1000).
        Schema::defaultStringLength(191);
    }
}
