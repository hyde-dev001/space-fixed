<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Support\Finance\FinanceShopContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * ShopIsolationMiddleware
 * 
 * Ensures that users with ERP module roles (HR, FINANCE, etc.)
 * can only access data from their own shop.
 * 
 * This middleware prevents users from accessing other shops' data
 * by checking the shop_owner_id on every request.
 */
class ShopIsolationMiddleware
{
    public function __construct(private readonly FinanceShopContext $financeShopContext)
    {
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('user');
        $shopOwner = $request->user('shop_owner');

        // If user is not authenticated, deny access
        if (! $user && ! $shopOwner) {
            return $next($request);
        }

        // Resolve the tenant from the guard selected by the route's auth
        // middleware. A request can retain another authenticated guard (for
        // example, a customer user while a shop-owner endpoint is called),
        // so the presence of a user-guard session must not override the
        // active shop-owner route context.
        $currentShopId = match (Auth::getDefaultDriver()) {
            'shop_owner' => $shopOwner?->getKey(),
            'user' => $user ? $this->financeShopContext->id($request) : null,
            default => $user
                ? $this->financeShopContext->id($request)
                : $shopOwner?->getKey(),
        };

        if (! is_numeric($currentShopId) || (int) $currentShopId < 1) {
            return response()->json([
                'message' => 'A Finance shop context is required.',
                'error' => 'TENANT_CONTEXT_REQUIRED',
            ], Response::HTTP_FORBIDDEN);
        }

        $currentShopId = (int) $currentShopId;

        // Check if shop_id in request matches user's shop_owner_id
        if ($request->has('shop_id') || $request->route('shop_id')) {
            $requestedShopId = $request->input('shop_id') ?? $request->route('shop_id');
            
            if ($requestedShopId && (int) $requestedShopId !== $currentShopId) {
                return response()->json([
                    'message' => 'You do not have access to this shop',
                    'error' => 'UNAUTHORIZED_SHOP_ACCESS'
                ], Response::HTTP_FORBIDDEN);
            }
        }

        // Attach shop_owner_id to request for easy access in controllers
        $request->merge(['user_shop_id' => $currentShopId]);

        return $next($request);
    }
}
