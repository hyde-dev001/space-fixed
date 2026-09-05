<?php

namespace App\Providers;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;

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
        VerifyEmail::createUrlUsing(static function (object $notifiable): string {
            $accountType = match (true) {
                $notifiable instanceof User => 'user',
                $notifiable instanceof ShopOwner => 'shop_owner',
                default => throw new \InvalidArgumentException('Unsupported email verification account.'),
            };

            return URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                [
                    'accountType' => $accountType,
                    'id' => $notifiable->getKey(),
                    'hash' => sha1((string) $notifiable->getEmailForVerification()),
                ],
            );
        });

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

        RateLimiter::for('privileged-subscription-refund', static function (Request $request): Limit {
            $adminId = (string) ($request->user('super_admin')?->getAuthIdentifier() ?? 'unknown');

            return Limit::perMinute(5)->by('privileged-subscription-refund|'.$adminId.'|'.$request->ip());
        });

        RateLimiter::for('logistics-location', static function (Request $request): Limit {
            $riderId = (string) ($request->user('user')?->getAuthIdentifier() ?? 'guest');

            return Limit::perMinute((int) config('logistics_tracking.rate_limits.location_updates_per_minute', 20))
                ->by('logistics-location|'.$riderId.'|'.$request->ip());
        });

        RateLimiter::for('logistics-viewer', static function (Request $request): Limit {
            $viewerId = (string) ($request->user('user')?->getAuthIdentifier()
                ?? $request->user('shop_owner')?->getAuthIdentifier()
                ?? 'guest');

            return Limit::perMinute((int) config('logistics_tracking.rate_limits.viewer_requests_per_minute', 20))
                ->by('logistics-viewer|'.$viewerId.'|'.$request->ip());
        });

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
