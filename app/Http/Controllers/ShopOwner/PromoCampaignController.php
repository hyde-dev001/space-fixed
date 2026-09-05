<?php

namespace App\Http\Controllers\ShopOwner;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PromoCampaign;
use App\Models\ShopOwner;
use App\Services\ShopModuleAccessService;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PromoCampaignController extends Controller
{
    public function __construct(
        private readonly ShopModuleAccessService $shopModuleAccess,
    ) {}

    private function promoTablesReady(): bool
    {
        return Schema::hasTable('promo_campaigns')
            && Schema::hasTable('promo_campaign_products')
            && Schema::hasTable('voucher_claims')
            && Schema::hasColumn('promo_campaigns', 'discount_target');
    }

    private function promoTablesUnavailableResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Promo feature is not available yet. Please run promo migrations first.',
        ], 503);
    }

    public function index(Request $request): JsonResponse
    {
        if (!$this->promoTablesReady()) {
            return $this->promoTablesUnavailableResponse();
        }

        $shopOwner = Auth::guard('shop_owner')->user();

        if (!$shopOwner) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $promos = PromoCampaign::with('products:id,name')
            ->where('shop_owner_id', $shopOwner->id)
            ->latest('id')
            ->get();

        $logisticsDecision = $this->shopModuleAccess->decide($shopOwner, 'logistics');
        $logisticsModule = $shopOwner->modules()
            ->where('module_key', 'logistics')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $promos,
            'logistics' => [
                'eligible' => $this->shopModuleAccess->isEligible($shopOwner, 'logistics'),
                'enabled' => $logisticsModule !== null && (bool) $logisticsModule->enabled,
                'accessible' => $logisticsDecision->allowed,
                'code' => $logisticsDecision->code,
                'reason' => $logisticsDecision->allowed ? null : $logisticsDecision->message,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->promoTablesReady()) {
            return $this->promoTablesUnavailableResponse();
        }

        $shopOwner = Auth::guard('shop_owner')->user();

        if (!$shopOwner) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'kind' => 'required|in:voucher,sale',
            'discount_target' => 'nullable|in:items,shipping',
            'scope' => 'required|in:shop_wide,product_specific',
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:100',
            'discount_mode' => 'required|in:percentage,fixed',
            'value' => 'required|numeric|min:0.01',
            'min_spend' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
        ]);

        $validated['discount_target'] = $validated['discount_target'] ?? 'items';
        $ruleError = $this->validateDiscountTarget(
            $shopOwner,
            $validated['kind'],
            $validated['discount_target'],
            $validated['scope'],
        );
        if ($ruleError !== null) {
            return $ruleError;
        }

        if ($validated['kind'] === 'voucher' && empty($validated['code'])) {
            return response()->json(['success' => false, 'message' => 'Voucher code is required.'], 422);
        }

        if ($validated['scope'] === 'product_specific' && empty($validated['product_ids'])) {
            return response()->json(['success' => false, 'message' => 'Select at least one product.'], 422);
        }

        if ($validated['kind'] === 'sale') {
            $validated['code'] = null;
        }

        $campaign = DB::transaction(function () use ($validated, $shopOwner) {
            $campaign = PromoCampaign::create([
                ...Arr::except($validated, ['product_ids']),
                'shop_owner_id' => $shopOwner->id,
                'status' => $this->resolveStatus($validated['start_at'], $validated['end_at']),
                'min_spend' => $validated['min_spend'] ?? 0,
            ]);

            $this->syncProductScope($campaign, $shopOwner->id, $validated['scope'] ?? 'shop_wide', $validated['product_ids'] ?? []);

            return $campaign->load('products:id,name');
        });

        return response()->json(['success' => true, 'data' => $campaign], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (!$this->promoTablesReady()) {
            return $this->promoTablesUnavailableResponse();
        }

        $shopOwner = Auth::guard('shop_owner')->user();

        if (!$shopOwner) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $campaign = PromoCampaign::where('shop_owner_id', $shopOwner->id)->findOrFail($id);

        $validated = $request->validate([
            'kind' => 'sometimes|in:voucher,sale',
            'discount_target' => 'sometimes|in:items,shipping',
            'scope' => 'sometimes|in:shop_wide,product_specific',
            'name' => 'sometimes|string|max:255',
            'code' => 'nullable|string|max:100',
            'discount_mode' => 'sometimes|in:percentage,fixed',
            'value' => 'sometimes|numeric|min:0.01',
            'min_spend' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'start_at' => 'sometimes|date',
            'end_at' => 'sometimes|date',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer|exists:products,id',
        ]);

        $nextKind = $validated['kind'] ?? $campaign->kind;
        $nextTarget = $validated['discount_target'] ?? ($campaign->discount_target ?: 'items');
        $nextScope = $validated['scope'] ?? $campaign->scope;

        $ruleError = $this->validateDiscountTarget($shopOwner, $nextKind, $nextTarget, $nextScope);
        if ($ruleError !== null) {
            return $ruleError;
        }

        if ($nextKind === 'voucher' && array_key_exists('code', $validated) && empty($validated['code'])) {
            return response()->json(['success' => false, 'message' => 'Voucher code is required.'], 422);
        }

        if ($nextScope === 'product_specific' && array_key_exists('product_ids', $validated) && empty($validated['product_ids'])) {
            return response()->json(['success' => false, 'message' => 'Select at least one product.'], 422);
        }

        $updatedCampaign = DB::transaction(function () use ($campaign, $validated, $shopOwner, $nextScope, $nextKind) {
            $startAt = $validated['start_at'] ?? $campaign->start_at;
            $endAt = $validated['end_at'] ?? $campaign->end_at;

            $campaign->update([
                ...Arr::except($validated, ['product_ids']),
                'code' => $nextKind === 'sale' ? null : ($validated['code'] ?? $campaign->code),
                'status' => $this->resolveStatus($startAt, $endAt),
            ]);

            if (array_key_exists('product_ids', $validated) || array_key_exists('scope', $validated)) {
                $this->syncProductScope($campaign, $shopOwner->id, $nextScope, $validated['product_ids'] ?? []);
            }

            return $campaign->load('products:id,name');
        });

        return response()->json(['success' => true, 'data' => $updatedCampaign]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        if (!$this->promoTablesReady()) {
            return $this->promoTablesUnavailableResponse();
        }

        $shopOwner = Auth::guard('shop_owner')->user();

        if (!$shopOwner) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'status' => 'required|in:draft,scheduled,active,expired,disabled',
        ]);

        $campaign = PromoCampaign::where('shop_owner_id', $shopOwner->id)->findOrFail($id);
        $campaign->update(['status' => $validated['status']]);

        return response()->json(['success' => true, 'data' => $campaign->load('products:id,name')]);
    }

    public function destroy(int $id): JsonResponse
    {
        if (!$this->promoTablesReady()) {
            return $this->promoTablesUnavailableResponse();
        }

        $shopOwner = Auth::guard('shop_owner')->user();

        if (!$shopOwner) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $campaign = PromoCampaign::where('shop_owner_id', $shopOwner->id)->findOrFail($id);
        $campaign->delete();

        return response()->json(['success' => true]);
    }

    public function products(Request $request): JsonResponse
    {
        if (!$this->promoTablesReady()) {
            return $this->promoTablesUnavailableResponse();
        }

        $shopOwner = Auth::guard('shop_owner')->user();

        if (!$shopOwner) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $products = Product::query()
            ->where('shop_owner_id', $shopOwner->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'category',
                'stock_quantity',
                'price',
                'compare_at_price',
                'scheduled_sale_price',
                'sale_starts_at',
                'sale_ends_at',
            ]);

        return response()->json(['success' => true, 'data' => $products]);
    }

    private function resolveStatus(mixed $startAt, mixed $endAt): string
    {
        $now = now();
        $start = Carbon::parse($startAt);
        $end = Carbon::parse($endAt);

        if ($now->lt($start)) {
            return 'scheduled';
        }

        if ($now->gt($end)) {
            return 'expired';
        }

        return 'active';
    }

    private function syncProductScope(PromoCampaign $campaign, int $shopOwnerId, string $scope, array $productIds): void
    {
        if ($scope !== 'product_specific') {
            $campaign->products()->sync([]);
            return;
        }

        $ownedProductIds = Product::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->whereIn('id', $productIds)
            ->pluck('id')
            ->all();

        $campaign->products()->sync($ownedProductIds);
    }

    private function validateDiscountTarget(
        ShopOwner $shopOwner,
        string $kind,
        string $discountTarget,
        string $scope,
    ): ?JsonResponse {
        if ($discountTarget === 'shipping' && $kind !== 'voucher') {
            return response()->json([
                'success' => false,
                'message' => 'Shipping discounts are available as vouchers only.',
            ], 422);
        }

        if ($discountTarget === 'shipping' && $scope !== 'shop_wide') {
            return response()->json([
                'success' => false,
                'message' => 'Shipping vouchers must be shop-wide.',
            ], 422);
        }

        if ($discountTarget === 'shipping' && ! $this->shopModuleAccess->canAccess($shopOwner, 'logistics')) {
            return response()->json([
                'success' => false,
                'message' => 'Shipping vouchers require accessible Shop-owned Logistics.',
            ], 422);
        }

        return null;
    }
}
