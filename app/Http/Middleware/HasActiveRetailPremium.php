<?php

namespace App\Http\Middleware;

use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HasActiveRetailPremium
{
    /**
     * Ensure the target shop has an active premium subscription and is retail-capable.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $shopOwner = $this->resolveShopOwner($request);

        if (!$shopOwner) {
            return $this->deny($request, 401, 'Unable to resolve shop owner for premium access check.');
        }

        $businessType = $this->normalizeBusinessType((string) $shopOwner->business_type);
        if (!in_array($businessType, ['retail', 'both'], true)) {
            return $this->deny(
                $request,
                403,
                'Virtual showroom is only available for retail-capable shops.',
                ['business_type' => $businessType]
            );
        }

        $subscription = ShopOwnerSubscription::where('shop_owner_id', $shopOwner->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->latest('ends_at')
            ->first();

        if (!$subscription) {
            return $this->deny(
                $request,
                403,
                'Active premium subscription is required to access the virtual showroom.',
                ['shop_owner_id' => $shopOwner->id]
            );
        }

        $request->attributes->set('active_premium_subscription', $subscription);

        return $next($request);
    }

    private function resolveShopOwner(Request $request): ?ShopOwner
    {
        $routeShopId = $request->route('id')
            ?? $request->route('shop_owner_id')
            ?? $request->route('shopOwnerId');

        if ($routeShopId !== null && is_numeric($routeShopId)) {
            return ShopOwner::find((int) $routeShopId);
        }

        $shopOwner = auth()->guard('shop_owner')->user();
        if ($shopOwner) {
            return ShopOwner::find($shopOwner->id) ?? $shopOwner;
        }

        $user = auth()->guard('user')->user();
        if ($user && !empty($user->shop_owner_id)) {
            return ShopOwner::find((int) $user->shop_owner_id);
        }

        return null;
    }

    private function deny(Request $request, int $status, string $message, array $extra = []): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(array_merge([
                'message' => $message,
            ], $extra), $status);
        }

        if (auth()->guard('shop_owner')->check()) {
            return redirect()
                ->route('shop-owner.premium-benefits')
                ->with('error', $message);
        }

        abort($status, $message);
    }

    private function normalizeBusinessType(?string $value): string
    {
        $normalized = strtolower(trim((string) $value));

        if (str_contains($normalized, 'both')) {
            return 'both';
        }

        if ($normalized === 'retail') {
            return 'retail';
        }

        if ($normalized === 'repair') {
            return 'repair';
        }

        return '';
    }
}
