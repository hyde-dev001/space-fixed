<?php

namespace App\Http\Controllers\UserSide;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\InventoryColorVariant;
use App\Models\InventoryItem;
use App\Models\InventorySize;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PromoCampaign;
use App\Models\ShopOwner;
use App\Models\ShopPolicyVersion;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\VoucherClaim;
use App\Models\Finance\Invoice;
use App\Models\Finance\InvoiceItem;
use App\Models\AuditLog;
use App\Services\NotificationService;
use App\Services\PaymentSettlementService;
use App\Services\PolicyAcceptanceService;
use App\Services\PromoPricingService;
use App\Support\Tax\VatInclusiveCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CheckoutController extends Controller
{
    private const PAYMENT_RETURN_TOKEN_TTL_SECONDS = 86400;

    protected NotificationService $notificationService;
    protected PromoPricingService $promoPricingService;

    public function __construct(NotificationService $notificationService, PromoPricingService $promoPricingService)
    {
        $this->notificationService = $notificationService;
        $this->promoPricingService = $promoPricingService;
    }

    private function normalizeSizeSystem(?string $rawSystem): string
    {
        $normalized = strtoupper(trim((string) $rawSystem));
        return in_array($normalized, ['US', 'UK', 'EU', 'AU', 'CN'], true) ? $normalized : 'US';
    }

    private function parseSizeComponents(?string $rawSize): array
    {
        $normalizedRaw = trim((string) $rawSize);
        if ($normalizedRaw === '') {
            return ['system' => 'US', 'value' => '', 'explicit_system' => false];
        }

        if (preg_match('/^(US|UK|EU|AU|CN)\s*[:\-]?\s*(.+)$/i', $normalizedRaw, $matches)) {
            return [
                'system' => $this->normalizeSizeSystem($matches[1] ?? null),
                'value' => trim((string) ($matches[2] ?? '')),
                'explicit_system' => true,
            ];
        }

        return ['system' => 'US', 'value' => $normalizedRaw, 'explicit_system' => false];
    }

    private function buildVariantSizeCandidates(?string $rawSize): array
    {
        $parsed = $this->parseSizeComponents($rawSize);
        $value = (string) ($parsed['value'] ?? '');
        $system = (string) ($parsed['system'] ?? 'US');
        $explicitSystem = (bool) ($parsed['explicit_system'] ?? false);
        $normalizedRaw = trim((string) $rawSize);

        if ($value === '') {
            return [];
        }

        $candidates = [];

        if ($explicitSystem) {
            $candidates[] = $system === 'US' ? $value : "{$system} {$value}";
            if ($system === 'US') {
                $candidates[] = "US {$value}";
            }
            $candidates[] = $normalizedRaw;
        } else {
            $candidates[] = $value;
            $candidates[] = "US {$value}";
            $candidates[] = $normalizedRaw;
        }

        $filtered = array_values(array_filter(array_map(fn ($item) => trim((string) $item), $candidates)));
        return array_values(array_unique($filtered));
    }

    private function resolveVariant(Product $product, ?string $size, ?string $color): ?ProductVariant
    {
        $normalizedColor = strtolower(trim((string) $color));
        $sizeCandidates = $this->buildVariantSizeCandidates($size);

        if ($normalizedColor === '' || empty($sizeCandidates)) {
            return null;
        }

        $matchingVariants = ProductVariant::where('product_id', $product->id)
            ->whereRaw('LOWER(color) = ?', [$normalizedColor])
            ->whereIn('size', $sizeCandidates)
            ->get();

        foreach ($sizeCandidates as $candidate) {
            $matched = $matchingVariants->first(fn ($variant) => (string) $variant->size === (string) $candidate);
            if ($matched) {
                return $matched;
            }
        }

        $requestedValue = strtolower((string) ($this->parseSizeComponents($size)['value'] ?? ''));
        if ($requestedValue === '') {
            return null;
        }

        $sameValueCandidates = ProductVariant::where('product_id', $product->id)
            ->whereRaw('LOWER(color) = ?', [$normalizedColor])
            ->get()
            ->filter(function ($variant) use ($requestedValue) {
                $variantValue = strtolower((string) ($this->parseSizeComponents($variant->size)['value'] ?? ''));
                return $variantValue !== '' && $variantValue === $requestedValue;
            })
            ->values();

        if ($sameValueCandidates->count() === 1) {
            return $sameValueCandidates->first();
        }

        return null;
    }

    private function filterOrderColumns(array $payload): array
    {
        static $orderColumns = null;

        if ($orderColumns === null) {
            $orderColumns = Schema::getColumnListing('orders');
        }

        $allowed = array_flip($orderColumns);

        return array_filter(
            $payload,
            static fn ($value, $key) => isset($allowed[$key]),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function resolveInventorySizeRowForCheckout(int $inventoryItemId, ?int $inventoryColorVariantId, ?string $rawSize, bool $forUpdate = true): ?InventorySize
    {
        $parsed = $this->parseSizeComponents($rawSize);
        $sizeValue = trim((string) ($parsed['value'] ?? ''));
        $sizeSystem = (string) ($parsed['system'] ?? 'US');
        $hasExplicitSystem = (bool) ($parsed['explicit_system'] ?? false);

        if ($sizeValue === '') {
            return null;
        }

        $query = InventorySize::where('inventory_item_id', $inventoryItemId)
            ->where('size', $sizeValue);

        if ($inventoryColorVariantId) {
            $query->where('inventory_color_variant_id', $inventoryColorVariantId);
        } else {
            $query->whereNull('inventory_color_variant_id');
        }

        if ($hasExplicitSystem) {
            $preferred = (clone $query)
                ->where('size_system', $sizeSystem)
                ->when($forUpdate, fn ($q) => $q->lockForUpdate())
                ->first();

            if ($preferred) {
                return $preferred;
            }
        }

        return $query->orderByRaw("CASE WHEN size_system = 'US' THEN 0 ELSE 1 END")
            ->when($forUpdate, fn ($q) => $q->lockForUpdate())
            ->first();
    }

    private function getLinkedInventoryAvailableForCheckout(InventoryItem $inventoryItem, ?string $itemSize, ?string $itemColor): int
    {
        if (!$itemSize || !$itemColor) {
            return (int) $inventoryItem->available_quantity;
        }

        $normalizedColor = strtolower(trim((string) $itemColor));

        $inventoryColorVariant = InventoryColorVariant::where('inventory_item_id', $inventoryItem->id)
            ->whereRaw('LOWER(color_name) = ?', [$normalizedColor])
            ->first();

        if (!$inventoryColorVariant) {
            return 0;
        }

        $sizeRow = $this->resolveInventorySizeRowForCheckout(
            (int) $inventoryItem->id,
            (int) $inventoryColorVariant->id,
            (string) $itemSize,
            false
        );

        if ($sizeRow) {
            return (int) $sizeRow->quantity;
        }

        return (int) $inventoryColorVariant->quantity;
    }

    private function applyInventoryDeductionForCheckout(Product $product, array $item, ?ProductVariant $resolvedVariant, int $performedBy): bool
    {
        $inventoryItem = InventoryItem::where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        if (!$inventoryItem) {
            return false;
        }

        $qty = (int) ($item['qty'] ?? 0);
        if ($qty <= 0) {
            return true;
        }

        $options = isset($item['options'])
            ? (is_string($item['options']) ? json_decode($item['options'], true) : $item['options'])
            : [];
        $itemSize = $item['size'] ?? null;
        $itemColor = $item['color'] ?? ($options['color'] ?? null);

        $quantityBefore = (int) $inventoryItem->available_quantity;
        $didSpecificDeduction = false;

        if ($itemSize && $itemColor) {
            $normalizedColor = strtolower(trim((string) $itemColor));

            $inventoryColorVariant = InventoryColorVariant::where('inventory_item_id', $inventoryItem->id)
                ->whereRaw('LOWER(color_name) = ?', [$normalizedColor])
                ->lockForUpdate()
                ->first();

            $sizeRow = $this->resolveInventorySizeRowForCheckout(
                (int) $inventoryItem->id,
                $inventoryColorVariant?->id,
                (string) $itemSize
            );

            if ($sizeRow) {
                if ((int) $sizeRow->quantity < $qty) {
                    throw new \RuntimeException("Insufficient linked inventory size stock for {$product->name}.");
                }
                $sizeRow->decrement('quantity', $qty);
                $didSpecificDeduction = true;
            }

            if ($inventoryColorVariant) {
                if ($sizeRow) {
                    // Keep color quantity derived from the sum of its size rows.
                    $recomputedColorQty = (int) InventorySize::where('inventory_item_id', $inventoryItem->id)
                        ->where('inventory_color_variant_id', $inventoryColorVariant->id)
                        ->sum('quantity');

                    $inventoryColorVariant->quantity = $recomputedColorQty;
                    $inventoryColorVariant->save();
                } else {
                    if ((int) $inventoryColorVariant->quantity < $qty) {
                        throw new \RuntimeException("Insufficient linked inventory color stock for {$product->name}.");
                    }
                    $inventoryColorVariant->decrement('quantity', $qty);
                }
                $didSpecificDeduction = true;
            }
        }

        $newTotalQty = (int) InventoryColorVariant::where('inventory_item_id', $inventoryItem->id)
            ->sum('quantity');

        if ($newTotalQty === 0) {
            $newTotalQty = (int) InventorySize::where('inventory_item_id', $inventoryItem->id)
                ->whereNull('inventory_color_variant_id')
                ->sum('quantity');
        }

        if (!$didSpecificDeduction) {
            if ((int) $inventoryItem->available_quantity < $qty) {
                throw new \RuntimeException("Insufficient linked inventory stock for {$product->name}.");
            }
            $newTotalQty = (int) $inventoryItem->available_quantity - $qty;
        }

        if ($newTotalQty < 0) {
            throw new \RuntimeException("Insufficient linked inventory stock for {$product->name}.");
        }

        $inventoryItem->available_quantity = $newTotalQty;
        $inventoryItem->save();

        StockMovement::create([
            'inventory_item_id' => $inventoryItem->id,
            'movement_type' => 'stock_out',
            'quantity_change' => -$qty,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $newTotalQty,
            'reference_type' => 'order',
            'notes' => 'Checkout deduction for order item',
            'performed_by' => $performedBy,
            'performed_at' => now(),
        ]);

        // Keep product stock derived from linked inventory immediately.
        $product->stock_quantity = $newTotalQty;
        $product->save();

        if ($resolvedVariant) {
            $resolvedVariant->refresh();
        }

        return true;
    }

    /**
     * @param array<int, array{item:array<string, mixed>, product:Product}> $shopItems
     * @return array<int, array{product_id:int, price:float, qty:int}>
     */
    private function buildPromoLineItems(array $shopItems): array
    {
        return collect($shopItems)
            ->map(function (array $shopItem): array {
                $item = $shopItem['item'];
                /** @var Product $product */
                $product = $shopItem['product'];

                return [
                    'product_id' => (int) $product->id,
                    'price' => max(0.0, (float) ($item['price'] ?? 0)),
                    'qty' => max(1, (int) ($item['qty'] ?? 1)),
                ];
            })
            ->values()
            ->all();
    }

    private function promoTablesReady(): bool
    {
        return Schema::hasTable('promo_campaigns')
            && Schema::hasTable('promo_campaign_products')
            && Schema::hasTable('voucher_claims');
    }

    private function activeCampaignsForShop(int $shopOwnerId, string $kind): Collection
    {
        if (!$this->promoTablesReady()) {
            return collect();
        }

        return PromoCampaign::query()
            ->with('products:id')
            ->where('shop_owner_id', $shopOwnerId)
            ->where('kind', $kind)
            ->where('status', 'active')
            ->where('start_at', '<=', now())
            ->where('end_at', '>=', now())
            ->where(function ($query) {
                $query->whereNull('usage_limit')
                    ->orWhereColumn('used_count', '<', 'usage_limit');
            })
            ->get();
    }

    private function claimedVoucherCampaignsForShop(int $shopOwnerId, int $userId): Collection
    {
        if (!$this->promoTablesReady()) {
            return collect();
        }

        return PromoCampaign::query()
            ->with('products:id')
            ->where('shop_owner_id', $shopOwnerId)
            ->where('kind', 'voucher')
            ->where('status', 'active')
            ->where('start_at', '<=', now())
            ->where('end_at', '>=', now())
            ->where(function ($query) {
                $query->whereNull('usage_limit')
                    ->orWhereColumn('used_count', '<', 'usage_limit');
            })
            ->whereHas('claims', function ($query) use ($shopOwnerId, $userId) {
                $query->where('shop_owner_id', $shopOwnerId)
                    ->where('user_id', $userId)
                    ->where('status', 'claimed');
            })
            ->get();
    }

    private function normalizeVoucherCode(?string $voucherCode): ?string
    {
        $normalized = trim((string) $voucherCode);
        return $normalized !== '' ? $normalized : null;
    }

    private function hasVoucherSelectionIntent(?int $voucherCampaignId, ?string $voucherCode): bool
    {
        return ($voucherCampaignId !== null && $voucherCampaignId > 0)
            || $this->normalizeVoucherCode($voucherCode) !== null;
    }

    private function voucherByCodeForCheckout(int $shopOwnerId, int $userId, ?string $voucherCode): ?PromoCampaign
    {
        $normalizedCode = $this->normalizeVoucherCode($voucherCode);

        if ($normalizedCode === null || !$this->promoTablesReady()) {
            return null;
        }

        $campaign = PromoCampaign::query()
            ->with('products:id')
            ->where('shop_owner_id', $shopOwnerId)
            ->where('kind', 'voucher')
            ->where('status', 'active')
            ->where('start_at', '<=', now())
            ->where('end_at', '>=', now())
            ->whereRaw('LOWER(code) = ?', [strtolower($normalizedCode)])
            ->where(function ($query) {
                $query->whereNull('usage_limit')
                    ->orWhereColumn('used_count', '<', 'usage_limit');
            })
            ->first();

        if (!$campaign) {
            return null;
        }

        $alreadyRedeemed = VoucherClaim::query()
            ->where('promo_campaign_id', (int) $campaign->id)
            ->where('shop_owner_id', $shopOwnerId)
            ->where('user_id', $userId)
            ->where('status', 'redeemed')
            ->exists();

        return $alreadyRedeemed ? null : $campaign;
    }

    private function availableVoucherCampaignsForCheckout(int $shopOwnerId, int $userId, ?string $voucherCode = null): Collection
    {
        $claimedVouchers = $this->claimedVoucherCampaignsForShop($shopOwnerId, $userId);
        $manualVoucher = $this->voucherByCodeForCheckout($shopOwnerId, $userId, $voucherCode);

        if (!$manualVoucher) {
            return $claimedVouchers->values();
        }

        return $claimedVouchers
            ->concat(collect([$manualVoucher]))
            ->unique('id')
            ->values();
    }

    private function pickRequestedVoucher(Collection $availableVouchers, ?int $voucherCampaignId, ?string $voucherCode): ?PromoCampaign
    {
        if ($voucherCampaignId !== null && $voucherCampaignId > 0) {
            /** @var PromoCampaign|null $campaign */
            $campaign = $availableVouchers
                ->first(fn (PromoCampaign $candidate) => (int) $candidate->id === (int) $voucherCampaignId);

            return $campaign;
        }

        $normalizedCode = $this->normalizeVoucherCode($voucherCode);
        if ($normalizedCode === null) {
            return null;
        }

        /** @var PromoCampaign|null $campaign */
        $campaign = $availableVouchers
            ->first(fn (PromoCampaign $candidate) => strtolower((string) $candidate->code) === strtolower($normalizedCode));

        return $campaign;
    }

    private function summarizeAvailableVoucher(PromoCampaign $campaign): array
    {
        return [
            'id' => (int) $campaign->id,
            'name' => (string) $campaign->name,
            'code' => $campaign->code,
            'discount_mode' => (string) $campaign->discount_mode,
            'value' => (float) $campaign->value,
            'min_spend' => (float) $campaign->min_spend,
        ];
    }

    private function summarizeAvailableVouchers(Collection $campaigns): array
    {
        return $campaigns
            ->map(fn (PromoCampaign $campaign) => $this->summarizeAvailableVoucher($campaign))
            ->values()
            ->all();
    }

    private function voucherCodeSuggestionCampaignsForCheckout(int $shopOwnerId, int $userId): Collection
    {
        if (!$this->promoTablesReady()) {
            return collect();
        }

        $redeemedCampaignIds = VoucherClaim::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('user_id', $userId)
            ->where('status', 'redeemed')
            ->pluck('promo_campaign_id');

        return PromoCampaign::query()
            ->where('shop_owner_id', $shopOwnerId)
            ->where('kind', 'voucher')
            ->where('status', 'active')
            ->where('start_at', '<=', now())
            ->where('end_at', '>=', now())
            ->whereNotNull('code')
            ->where('code', '!=', '')
            ->where(function ($query) {
                $query->whereNull('usage_limit')
                    ->orWhereColumn('used_count', '<', 'usage_limit');
            })
            ->when($redeemedCampaignIds->isNotEmpty(), function ($query) use ($redeemedCampaignIds) {
                $query->whereNotIn('id', $redeemedCampaignIds);
            })
            ->orderBy('code')
            ->get();
    }

    private function summarizeAppliedVoucher(?PromoCampaign $campaign): ?array
    {
        if (!$campaign) {
            return null;
        }

        return [
            'id' => (int) $campaign->id,
            'name' => (string) $campaign->name,
            'code' => $campaign->code,
            'scope' => (string) $campaign->scope,
            'discount_mode' => (string) $campaign->discount_mode,
            'value' => (float) $campaign->value,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $pricedLineItems
     */
    private function voucherEligibleSubtotalFromPricedLineItems(PromoCampaign $campaign, array $pricedLineItems): float
    {
        $eligibleSubtotal = 0.0;

        foreach ($pricedLineItems as $lineItem) {
            $productId = (int) ($lineItem['product_id'] ?? 0);
            if (!$this->isVoucherCampaignApplicableToProduct($campaign, $productId)) {
                continue;
            }

            $eligibleSubtotal += max(0.0, (float) ($lineItem['sale_adjusted_total'] ?? $lineItem['base_total'] ?? 0.0));
        }

        return round($eligibleSubtotal, 2);
    }

    private function isVoucherCampaignApplicableToProduct(PromoCampaign $campaign, int $productId): bool
    {
        if ((string) $campaign->scope === 'shop_wide') {
            return true;
        }

        return $campaign->products->pluck('id')->contains($productId);
    }

    /**
     * @param array<string, mixed> $pricingResult
     */
    private function buildVoucherIneligibilityMessage(?PromoCampaign $selectedVoucher, array $pricingResult): string
    {
        if (!$selectedVoucher) {
            return 'Voucher code is invalid, unavailable, or already redeemed.';
        }

        $pricedLineItems = is_array($pricingResult['line_items'] ?? null)
            ? $pricingResult['line_items']
            : [];
        $eligibleSubtotal = $this->voucherEligibleSubtotalFromPricedLineItems($selectedVoucher, $pricedLineItems);
        $minSpend = max(0.0, (float) $selectedVoucher->min_spend);

        if ($eligibleSubtotal <= 0.0) {
            return 'Selected voucher does not apply to items in your cart.';
        }

        if ($minSpend > 0.0 && $eligibleSubtotal < $minSpend) {
            return sprintf(
                'Minimum spend of PHP %s is required for this voucher (current eligible subtotal: PHP %s).',
                number_format($minSpend, 2, '.', ','),
                number_format($eligibleSubtotal, 2, '.', ',')
            );
        }

        return 'Selected voucher is not eligible for this checkout.';
    }

    /**
     * Preview promo-adjusted checkout totals for the current cart.
     */
    public function previewPromoPricing(Request $request)
    {
        $user = Auth::guard('user')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Please log in to continue.',
            ], 401);
        }

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.pid' => 'required|integer',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'disable_voucher' => 'nullable|boolean',
            'voucher_campaign_id' => 'nullable|integer',
            'voucher_code' => 'nullable|string|max:100',
        ]);

        $disableVoucher = (bool) ($validated['disable_voucher'] ?? false);
        $requestedVoucherCampaignId = isset($validated['voucher_campaign_id'])
            ? (int) $validated['voucher_campaign_id']
            : null;
        $requestedVoucherCode = $this->normalizeVoucherCode($validated['voucher_code'] ?? null);
        $hasVoucherSelectionIntent = $this->hasVoucherSelectionIntent($requestedVoucherCampaignId, $requestedVoucherCode);

        $requestedItems = collect($validated['items']);
        $productIds = $requestedItems
            ->map(fn ($item) => (int) ($item['pid'] ?? 0))
            ->filter(fn ($pid) => $pid > 0)
            ->unique()
            ->values();

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->get(['id', 'shop_owner_id'])
            ->keyBy('id');

        if ($products->count() !== $productIds->count()) {
            return response()->json([
                'success' => false,
                'message' => 'One or more products are no longer available.',
            ], 404);
        }

        $shopOwnerIds = $products
            ->pluck('shop_owner_id')
            ->filter(fn ($shopOwnerId) => !is_null($shopOwnerId))
            ->unique()
            ->values();

        if ($shopOwnerIds->count() > 1) {
            return response()->json([
                'success' => false,
                'error' => 'mixed_shop_checkout_not_allowed',
                'message' => 'You can only checkout products from one shop at a time.',
            ], 422);
        }

        $shopOwnerId = (int) $shopOwnerIds->first();

        if (!$this->promoTablesReady()) {
            $fallbackSubtotal = round(max(0.0, (float) $requestedItems->sum(fn ($item) => ((float) ($item['price'] ?? 0)) * ((int) ($item['qty'] ?? 1)))), 2);
            $vatRatePercent = 12.0;
            $vatBreakdown = VatInclusiveCalculator::extract($fallbackSubtotal, $vatRatePercent);

            return response()->json([
                'success' => true,
                'data' => [
                    'shop_owner_id' => $shopOwnerId,
                    'sale_adjusted_subtotal' => $fallbackSubtotal,
                    'voucher_discount' => 0,
                    'final_subtotal' => $fallbackSubtotal,
                    'net_subtotal' => round((float) ($vatBreakdown['net'] ?? 0), 2),
                    'vat_amount' => round((float) ($vatBreakdown['vat'] ?? 0), 2),
                    'vat_rate' => $vatRatePercent,
                    'applied_voucher' => null,
                    'available_vouchers' => [],
                    'voucher_code_suggestions' => [],
                    'voucher_error' => $hasVoucherSelectionIntent ? 'Voucher feature is not available right now.' : null,
                ],
            ]);
        }

        $shopItems = $requestedItems
            ->map(function ($item) use ($products): array {
                /** @var Product|null $product */
                $product = $products->get((int) ($item['pid'] ?? 0));

                return [
                    'item' => [
                        'price' => (float) ($item['price'] ?? 0),
                        'qty' => (int) ($item['qty'] ?? 1),
                    ],
                    'product' => $product,
                ];
            })
            ->filter(fn (array $shopItem) => $shopItem['product'] instanceof Product)
            ->values()
            ->all();

        $pricingLineItems = $this->buildPromoLineItems($shopItems);
        $activeSales = $disableVoucher
            ? collect()
            : $this->activeCampaignsForShop($shopOwnerId, 'sale');
        $availableVouchers = $this->availableVoucherCampaignsForCheckout($shopOwnerId, (int) $user->id, $requestedVoucherCode);
        $voucherCodeSuggestions = $this->voucherCodeSuggestionCampaignsForCheckout($shopOwnerId, (int) $user->id);
        $selectedVoucher = $this->pickRequestedVoucher($availableVouchers, $requestedVoucherCampaignId, $requestedVoucherCode);

        if ($disableVoucher) {
            $voucherCandidates = collect();
        } else {
            $voucherCandidates = $hasVoucherSelectionIntent
                ? ($selectedVoucher ? collect([$selectedVoucher]) : collect())
                : $availableVouchers;
        }

        $pricing = $this->promoPricingService->applySaleThenVoucher($pricingLineItems, $activeSales, $voucherCandidates);

        $appliedVoucher = $pricing['applied_voucher'] ?? null;
        $voucherError = null;

        if (!$disableVoucher && $hasVoucherSelectionIntent && !($appliedVoucher instanceof PromoCampaign)) {
            $voucherError = $this->buildVoucherIneligibilityMessage($selectedVoucher, $pricing);
        }

        $vatRatePercent = 12.0;
        $vatBreakdown = VatInclusiveCalculator::extract((float) $pricing['final_subtotal'], $vatRatePercent);

        return response()->json([
            'success' => true,
            'data' => [
                'shop_owner_id' => $shopOwnerId,
                'sale_adjusted_subtotal' => round((float) $pricing['sale_adjusted_subtotal'], 2),
                'voucher_discount' => round((float) $pricing['voucher_discount'], 2),
                'final_subtotal' => round((float) $pricing['final_subtotal'], 2),
                'net_subtotal' => round((float) ($vatBreakdown['net'] ?? 0), 2),
                'vat_amount' => round((float) ($vatBreakdown['vat'] ?? 0), 2),
                'vat_rate' => $vatRatePercent,
                'applied_voucher' => $this->summarizeAppliedVoucher($appliedVoucher),
                'available_vouchers' => $this->summarizeAvailableVouchers($availableVouchers),
                'voucher_code_suggestions' => $this->summarizeAvailableVouchers($voucherCodeSuggestions),
                'voucher_error' => $voucherError,
            ],
        ]);
    }

    /**
     * Create order from cart items
     */
    public function createOrder(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please log in to place an order.',
                ], 401);
            }

            $validated = $request->validate([
                'items' => 'required|array|min:1',
                'items.*.id' => 'required',
                'items.*.pid' => 'required|integer',
                'items.*.qty' => 'required|integer|min:1',
                'items.*.name' => 'required|string',
                'items.*.price' => 'required|numeric|min:0',
                'items.*.size' => 'nullable|string',
                'items.*.color' => 'nullable|string',
                'items.*.image' => 'nullable|string',
                'items.*.options' => 'nullable',
                'total_amount' => 'required|numeric|min:0',
                'shipping_fee' => 'nullable|numeric|min:0',
                'customer_name' => 'required|string|max:255',
                'customer_email' => 'required|email|max:255',
                'customer_phone' => 'nullable|string|max:20',
                'shipping_address' => 'required|string|max:500',
                'payment_method' => 'nullable|string|max:50',
                // Structured address fields
                'address_id' => 'nullable|integer|exists:user_addresses,id',
                'shipping_region' => 'nullable|string|max:100',
                'shipping_province' => 'nullable|string|max:100',
                'shipping_city' => 'nullable|string|max:100',
                'shipping_barangay' => 'nullable|string|max:100',
                'shipping_postal_code' => 'nullable|string|max:10',
                'shipping_address_line' => 'nullable|string|max:255',
                'disable_voucher' => 'nullable|boolean',
                'voucher_campaign_id' => 'nullable|integer',
                'voucher_code' => 'nullable|string|max:100',
            ]);

            $customerId = $user->id;
            $requestedShippingFee = max(0.0, round((float) ($validated['shipping_fee'] ?? 0), 2));
            $disableVoucher = (bool) ($validated['disable_voucher'] ?? false);
            $requestedVoucherCampaignId = isset($validated['voucher_campaign_id'])
                ? (int) $validated['voucher_campaign_id']
                : null;
            $requestedVoucherCode = $this->normalizeVoucherCode($validated['voucher_code'] ?? null);
            $hasVoucherSelectionIntent = $this->hasVoucherSelectionIntent($requestedVoucherCampaignId, $requestedVoucherCode);

            // Enforce single-shop checkout to avoid cross-shop shipping/payment conflicts.
            $selectedShopOwnerIds = collect($validated['items'])
                ->map(function ($item) {
                    $productId = (int) ($item['pid'] ?? 0);
                    if ($productId <= 0) {
                        return null;
                    }

                    return Product::where('id', $productId)->value('shop_owner_id');
                })
                ->filter(fn ($shopOwnerId) => !is_null($shopOwnerId))
                ->unique()
                ->values();

            if ($selectedShopOwnerIds->count() > 1) {
                return response()->json([
                    'success' => false,
                    'error' => 'mixed_shop_checkout_not_allowed',
                    'message' => 'You can only place an order for products from one shop at a time. Please select items from a single shop.',
                ], 422);
            }

            $activePolicyVersion = null;
            if ($selectedShopOwnerIds->count() === 1) {
                $singleShopOwnerId = (int) $selectedShopOwnerIds->first();
                $activePolicyVersion = ShopPolicyVersion::query()
                    ->where('shop_owner_id', $singleShopOwnerId)
                    ->where('status', 'published')
                    ->latest('version_number')
                    ->first();

                if ($activePolicyVersion) {
                    $providedVersionId = (int) ($request->input('accepted_shop_policy_version_id') ?? 0);
                    $policyAccepted = filter_var($request->input('policy_accepted'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;

                    if (!$policyAccepted || $providedVersionId !== (int) $activePolicyVersion->id) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Please accept the latest shop terms before proceeding to payment.',
                            'errors' => [
                                'policy_accepted' => ['Latest shop terms acceptance is required.'],
                                'accepted_shop_policy_version_id' => ['Active policy version mismatch. Please reopen and accept the latest terms.'],
                            ],
                        ], 422);
                    }
                }
            }

            // Group items by shop owner (products from same shop go to same order)
            $itemsByShop = [];
            foreach ($validated['items'] as $item) {
                // Log the item data for debugging
                Log::info('Processing checkout item', [
                    'item' => $item,
                    'pid_value' => $item['pid'] ?? 'NOT SET',
                    'pid_type' => gettype($item['pid'] ?? null),
                ]);

                $product = Product::find($item['pid']);
                if (!$product) {
                    Log::error('Product not found during checkout', [
                        'item' => $item,
                        'pid' => $item['pid'],
                        'all_products' => Product::pluck('id')->toArray(),
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => "Product not found: {$item['name']} (ID: {$item['pid']})",
                    ], 404);
                }

                // Extract variant details from cart item
                $options = isset($item['options']) ? (is_string($item['options']) ? json_decode($item['options'], true) : $item['options']) : [];
                $itemSize = $item['size'] ?? null;
                // Try to get color from direct field first, then from options
                $itemColor = $item['color'] ?? $options['color'] ?? null;

                // LOG: Check what we're extracting with FULL item dump
                Log::info('Checkout - Extracting variant details for stock check', [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'FULL_ITEM_DATA' => $item, // Log entire item to see everything
                    'item_array_keys' => array_keys($item),
                    'has_color_field' => isset($item['color']),
                    'color_value' => $item['color'] ?? 'NOT SET',
                    'has_options' => isset($item['options']),
                    'options' => $options,
                    'extracted_size' => $itemSize,
                    'extracted_color' => $itemColor,
                ]);

                // Check variant-specific stock availability
                $linkedInventory = InventoryItem::where('product_id', $product->id)
                    ->with(['colorVariants.sizes'])
                    ->first();

                if ($linkedInventory) {
                    $availableLinkedQty = $this->getLinkedInventoryAvailableForCheckout(
                        $linkedInventory,
                        $itemSize,
                        $itemColor
                    );

                    if ($availableLinkedQty < (int) $item['qty']) {
                        $variantLabel = ($itemSize && $itemColor)
                            ? " (Size {$itemSize}, Color {$itemColor})"
                            : '';

                        return response()->json([
                            'success' => false,
                            'message' => "Insufficient stock for {$product->name}{$variantLabel}. Available: {$availableLinkedQty}",
                        ], 400);
                    }
                } elseif ($itemSize && $itemColor) {
                    $variant = $this->resolveVariant($product, (string) $itemSize, (string) $itemColor);

                    if (!$variant) {
                        return response()->json([
                            'success' => false,
                            'message' => "Variant not found for {$product->name} (Size {$itemSize}, Color {$itemColor})",
                        ], 404);
                    }

                    if ($variant->quantity < $item['qty']) {
                        return response()->json([
                            'success' => false,
                            'message' => "Insufficient stock for {$product->name} (Size {$itemSize}, Color {$itemColor}). Available: {$variant->quantity}",
                        ], 400);
                    }
                } else {
                    // Fallback to product-level stock check if no variant specified
                    if ($product->stock_quantity < $item['qty']) {
                        return response()->json([
                            'success' => false,
                            'message' => "Insufficient stock for {$product->name}. Available: {$product->stock_quantity}",
                        ], 400);
                    }
                }

                $shopOwnerId = $product->shop_owner_id;
                if (!isset($itemsByShop[$shopOwnerId])) {
                    $itemsByShop[$shopOwnerId] = [];
                }
                $itemsByShop[$shopOwnerId][] = [
                    'item' => $item,
                    'product' => $product,
                    'linked_inventory_item_id' => $linkedInventory?->id,
                ];
            }

            $createdOrders = [];
            $shopOwnerIds = array_keys($itemsByShop);
            $totalShops = count($shopOwnerIds);
            $cartSubtotal = (float) collect($validated['items'])->sum(fn($item) => ((float) $item['price']) * ((int) $item['qty']));
            $allocatedShippingFee = 0.0;
            $shopIndex = 0;
            $ordersHasShippingFee = Schema::hasColumn('orders', 'shipping_fee');
            $requestedPaymentMethod = strtolower((string) ($validated['payment_method'] ?? 'paymongo'));
            $vatRatePercent = 12.0;

            $isCodCheckout = in_array($requestedPaymentMethod, ['cod', 'cash_on_delivery', 'cash on delivery', 'cash'], true);
            if (!$isCodCheckout && !empty($shopOwnerIds)) {
                $shopsMissingPaymongoCount = ShopOwner::query()
                    ->whereIn('id', $shopOwnerIds)
                    ->where(function ($query) {
                        $query->whereNull('paymongo_secret_key')
                            ->orWhere('paymongo_secret_key', '');
                    })
                    ->count();

                if ($shopsMissingPaymongoCount > 0) {
                    return response()->json([
                        'success' => false,
                        'error' => 'shop_payment_not_configured',
                        'message' => 'One or more shops in your cart have not set up online payments yet. Please choose Cash on Delivery or remove those items.',
                    ], 503);
                }
            }

            DB::beginTransaction();
            $policyAcceptanceService = app(PolicyAcceptanceService::class);

            try {
                // Create separate order for each shop owner
                foreach ($itemsByShop as $shopOwnerId => $shopItems) {
                    $pricingLineItems = $this->buildPromoLineItems($shopItems);
                    $activeSales = $disableVoucher
                        ? collect()
                        : $this->activeCampaignsForShop((int) $shopOwnerId, 'sale');
                    $availableVouchers = $this->availableVoucherCampaignsForCheckout((int) $shopOwnerId, (int) $customerId, $requestedVoucherCode);
                    $selectedVoucher = $this->pickRequestedVoucher($availableVouchers, $requestedVoucherCampaignId, $requestedVoucherCode);

                    if ($disableVoucher) {
                        $voucherCandidates = collect();
                    } else {
                        $voucherCandidates = $hasVoucherSelectionIntent
                            ? ($selectedVoucher ? collect([$selectedVoucher]) : collect())
                            : $availableVouchers;
                    }

                    $pricingResult = $this->promoPricingService->applySaleThenVoucher($pricingLineItems, $activeSales, $voucherCandidates);

                    $selectedPricingVoucher = $pricingResult['applied_voucher'] ?? null;

                    if (!$disableVoucher && $hasVoucherSelectionIntent && !($selectedPricingVoucher instanceof PromoCampaign)) {
                        throw new \RuntimeException($this->buildVoucherIneligibilityMessage($selectedVoucher, $pricingResult));
                    }

                    $expectedRawTotal = collect($shopItems)->sum(fn ($si) => ((float) $si['item']['price']) * ((int) $si['item']['qty']));
                    $expectedItemInclusiveTotal = round(max(0.0, (float) ($pricingResult['final_subtotal'] ?? 0)), 2);
                    $expectedVatBreakdown = VatInclusiveCalculator::extract($expectedItemInclusiveTotal, $vatRatePercent);
                    $expectedOrderNetSubtotal = (float) ($expectedVatBreakdown['net'] ?? 0);
                    /** @var PromoCampaign|null $appliedVoucher */
                    $appliedVoucher = $pricingResult['applied_voucher'] ?? null;
                    $appliedVoucherDiscount = round((float) ($pricingResult['voucher_discount'] ?? 0), 2);
                    $shopIndex++;

                    if ($requestedShippingFee <= 0) {
                        $shippingFeeForOrder = 0.0;
                    } elseif ($totalShops === 1 || $cartSubtotal <= 0) {
                        $shippingFeeForOrder = $requestedShippingFee;
                    } elseif ($shopIndex === $totalShops) {
                        $shippingFeeForOrder = max(0.0, round($requestedShippingFee - $allocatedShippingFee, 2));
                    } else {
                        $shippingFeeForOrder = round(($expectedRawTotal / $cartSubtotal) * $requestedShippingFee, 2);
                    }
                    $allocatedShippingFee = round($allocatedShippingFee + $shippingFeeForOrder, 2);

                    // Duplicate guard: if an identical pending order exists for this
                    // customer + shop within the last 5 minutes, return it instead of
                    // creating a new one (prevents double-orders on retry after 500 errors).
                    $existingOrderQuery = Order::where('customer_id', $customerId)
                        ->where('shop_owner_id', $shopOwnerId)
                        ->where('total_amount', $expectedOrderNetSubtotal)
                        ->where('status', 'pending')
                        ->where('payment_status', 'pending')
                        ->whereRaw('LOWER(COALESCE(payment_method, ?)) = ?', ['paymongo', $requestedPaymentMethod])
                        ->whereNull('payment_expired_at')
                        ->where('created_at', '>=', now()->subMinutes(5));

                    if ($ordersHasShippingFee) {
                        $existingOrderQuery->whereRaw('COALESCE(shipping_fee, 0) = ?', [$shippingFeeForOrder]);
                    }

                    $existingOrder = $existingOrderQuery
                        ->latest()
                        ->first();

                    if ($existingOrder && $existingOrder->payment_expires_at && now()->greaterThan($existingOrder->payment_expires_at)) {
                        $existingOrder = null;
                    }

                    if ($existingOrder) {
                        Log::info('Duplicate order prevented – returning existing pending order', [
                            'existing_order_id'     => $existingOrder->id,
                            'existing_order_number' => $existingOrder->order_number,
                            'customer_id'           => $customerId,
                            'shop_owner_id'         => $shopOwnerId,
                        ]);
                        $existingVatAmount = $existingOrder->vat_amount !== null
                            ? max(0.0, (float) $existingOrder->vat_amount)
                            : round(max(0.0, (float) $existingOrder->total_amount) * ($vatRatePercent / 100), 2);

                        $createdOrders[] = [
                            'id'          => $existingOrder->id,
                            'order_number' => $existingOrder->order_number,
                            'total'       => ((float) $existingOrder->total_amount) + ((float) ($existingOrder->shipping_fee ?? 0)) + $existingVatAmount,
                            'items_count' => count($shopItems),
                        ];
                        continue;
                    }

                    // Create the order
                    $orderPayload = [
                        'shop_owner_id' => $shopOwnerId,
                        'customer_id' => $customerId,
                        'order_number' => Order::generateOrderNumber(),
                        'total_amount' => 0, // Item subtotal, updated after items
                        'shipping_fee' => $shippingFeeForOrder,
                        'status' => 'pending',
                        'customer_name' => $validated['customer_name'],
                        'customer_email' => $validated['customer_email'],
                        'customer_phone' => $validated['customer_phone'] ?? null,
                        'customer_address' => $validated['shipping_address'],
                        'payment_method' => $validated['payment_method'] ?? 'paymongo',
                        'payment_status' => 'pending',
                        // Store structured address data
                        'address_id' => $validated['address_id'] ?? null,
                        'shipping_region' => $validated['shipping_region'] ?? null,
                        'shipping_province' => $validated['shipping_province'] ?? null,
                        'shipping_city' => $validated['shipping_city'] ?? null,
                        'shipping_barangay' => $validated['shipping_barangay'] ?? null,
                        'shipping_postal_code' => $validated['shipping_postal_code'] ?? null,
                        'shipping_address_line' => $validated['shipping_address_line'] ?? null,
                        'accepted_shop_policy_version_id' => $activePolicyVersion
                            ? (int) $activePolicyVersion->id
                            : null,
                    ];

                    $paymentLifecyclePayload = [
                        'payment_link_created_at' => now(),
                        'payment_expires_at' => now()->addHour(),
                        'payment_failed_at' => null,
                        'payment_failure_reason' => null,
                        'payment_expired_at' => null,
                        'payment_released_at' => null,
                    ];

                    $order = Order::create($this->filterOrderColumns(array_merge($orderPayload, $paymentLifecyclePayload)));

                    if ($activePolicyVersion && (int) $activePolicyVersion->shop_owner_id === (int) $shopOwnerId) {
                        $policyAcceptanceService->record([
                            'shop_owner_id' => (int) $shopOwnerId,
                            'shop_policy_version_id' => (int) $activePolicyVersion->id,
                            'actor_user_id' => (int) $customerId,
                            'context_type' => 'order',
                            'context_id' => (int) $order->id,
                            'accepted_at' => now(),
                            'accepted_from_ip' => $request->ip(),
                            'accepted_user_agent' => (string) $request->userAgent(),
                        ]);
                    }

                    // Create order items and reduce stock
                    foreach ($shopItems as $shopItem) {
                        $item = $shopItem['item'];
                        $product = $shopItem['product'];

                        $subtotal = $item['price'] * $item['qty'];

                        // Extract options for color and image
                        $options = isset($item['options']) ? (is_string($item['options']) ? json_decode($item['options'], true) : $item['options']) : [];
                        $itemSize = $item['size'] ?? null;
                        // Try to get color from direct field first, then from options
                        $itemColor = $item['color'] ?? $options['color'] ?? null;
                        $isLinkedInventoryProduct = !empty($shopItem['linked_inventory_item_id']);
                        $resolvedVariant = null;

                        if (!$isLinkedInventoryProduct && $itemSize && $itemColor) {
                            $resolvedVariant = $this->resolveVariant($product, (string) $itemSize, (string) $itemColor);
                        }

                        $itemSizeToSave = $resolvedVariant ? (string) $resolvedVariant->size : $itemSize;
                        $itemImage = $options['image'] ?? $item['image'] ?? $product->main_image;

                        Log::info('Processing order item', [
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                            'item_data' => $item,
                            'extracted_size' => $itemSize,
                            'extracted_color' => $itemColor,
                            'has_options' => isset($item['options']),
                            'options' => $options,
                        ]);

                        // LOG: What we're saving to order_items
                        Log::info('Checkout - Creating order_item', [
                            'order_id' => $order->id,
                            'product_id' => $product->id,
                            'size_to_save' => $itemSizeToSave,
                            'color_to_save' => $itemColor,
                            'image_to_save' => $itemImage,
                        ]);

                        OrderItem::create([
                            'order_id' => $order->id,
                            'product_id' => $product->id,
                            'product_name' => $product->name,
                            'product_slug' => $product->slug,
                            'price' => $item['price'],
                            'quantity' => $item['qty'],
                            'subtotal' => $subtotal,
                            'size' => $itemSizeToSave,
                            'color' => $itemColor,
                            'product_image' => $itemImage,
                        ]);

                        // Reduce variant-specific stock quantity
                        if (!$isLinkedInventoryProduct && $itemSize && $itemColor) {
                            Log::info('Checkout - Looking for variant to decrement', [
                                'product_id' => $product->id,
                                'size' => $itemSize,
                                'color' => $itemColor,
                                'qty_to_reduce' => $item['qty']
                            ]);

                            if ($resolvedVariant) {
                                Log::info('Checkout - Variant FOUND, decrementing now', [
                                    'variant_id' => $resolvedVariant->id,
                                    'before_quantity' => $resolvedVariant->quantity,
                                    'reducing_by' => $item['qty']
                                ]);

                                $resolvedVariant->decrement('quantity', $item['qty']);

                                // Refresh to get updated value
                                $resolvedVariant->refresh();

                                Log::info('Variant stock decremented', [
                                    'product_id' => $product->id,
                                    'variant_id' => $resolvedVariant->id,
                                    'size' => $resolvedVariant->size,
                                    'color' => $itemColor,
                                    'quantity_reduced' => $item['qty'],
                                    'remaining' => $resolvedVariant->quantity,
                                ]);
                            } else {
                                Log::warning('Variant NOT FOUND for stock deduction', [
                                    'product_id' => $product->id,
                                    'size' => $itemSize,
                                    'color' => $itemColor,
                                    'searched_product_id' => $product->id,
                                ]);
                            }
                        } elseif (!$isLinkedInventoryProduct) {
                            Log::warning('Missing size or color for variant stock deduction', [
                                'product_id' => $product->id,
                                'product_name' => $product->name,
                                'size' => $itemSize,
                                'color' => $itemColor,
                                'has_size' => !empty($itemSize),
                                'has_color' => !empty($itemColor),
                            ]);
                        }

                        // Keep inventory as source-of-truth for linked products; fallback to product-level decrement otherwise.
                        $appliedInventoryDeduction = $this->applyInventoryDeductionForCheckout(
                            $product,
                            $item,
                            $resolvedVariant,
                            (int) $customerId
                        );

                        if (!$appliedInventoryDeduction) {
                            $product->decrement('stock_quantity', $item['qty']);
                        }
                    }

                    // Product pricing is VAT-inclusive: extract net + VAT from item total.
                    $itemInclusiveTotal = $expectedItemInclusiveTotal;
                    $vatBreakdown = $expectedVatBreakdown;
                    $orderNetSubtotal = (float) $vatBreakdown['net'];
                    $orderVatAmount = (float) $vatBreakdown['vat'];
                    $orderGrandTotal = round(((float) $vatBreakdown['total']) + $shippingFeeForOrder, 2);

                    $order->update($this->filterOrderColumns([
                        'total_amount' => $orderNetSubtotal,
                        'vat_amount' => $orderVatAmount,
                        'vat_rate' => $vatRatePercent,
                        'total' => $orderGrandTotal,
                    ]));

                    if ($appliedVoucher instanceof PromoCampaign) {
                        $claimToRedeem = VoucherClaim::query()
                            ->where('promo_campaign_id', (int) $appliedVoucher->id)
                            ->where('shop_owner_id', (int) $shopOwnerId)
                            ->where('user_id', (int) $customerId)
                            ->orderBy('id')
                            ->first();

                        if ($claimToRedeem && $claimToRedeem->status === 'claimed') {
                            $claimToRedeem->update([
                                'status' => 'redeemed',
                                'redeemed_at' => now(),
                            ]);

                            PromoCampaign::query()
                                ->where('id', (int) $appliedVoucher->id)
                                ->increment('used_count');
                        } elseif (!$claimToRedeem) {
                            VoucherClaim::query()->create([
                                'promo_campaign_id' => (int) $appliedVoucher->id,
                                'shop_owner_id' => (int) $shopOwnerId,
                                'user_id' => (int) $customerId,
                                'status' => 'redeemed',
                                'claimed_at' => now(),
                                'redeemed_at' => now(),
                            ]);

                            PromoCampaign::query()
                                ->where('id', (int) $appliedVoucher->id)
                                ->increment('used_count');
                        }
                    }

                    $createdOrders[] = [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'total' => $orderGrandTotal,
                        'items_count' => count($shopItems),
                        'voucher_discount' => $appliedVoucherDiscount,
                        'applied_voucher' => $this->summarizeAppliedVoucher($appliedVoucher),
                    ];

                    Log::info('Order created', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'shop_owner_id' => $shopOwnerId,
                        'customer_id' => $customerId,
                        'total' => $orderGrandTotal,
                        'item_subtotal' => $orderNetSubtotal,
                        'item_total_inclusive' => $itemInclusiveTotal,
                        'shipping_fee' => $shippingFeeForOrder,
                        'vat_amount' => $orderVatAmount,
                        'vat_rate' => $vatRatePercent,
                        'voucher_discount' => $appliedVoucherDiscount,
                        'applied_voucher_id' => $appliedVoucher?->id,
                    ]);

                    // Create or find conversation and send automatic message to customer
                    try {
                        $conversation = \App\Models\Conversation::firstOrCreate(
                            [
                                'shop_owner_id' => $shopOwnerId,
                                'customer_id' => $customerId,
                            ],
                            [
                                'status' => 'open',
                                'priority' => 'medium',
                                'assigned_to_type' => 'crm',
                                'last_message_at' => now(),
                            ]
                        );

                        // Update conversation with order_id if not set
                        if (!$conversation->order_id) {
                            $conversation->update([
                                'order_id' => $order->id,
                                'last_message_at' => now(),
                            ]);
                        }

                        // Send automatic system message about the order
                        $itemsSummary = collect($shopItems)->map(function ($shopItem) {
                            $item = $shopItem['item'];
                            $product = $shopItem['product'];
                            return "{$product->name} x{$item['qty']}";
                        })->take(3)->join(', ');

                        $productLines = collect($shopItems)->map(function ($shopItem) {
                            $item = $shopItem['item'];
                            $product = $shopItem['product'];

                            $unitPrice = isset($item['price']) ? (float) $item['price'] : 0;
                            return "- {$product->name} x{$item['qty']} (₱" . number_format($unitPrice, 2) . ")";
                        })->take(5)->values();

                        $resolveProductImageUrl = function (?string $imagePath): ?string {
                            if (!$imagePath) {
                                return null;
                            }

                            if (str_starts_with($imagePath, 'http://') || str_starts_with($imagePath, 'https://')) {
                                return $imagePath;
                            }

                            if (str_starts_with($imagePath, '/storage/')) {
                                return $imagePath;
                            }

                            if (str_starts_with($imagePath, 'storage/')) {
                                return '/' . $imagePath;
                            }

                            return '/storage/products/' . ltrim($imagePath, '/');
                        };

                        $productImageUrls = collect($shopItems)->map(function ($shopItem) use ($resolveProductImageUrl) {
                            $item = $shopItem['item'];
                            $product = $shopItem['product'];

                            $options = isset($item['options'])
                                ? (is_string($item['options']) ? json_decode($item['options'], true) : $item['options'])
                                : [];

                            $candidateImage = $options['image'] ?? $item['image'] ?? $product->main_image_url ?? $product->main_image;

                            return $resolveProductImageUrl($candidateImage);
                        })->filter()->take(5)->values()->toArray();

                        if (count($shopItems) > 3) {
                            $itemsSummary .= ' and ' . (count($shopItems) - 3) . ' more';
                        }

                        $systemMessage = "🛍️ **New Order Placed**\n\n";
                        $systemMessage .= "**Order Number:** {$order->order_number}\n";
                        $systemMessage .= "**Items:** {$itemsSummary}\n";
                        if ($productLines->isNotEmpty()) {
                            $systemMessage .= "**Products:**\n";
                            $systemMessage .= $productLines->join("\n") . "\n";
                        }
                        $systemMessage .= "**Total:** ₱" . number_format($orderGrandTotal, 2) . "\n";
                            $systemMessage .= "**Status:** Pending Payment\n\n";
                            $systemMessage .= "Thank you for your order! Please complete payment to start processing.";

                        $messageRecord = \App\Models\ConversationMessage::create([
                            'conversation_id' => $conversation->id,
                            'sender_type' => 'system',
                            'sender_id' => $shopOwnerId,
                            'content' => $systemMessage,
                            'attachments' => count($productImageUrls) > 0 ? $productImageUrls : null,
                        ]);

                        // Update conversation last message time
                        $conversation->update(['last_message_at' => $messageRecord->created_at]);

                        Log::info('Order conversation created and message sent', [
                            'conversation_id' => $conversation->id,
                            'order_id' => $order->id,
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Failed to create order conversation/message', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    // Send notification to shop owner about new retail order
                    try {
                        $this->notificationService->notifyNewOrder(
                            shopOwnerId: $shopOwnerId,
                            orderData: [
                                'order_id' => $order->id,
                                'order_number' => $order->order_number,
                                'total' => number_format($orderGrandTotal, 2),
                                'items_count' => count($shopItems),
                                'customer_name' => $validated['customer_name'],
                                'customer_email' => $validated['customer_email'],
                            ]
                        );
                        Log::info('Shop owner notified of new order', [
                            'shop_owner_id' => $shopOwnerId,
                            'order_number' => $order->order_number,
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Failed to send shop owner notification', [
                            'shop_owner_id' => $shopOwnerId,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    // Send notification to all staff with order management permissions
                    try {
                        $staffNotified = $this->notificationService->notifyAllStaffNewOrder(
                            shopOwnerId: $shopOwnerId,
                            orderData: [
                                'order_id' => $order->id,
                                'order_number' => $order->order_number,
                                'total' => number_format($orderGrandTotal, 2),
                                'items_count' => count($shopItems),
                                'customer_name' => $validated['customer_name'],
                                'customer_email' => $validated['customer_email'],
                            ]
                        );
                        Log::info("Notified {$staffNotified} staff members of new order", [
                            'shop_owner_id' => $shopOwnerId,
                            'order_number' => $order->order_number,
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Failed to send staff notifications', [
                            'shop_owner_id' => $shopOwnerId,
                            'order_id' => $order->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                DB::commit();

                // Clear user's cart after successful order
                if ($customerId) {
                    \App\Models\CartItem::where('user_id', $customerId)->delete();
                    Log::info('Cart cleared after order', ['user_id' => $customerId]);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Order(s) created successfully',
                    'order'   => !empty($createdOrders) ? $createdOrders[0] : null,
                    'orders'  => $createdOrders,
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::error('Order creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create order: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get customer orders
     */
    public function myOrders()
    {
        try {
            $user = Auth::guard('user')->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 401);
            }

            $orders = Order::where('customer_id', $user->id)
                ->with(['items.product', 'shopOwner'])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($order) {
                    return [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'status' => $order->status,
                        'total_amount' => $order->total_amount,
                        'shipping_fee' => (float) ($order->shipping_fee ?? 0),
                        'vat_amount' => $order->vat_amount !== null
                            ? (float) $order->vat_amount
                            : round(((float) $order->total_amount) * 0.12, 2),
                        'vat_rate' => $order->vat_rate !== null ? (float) $order->vat_rate : 12.0,
                        'grand_total' => ((float) $order->total_amount)
                            + ((float) ($order->shipping_fee ?? 0))
                            + ($order->vat_amount !== null
                                ? (float) $order->vat_amount
                                : round(((float) $order->total_amount) * 0.12, 2)),
                        'created_at' => $order->created_at->format('Y-m-d H:i:s'),
                        'shop_id' => $order->shopOwner ? $order->shopOwner->id : null,
                        'shop_name' => $order->shopOwner->business_name ?? 'Unknown Shop',
                        'shop_address' => $order->shopOwner->business_address ?? $order->shopOwner->city_state,
                        'items_count' => $order->items->count(),
                        'items' => $order->items->map(function ($item) {
                            return [
                                'id' => $item->id,
                                'product_name' => $item->product_name,
                                'product_slug' => $item->product_slug,
                                'product_image' => $item->product_image,
                                'price' => $item->price,
                                'quantity' => $item->quantity,
                                'subtotal' => $item->subtotal,
                                'size' => $item->size,
                            ];
                        }),
                    ];
                });

            return response()->json([
                'success' => true,
                'orders' => $orders,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch orders', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch orders',
            ], 500);
        }
    }

    /**
     * Update order with PayMongo link ID
     */
    public function updatePaymentLink(Request $request, $orderId)
    {
        try {
            $user = Auth::guard('user')->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $validated = $request->validate([
                'paymongo_link_id' => 'required|string|max:255',
            ]);

            $order = Order::where('id', $orderId)
                ->where('customer_id', $user->id)
                ->firstOrFail();

            $order->update($this->filterOrderColumns([
                'paymongo_link_id' => $validated['paymongo_link_id'],
                'payment_link_created_at' => now(),
                'payment_expires_at' => now()->addHour(),
                'payment_failed_at' => null,
                'payment_failure_reason' => null,
                'payment_expired_at' => null,
            ]));

            Log::info('Order updated with PayMongo link ID', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'link_id' => $validated['paymongo_link_id'],
            ]);

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Failed to update payment link', [
                'error' => $e->getMessage(),
                'order_id' => $orderId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update payment link',
            ], 500);
        }
    }

    /**
     * Create a fresh PayMongo checkout session for an existing unpaid order.
     */
    public function retryPaymentSession(Request $request, $orderId)
    {
        try {
            $validated = $request->validate([
                'shipping_fee' => 'nullable|numeric|min:0',
                'subtotal_amount' => 'nullable|numeric|min:0',
            ]);

            $user = Auth::guard('user')->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $order = Order::with('shopOwner')
                ->where('id', $orderId)
                ->where('customer_id', $user->id)
                ->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                ], 404);
            }

            $paymentStatus = $order->payment_status instanceof \BackedEnum
                ? $order->payment_status->value
                : (string) $order->payment_status;

            if (in_array($paymentStatus, ['paid', 'completed', 'refunded'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order is already paid',
                ], 409);
            }

            $paymentMethod = strtolower((string) ($order->payment_method ?? ''));
            if (in_array($paymentMethod, ['cod', 'cash_on_delivery', 'cash on delivery'], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'This is a Cash on Delivery order and does not require online payment.',
                ], 409);
            }

            $orderStatus = $order->status instanceof \BackedEnum
                ? $order->status->value
                : (string) $order->status;

            if ($orderStatus === 'cancelled') {
                return response()->json([
                    'success' => false,
                    'message' => 'This order was cancelled. Please checkout again to create a new order.',
                ], 409);
            }

            $apiKey = $order->shopOwner?->paymongo_secret_key;
            if (!$apiKey) {
                return response()->json([
                    'success' => false,
                    'error' => 'shop_payment_not_configured',
                    'message' => 'This shop has not set up payment processing yet. Please contact the shop owner.',
                ], 503);
            }

            $fallbackSubtotal = max(0.0, (float) ($validated['subtotal_amount'] ?? 0));
            $fallbackShippingFee = max(0.0, (float) ($validated['shipping_fee'] ?? 0));

            $itemSubtotal = max(0.0, (float) $order->total_amount);
            if ($itemSubtotal <= 0 && $fallbackSubtotal > 0) {
                $itemSubtotal = $fallbackSubtotal;
            }

            $shippingFee = max(0.0, (float) ($order->shipping_fee ?? 0));
            if ($fallbackShippingFee > 0) {
                $shippingFee = max($shippingFee, $fallbackShippingFee);

                if ((float) ($order->shipping_fee ?? 0) <= 0) {
                    $order->update($this->filterOrderColumns([
                        'shipping_fee' => $shippingFee,
                    ]));
                }
            }

            $description = 'SoleSpace Order #' . $order->order_number;
            $returnTimestamp = now()->timestamp;
            $returnSignature = $this->buildPaymentReturnSignature('order', (int) $order->id, $returnTimestamp);
            $successUrl = route('payment-return.order', [
                'paymongo_success' => 1,
                'pending_order_id' => $order->id,
                'return_ts' => $returnTimestamp,
                'return_sig' => $returnSignature,
            ]);
            $failedUrl = route('payment-return.order', [
                'paymongo_failed' => 1,
                'pending_order_id' => $order->id,
                'return_ts' => $returnTimestamp,
                'return_sig' => $returnSignature,
            ]);

            $lineItems = [];
            if ($itemSubtotal > 0) {
                $lineItems[] = [
                    'currency' => 'PHP',
                    'amount' => (int) round($itemSubtotal * 100),
                    'name' => 'Product Subtotal',
                    'quantity' => 1,
                ];
            }
            if ($shippingFee > 0) {
                $lineItems[] = [
                    'currency' => 'PHP',
                    'amount' => (int) round($shippingFee * 100),
                    'name' => 'Shipping Fee',
                    'quantity' => 1,
                ];
            }

            $resolvedVatRate = $order->vat_rate !== null
                ? max(0.0, (float) $order->vat_rate)
                : 12.0;
            if ($resolvedVatRate <= 0) {
                $resolvedVatRate = 12.0;
            }

            $vatAmount = $order->vat_amount !== null
                ? max(0.0, (float) $order->vat_amount)
                : round($itemSubtotal * ($resolvedVatRate / 100), 2);

            if ($order->vat_amount === null || $order->vat_rate === null) {
                $order->update($this->filterOrderColumns([
                    'vat_amount' => $vatAmount,
                    'vat_rate' => $resolvedVatRate,
                    'total' => $itemSubtotal + $shippingFee + $vatAmount,
                ]));
            }

            $amount = $itemSubtotal + $shippingFee + $vatAmount;
            if ($amount <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid payable amount',
                ], 422);
            }

            if ($vatAmount > 0) {
                $lineItems[] = [
                    'currency' => 'PHP',
                    'amount' => (int) round($vatAmount * 100),
                    'name' => 'VAT (12%)',
                    'quantity' => 1,
                ];
            }

            if (empty($lineItems)) {
                $lineItems[] = [
                    'currency' => 'PHP',
                    'amount' => (int) round($amount * 100),
                    'name' => $description,
                    'quantity' => 1,
                ];
            }

            $paymentResponse = \Illuminate\Support\Facades\Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($apiKey . ':'),
            ])->post('https://api.paymongo.com/v1/checkout_sessions', [
                'data' => [
                    'attributes' => [
                        'success_url' => $successUrl,
                        'cancel_url' => $failedUrl,
                        'description' => $description,
                        'send_email_receipt' => false,
                        'show_description' => true,
                        'show_line_items' => true,
                        'line_items' => $lineItems,
                        'payment_method_types' => ['card', 'gcash', 'paymaya', 'grab_pay'],
                    ],
                ],
            ]);

            if ($paymentResponse->failed()) {
                $errorMsg = $paymentResponse->json('message') ?? $paymentResponse->json('error') ?? 'PayMongo API failed';
                $errors = $paymentResponse->json('errors');

                Log::error('Order retry payment session creation failed', [
                    'order_id' => $order->id,
                    'status' => $paymentResponse->status(),
                    'message' => $errorMsg,
                    'errors' => $errors,
                    'response' => $paymentResponse->json(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $errors[0]['detail'] ?? $errorMsg ?? 'Failed to create payment session',
                ], $paymentResponse->status());
            }

            $responseData = $paymentResponse->json();
            $checkoutUrl = $responseData['data']['attributes']['checkout_url'] ?? null;
            $linkId = $responseData['data']['id'] ?? null;

            if (!$checkoutUrl || !$linkId) {
                Log::error('Order retry payment session missing checkout data', [
                    'order_id' => $order->id,
                    'response' => $responseData,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Incomplete payment session response',
                ], 500);
            }

            $order->update($this->filterOrderColumns([
                'paymongo_link_id' => $linkId,
                'payment_link_created_at' => now(),
                'payment_expires_at' => now()->addHour(),
                'payment_failed_at' => null,
                'payment_failure_reason' => null,
                'payment_expired_at' => null,
            ]));

            return response()->json([
                'success' => true,
                'checkout_url' => $checkoutUrl,
                'link_id' => $linkId,
                'order_id' => $order->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Retry payment session failed for order', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create retry payment session',
            ], 500);
        }
    }

    /**
     * Verify order payment status directly with PayMongo API.
     * Called when the customer lands on /order-success after checkout.
     */
    public function verifyPayment(Request $request, $orderId)
    {
        try {
            $settlementService = app(PaymentSettlementService::class);

            $user = Auth::guard('user')->user();
            $hasValidPublicReturnSignature = $this->hasValidPublicPaymentReturnSignature($request, 'order', (int) $orderId);

            if (!$user && !$hasValidPublicReturnSignature) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }

            $orderQuery = Order::with('shopOwner')
                ->where('id', $orderId);

            if ($user) {
                $orderQuery->where('customer_id', $user->id);
            }

            $order = $orderQuery->first();

            if (!$order) {
                return response()->json(['success' => false, 'message' => 'Order not found'], 404);
            }

            // Idempotent: already verified
            if ($order->payment_status === 'paid') {
                return response()->json([
                    'success'          => true,
                    'payment_verified' => true,
                    'already_paid'     => true,
                    'order'            => $order,
                ]);
            }

            $isExpired = $settlementService->isOrderExpired($order);

            if (!$order->paymongo_link_id) {
                return response()->json([
                    'success'          => false,
                    'payment_verified' => false,
                    'message'          => 'No payment link found for this order',
                ], 404);
            }

            // Ask PayMongo for the current checkout session status
            $apiKey = $order->shopOwner?->paymongo_secret_key;
            if (!$apiKey) {
                return response()->json([
                    'success' => false,
                    'payment_verified' => false,
                    'message' => 'Payment gateway not configured for this shop',
                ], 503);
            }

            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'Authorization' => 'Basic ' . base64_encode($apiKey . ':'),
            ])->get("https://api.paymongo.com/v1/checkout_sessions/{$order->paymongo_link_id}");

            if ($response->failed()) {
                Log::error('PayMongo order session status check failed', [
                    'order_id'   => $order->id,
                    'session_id' => $order->paymongo_link_id,
                    'status'     => $response->status(),
                    'body'       => $response->json(),
                ]);
                return response()->json([
                    'success'          => false,
                    'payment_verified' => false,
                    'message'          => 'Could not reach PayMongo to verify payment',
                ], 502);
            }

            $data          = $response->json();
            $paymentStatus = $data['data']['attributes']['payment_status'] ?? null;
            // PayMongo checkout sessions use payments[0].attributes.status, not payment_status
            $payments             = $data['data']['attributes']['payments'] ?? [];
            $firstPayment         = $payments[0] ?? null;
            $firstPaymentStatus   = $firstPayment['data']['attributes']['status'] ?? ($firstPayment['attributes']['status'] ?? null);
            $paymentId            = $firstPayment['data']['id'] ?? ($firstPayment['id'] ?? $data['data']['id'] ?? null);

            $isVerified = ($paymentStatus === 'paid') || ($firstPaymentStatus === 'paid');

            Log::info('PayMongo order session check', [
                'order_id'         => $order->id,
                'session_id'       => $order->paymongo_link_id,
                'payment_status'   => $paymentStatus,
                'first_pay_status' => $firstPaymentStatus,
                'is_verified'      => $isVerified,
            ]);

            if (!$isVerified) {
                if ($isExpired) {
                    $settlementService->recordOrderPaymentFailure($order, 'paymongo_session_expired');

                    return response()->json([
                        'success' => false,
                        'payment_verified' => false,
                        'expired' => true,
                        'message' => 'Payment session expired. Please create a new payment session.',
                    ], 410);
                }

                $statusSignals = array_filter([
                    strtolower((string) $paymentStatus),
                    strtolower((string) $firstPaymentStatus),
                ]);

                $isFailed = in_array('failed', $statusSignals, true);
                $isExpiredSignal = in_array('expired', $statusSignals, true);
                $isCancelled = in_array('cancelled', $statusSignals, true) || in_array('canceled', $statusSignals, true);

                if ($isFailed || $isExpiredSignal || $isCancelled) {
                    $settlementService->recordOrderPaymentFailure(
                        $order,
                        $isExpiredSignal
                            ? 'paymongo_session_expired'
                            : ($isCancelled ? 'paymongo_payment_cancelled' : 'paymongo_payment_failed')
                    );
                }

                return response()->json([
                    'success'          => false,
                    'payment_verified' => false,
                    'payment_status'   => $paymentStatus,
                    'message'          => 'Payment has not been completed yet',
                ]);
            }

            $settlement = $settlementService->settleOrderPaid($order, (string) $paymentId, true);
            $result = $settlement['result'] ?? 'settled';
            $settledOrder = $settlement['model'] ?? $order;

            if ($result === 'expired') {
                return response()->json([
                    'success' => false,
                    'payment_verified' => false,
                    'expired' => true,
                    'message' => 'Payment session expired. Please create a new payment session.',
                ], 410);
            }

            // Downstream progression is unlocked only after payment is verified.
            try {
                if ($result === 'settled' && !$settledOrder->invoice_generated && !$settledOrder->invoice_id) {
                    $settledOrder->loadMissing('items');
                    $this->autoGenerateInvoice($settledOrder);
                }
            } catch (\Exception $invoiceError) {
                Log::warning('Failed to auto-generate invoice after payment verification', [
                    'order_id' => $settledOrder->id,
                    'error' => $invoiceError->getMessage(),
                ]);
            }

            Log::info('Order payment verified via PayMongo API', [
                'order_id'   => $settledOrder->id,
                'order_num'  => $settledOrder->order_number,
                'link_id'    => $settledOrder->paymongo_link_id,
                'payment_id' => $paymentId,
                'result'     => $result,
            ]);

            return response()->json([
                'success'          => true,
                'payment_verified' => true,
                'order'            => $settledOrder->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('Order payment verification error', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
            return response()->json([
                'success'          => false,
                'payment_verified' => false,
                'message'          => 'Verification error: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function buildPaymentReturnSignature(string $scope, int $resourceId, int $timestamp): string
    {
        return hash_hmac(
            'sha256',
            sprintf('%s:return:%d:%d', trim($scope), $resourceId, $timestamp),
            (string) config('app.key')
        );
    }

    private function hasValidPublicPaymentReturnSignature(Request $request, string $scope, int $resourceId): bool
    {
        $timestamp = (int) $request->input('return_ts', 0);
        $signature = trim((string) $request->input('return_sig', ''));

        if ($timestamp <= 0 || $signature === '') {
            return false;
        }

        $now = now()->timestamp;
        if ($timestamp > ($now + 300)) {
            return false;
        }

        if (($now - $timestamp) > self::PAYMENT_RETURN_TOKEN_TTL_SECONDS) {
            return false;
        }

        $expected = $this->buildPaymentReturnSignature($scope, $resourceId, $timestamp);
        return hash_equals($expected, $signature);
    }

    /**
     * Get order details for confirmation page
     */
    public function getOrderDetails($orderId)
    {
        try {
            $user = Auth::guard('user')->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $order = Order::where('id', $orderId)
                ->where('customer_id', $user->id)
                ->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'order' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'total_amount' => $order->total_amount,
                    'status' => $order->status,
                    'payment_status' => $order->payment_status,
                    'payment_method' => $order->payment_method,
                    'created_at' => $order->created_at->format('M d, Y'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch order details', [
                'error' => $e->getMessage(),
                'order_id' => $orderId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch order details',
            ], 500);
        }
    }

    /**
     * Auto-generate invoice for an order based on payment method
     */
    protected function autoGenerateInvoice(Order $order): ?Invoice
    {
        // Don't create duplicate invoices
        if ($order->invoice_generated || $order->invoice_id) {
            return $order->invoice;
        }

        // Keep fulfillment progression blocked for online payments until paid.
        $paymentMethod = strtolower((string) ($order->payment_method ?? 'paymongo'));
        $onlinePaymentMethods = [
            'paymongo',
            'paypal',
            'stripe',
            'gcash',
            'maya',
            'online',
            'card',
            'credit_card',
            'debit_card',
            'bank_transfer'
        ];

        if (in_array($paymentMethod, $onlinePaymentMethods, true) && $order->payment_status !== 'paid') {
            Log::info('Skipping invoice generation for unpaid online order reservation', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'payment_method' => $paymentMethod,
                'payment_status' => $order->payment_status,
            ]);

            return null;
        }

        try {
            // Generate invoice reference
            $prefix = 'INV';
            $date = now()->format('Ymd');
            $random = strtoupper(substr(uniqid(), -4));
            $reference = "{$prefix}-{$date}-{$random}";

            // Determine invoice status based on payment method
            $paymentMethod = strtolower($order->payment_method ?? 'paymongo');

            $invoiceStatus = 'sent'; // default
            $paymentDate = null;
            $dueDate = now()->addDays(15);

            if (in_array($paymentMethod, $onlinePaymentMethods)) {
                $invoiceStatus = 'paid';
                $paymentDate = now();
                $dueDate = null;
            } elseif (in_array($paymentMethod, ['cod', 'cash_on_delivery', 'cash'])) {
                $invoiceStatus = 'sent';
                $dueDate = now()->addDays(7); // COD: due on delivery
            } elseif ($paymentMethod === 'check') {
                $invoiceStatus = 'sent';
                $dueDate = now()->addDays(30);
            }

            // Calculate tax (12% VAT)
            $baseAmount = $order->total_amount / 1.12;
            $taxAmount = round($order->total_amount - $baseAmount, 2);

            // Create the invoice
            $invoice = Invoice::create([
                'shop_id' => $order->shop_owner_id,
                'reference' => $reference,
                'customer_name' => $order->customer_name,
                'customer_email' => $order->customer_email,
                'date' => now(),
                'due_date' => $dueDate,
                'total' => $order->total_amount,
                'tax_amount' => $taxAmount,
                'status' => $invoiceStatus,
                'payment_date' => $paymentDate,
                'payment_method' => in_array($paymentMethod, ['cod', 'cash_on_delivery']) ? 'cod' : $paymentMethod,
                'job_order_id' => $order->id,
                'notes' => "Auto-generated from Order #{$order->order_number}",
            ]);

            // Log payment processing for online payment methods
            if (in_array($paymentMethod, $onlinePaymentMethods)) {
                activity()
                    ->performedOn($order)
                    ->withProperties([
                        'order_number' => $order->order_number,
                        'customer_name' => $order->customer_name,
                        'amount_paid' => $order->total_amount,
                        'payment_method' => ucfirst($paymentMethod),
                        'payment_status' => 'paid',
                        'invoice_reference' => $reference,
                        'auto_processed' => true,
                    ])
                    ->log("Order payment processed: {$order->order_number} - ₱" . number_format($order->total_amount, 2));
            }

            // Get default revenue account (if finance module is set up)
            $revenueAccount = null;
            // Account model not yet implemented - leave account_id as null for now

            // Create invoice items from order items
            if ($order->relationLoaded('items') && $order->items->count() > 0) {
                foreach ($order->items as $orderItem) {
                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'description' => $orderItem->product_name .
                            ($orderItem->size ? " (Size: {$orderItem->size})" : '') .
                            ($orderItem->color ? " (Color: {$orderItem->color})" : ''),
                        'quantity' => $orderItem->quantity,
                        'unit_price' => $orderItem->price,
                        'tax_rate' => 12.00,
                        'amount' => $orderItem->subtotal,
                        'account_id' => null,
                    ]);
                }
            }

            // Update order
            $order->update([
                'invoice_generated' => true,
                'invoice_id' => $invoice->id,
            ]);

            // Audit log
            AuditLog::create([
                'shop_owner_id' => $order->shop_owner_id,
                'action' => 'auto_generate_invoice',
                'target_type' => 'invoice',
                'target_id' => $invoice->id,
                'metadata' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'invoice_reference' => $reference,
                    'payment_method' => $order->payment_method,
                    'invoice_status' => $invoiceStatus,
                ]
            ]);

            Log::info('Auto-generated invoice for order', [
                'order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'invoice_reference' => $reference,
                'status' => $invoiceStatus,
            ]);

            return $invoice;
        } catch (\Exception $e) {
            Log::error('Failed to auto-generate invoice', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
