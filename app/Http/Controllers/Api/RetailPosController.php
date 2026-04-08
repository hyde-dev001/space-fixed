<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RetailPosController extends Controller
{
    public function listProducts(Request $request)
    {
        $shopOwnerId = $this->resolveActorShopOwnerId($this->resolveActor());
        $this->assertRetailOrBoth($shopOwnerId);

        $search = trim((string) $request->query('q', ''));

        $rows = Product::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($searchQuery) use ($search) {
                    $searchQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'name', 'price', 'stock_quantity', 'slug', 'main_image']);

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    public function checkout(Request $request)
    {
        $shopOwnerId = $this->resolveActorShopOwnerId($this->resolveActor());
        $this->assertRetailOrBoth($shopOwnerId);

        return response()->json([
            'success' => false,
            'code' => 'RETAIL_POS_NOT_IMPLEMENTED',
            'message' => 'Retail POS checkout is not implemented yet.',
        ], 501);
    }

    public function history(Request $request)
    {
        $shopOwnerId = $this->resolveActorShopOwnerId($this->resolveActor());
        $this->assertRetailOrBoth($shopOwnerId);

        $limit = max(1, min((int) $request->query('limit', 200), 500));

        $rows = Order::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('order_number', 'like', 'RPOS-%')
            ->with('items')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    public function receipt(Order $order)
    {
        $shopOwnerId = $this->resolveActorShopOwnerId($this->resolveActor());
        $this->assertRetailOrBoth($shopOwnerId);

        abort_if((int) $order->shop_owner_id !== $shopOwnerId, 404);
        abort_if(!str_starts_with((string) $order->order_number, 'RPOS-'), 404);

        $order->load('items');

        return response()->json([
            'success' => true,
            'data' => $order,
        ]);
    }

    private function resolveActor(): ?object
    {
        return Auth::guard('user')->user() ?? Auth::guard('shop_owner')->user();
    }

    private function resolveActorShopOwnerId(?object $actor = null): int
    {
        if (Auth::guard('shop_owner')->check()) {
            return (int) Auth::guard('shop_owner')->id();
        }

        if (Auth::guard('user')->check()) {
            return (int) (Auth::guard('user')->user()?->shop_owner_id ?? 0);
        }

        return (int) ($actor?->shop_owner_id ?? 0);
    }

    private function assertRetailOrBoth(int $shopOwnerId): void
    {
        $businessType = $this->normalizeBusinessType((string) ShopOwner::query()->whereKey($shopOwnerId)->value('business_type'));

        if (in_array($businessType, ['retail', 'both'], true)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'success' => false,
            'code' => 'BUSINESS_TYPE_FORBIDDEN_MODE',
            'message' => 'Retail POS is not available for this business type.',
        ], 403));
    }

    private function normalizeBusinessType(string $value): string
    {
        $normalized = strtolower(trim($value));

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
