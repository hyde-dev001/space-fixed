<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

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
        RateLimiter::for('privileged-login', static function (Request $request): Limit {
            $email = Str::lower(trim((string) $request->input('email')));

            return Limit::perMinute(5)->by('privileged-login|'.$email.'|'.$request->ip());
        });

        RateLimiter::for('privileged-mfa', static function (Request $request): Limit {
            $adminId = (string) ($request->user('super_admin')?->getAuthIdentifier() ?? 'unknown');
            $sessionId = $request->hasSession() ? $request->session()->getId() : 'no-session';

            return Limit::perMinute(5)->by('privileged-mfa|'.$adminId.'|'.$sessionId.'|'.$request->ip());
        });

        RateLimiter::for('privileged-setup', static function (Request $request): Limit {
            $adminId = (string) ($request->user('super_admin')?->getAuthIdentifier() ?? 'unknown');
            $sessionId = $request->hasSession() ? $request->session()->getId() : 'no-session';

            return Limit::perMinute(10)->by('privileged-setup|'.$adminId.'|'.$sessionId.'|'.$request->ip());
        });

        RateLimiter::for('privileged-password-reset', static function (Request $request): Limit {
            $email = Str::lower(trim((string) $request->input('email')));

            return Limit::perMinute(5)->by('privileged-password-reset|'.$email.'|'.$request->ip());
        });

        RateLimiter::for('privileged-reauth', static function (Request $request): Limit {
            $sessionId = $request->hasSession() ? $request->session()->getId() : 'no-session';

            return Limit::perMinute(5)->by('privileged-reauth|'.$sessionId.'|'.$request->ip());
        });

        if (app()->environment('production')
            && (bool) config('shop_modules.owner_erp_workspace_enabled', false)
            && ! (bool) config('shop_modules.enforcement_enabled', false)) {
            throw new \RuntimeException(
                'SHOP_OWNER_ERP_WORKSPACE_ENABLED requires SHOP_MODULE_ENFORCEMENT_ENABLED=true in production.',
            );
        }

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
