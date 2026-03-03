<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Event;

// Inventory Events
use App\Events\InventoryItemCreated;
use App\Events\InventoryItemUpdated;
use App\Events\StockMovementRecorded;
use App\Events\LowStockAlert;
use App\Events\OutOfStockAlert;
use App\Events\SupplierOrderCreated;
use App\Events\SupplierOrderDelivered;
use App\Events\SupplierOrderOverdue;

// Inventory Listeners
use App\Listeners\SendLowStockNotification;
use App\Listeners\SendOutOfStockNotification;
use App\Listeners\UpdateProductStock;
use App\Listeners\CreateStockMovement;
use App\Listeners\NotifySupplierOrderOverdue;
use App\Listeners\GenerateInventoryReport;

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
        // In local/debug mode, exclude our development-only finance public endpoints
        // from CSRF verification so the front-end can POST without a session token.
        if (app()->environment('local') || config('app.debug')) {
            VerifyCsrfToken::except([
                'api/finance/public/*',
                '/api/finance/public/*',
                'api/finance/*', // broader dev convenience
            ]);
        }

        // Register Inventory Module Event Listeners
        Event::listen(LowStockAlert::class, SendLowStockNotification::class);
        Event::listen(OutOfStockAlert::class, SendOutOfStockNotification::class);
        Event::listen(StockMovementRecorded::class, UpdateProductStock::class);
        Event::listen(InventoryItemUpdated::class, CreateStockMovement::class);
        Event::listen(SupplierOrderOverdue::class, NotifySupplierOrderOverdue::class);
        Event::listen(SupplierOrderDelivered::class, GenerateInventoryReport::class);
    }
}
