<?php

namespace App\Providers;

use App\Billing\CheckoutService;
use App\Billing\ManualPaymentGateway;
use App\Billing\PaymentGateway;
use App\Billing\Stripe\SdkStripeClient;
use App\Billing\Stripe\StripeClientContract;
use App\Billing\StripePaymentGateway;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(StripeClientContract::class, function () {
            return new SdkStripeClient(new StripeClient([
                'api_key' => (string) config('services.stripe.secret'),
            ]));
        });

        // Pin API version globally.
        try {
            \Stripe\Stripe::setApiVersion('2024-06-20');
        } catch (\Throwable $e) {
            // ignore if Stripe not loaded yet
        }

        $this->app->singleton(PaymentGateway::class, function ($app) {
            $driver = config('billing.driver', 'manual');

            return match ($driver) {
                'manual' => new ManualPaymentGateway,
                'stripe' => new StripePaymentGateway($app->make(StripeClientContract::class)),
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
