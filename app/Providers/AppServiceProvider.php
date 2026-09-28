<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
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
