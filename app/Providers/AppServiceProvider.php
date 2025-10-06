<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\OrderItem;
use App\Observers\OrderItemObserver;

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
        // Keep order header totals in sync with line items
        OrderItem::observe(OrderItemObserver::class);
    }
}
