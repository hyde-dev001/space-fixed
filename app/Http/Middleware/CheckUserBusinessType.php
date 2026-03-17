<?php

namespace App\Http\Middleware;

use App\Models\ShopOwner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserBusinessType
{
    /**
     * Handle an incoming request for ERP users (auth:user).
     *
     * @param Request $request
     * @param Closure(Request): Response $next
     * @param string ...$allowedTypes
     * @return Response
     */
    public function handle(Request $request, Closure $next, ...$allowedTypes): Response
    {
        $user = $request->user('user') ?? auth()->guard('user')->user();

        if (!$user) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Unauthenticated.'
                ], 401);
            }

            abort(401, 'Unauthenticated.');
        }

        if (empty($allowedTypes)) {
            return $next($request);
        }

        $shopOwner = $user->shopOwner;
        if (!$shopOwner && $user->shop_owner_id) {
            $shopOwner = ShopOwner::find($user->shop_owner_id);
        }

        $businessType = $this->normalizeBusinessType($shopOwner?->business_type);
        $normalizedAllowedTypes = array_values(array_unique(array_filter(array_map(
            fn (string $type) => $this->normalizeBusinessType($type),
            $allowedTypes
        ))));

        if (!in_array($businessType, $normalizedAllowedTypes, true)) {
            $allowedLabel = implode(' or ', $normalizedAllowedTypes);
            $message = "This feature is not available for your business type. Allowed: {$allowedLabel}.";

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => $message,
                    'business_type' => $businessType,
                    'allowed_types' => $normalizedAllowedTypes,
                ], 403);
            }

            return redirect()->route('erp.profile')->with('error', $message);
        }

        return $next($request);
    }

    private function normalizeBusinessType(?string $businessType): string
    {
        $normalized = strtolower(trim((string) $businessType));

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