<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShopOwner;
use App\Models\User;
use App\Services\RetailPosCheckoutService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

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

    public function checkout(Request $request, RetailPosCheckoutService $service)
    {
        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'payment_method' => ['required', 'string', 'in:cash,gcash,card'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0.01'],
            'items.*.size' => ['nullable', 'string', 'max:50'],
            'items.*.color' => ['nullable', 'string', 'max:100'],
            'items.*.image' => ['nullable', 'string', 'max:2048'],
        ]);

        if (in_array($validated['payment_method'], ['gcash', 'card'], true)
            && trim((string) ($validated['payment_reference'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'payment_reference' => ['Reference is required for GCash and Card payments.'],
            ]);
        }

        $shopOwnerId = $this->resolveActorShopOwnerId($this->resolveActor());
        $this->assertRetailOrBoth($shopOwnerId);

        $order = $service->checkout($shopOwnerId, $validated, $this->resolveActorAuditUserId());

        return response()->json([
            'success' => true,
            'order_id' => (int) $order->id,
            'order_number' => (string) $order->order_number,
        ], 201);
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

    private function resolveActorAuditUserId(): int
    {
        if (Auth::guard('user')->check()) {
            return (int) (Auth::guard('user')->id() ?? 0);
        }

        $shopOwner = Auth::guard('shop_owner')->user();
        if (!$shopOwner) {
            return 0;
        }

        $shopOwnerId = (int) ($shopOwner->id ?? 0);
        if ($shopOwnerId <= 0) {
            return 0;
        }

        $shopOwnerEmail = trim((string) ($shopOwner->email ?? ''));
        if ($shopOwnerEmail !== '') {
            $matchedByEmail = (int) (User::query()
                ->where('shop_owner_id', $shopOwnerId)
                ->where('email', $shopOwnerEmail)
                ->value('id') ?? 0);

            if ($matchedByEmail > 0) {
                return $matchedByEmail;
            }
        }

        return (int) (User::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->orderBy('id')
            ->value('id') ?? 0);
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
