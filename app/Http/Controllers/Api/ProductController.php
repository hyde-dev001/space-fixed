<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductColorVariant;
use App\Models\ProductColorVariantImage;
use App\Models\PriceChangeRequest;
use App\Models\InventoryItem;
use App\Models\ShopOwner;
use App\Models\ShopOwnerSubscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class ProductController extends Controller
{
    /**
     * Get the shop owner ID for the authenticated user
     * Returns shop_owner's ID directly, or staff member's shop_owner_id.
     * Shop-owner-only routes must never be resolved from a stale staff session.
     */
    private function getAuthenticatedShopOwnerId()
    {
        $request = request();
        $routeName = $request instanceof Request ? (string) optional($request->route())->getName() : '';
        $requestPath = $request instanceof Request ? strtolower(trim((string) $request->path(), '/')) : '';
        $isShopOwnerRoute = str_starts_with($routeName, 'shop_owner.')
            || str_contains($requestPath, 'api/shop-owner/');

        // Shop-owner-only endpoints must stay bound to the authenticated shop owner,
        // even when the same browser also has an unrelated staff/user session.
        if ($isShopOwnerRoute) {
            if (Auth::guard('shop_owner')->check()) {
                return (int) Auth::guard('shop_owner')->id();
            }

            throw new \Exception('Shop owner authentication is required for this route.');
        }

        $user = Auth::guard('user')->user();
        if ($user && !empty($user->shop_owner_id)) {
            return (int) $user->shop_owner_id;
        }

        if (Auth::guard('shop_owner')->check()) {
            return (int) Auth::guard('shop_owner')->id();
        }

        // If no shop_owner_id found, throw an error instead of returning null
        throw new \Exception('User is not authorized to create products. Only shop owners and staff can create products.');
    }

    private function getActivePremiumSubscription(int $shopOwnerId): ?ShopOwnerSubscription
    {
        return ShopOwnerSubscription::where('shop_owner_id', $shopOwnerId)
            ->showroomEntitled()
            ->latest('ends_at')
            ->latest('id')
            ->first();
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

    private function canonicalizeColorName(string $colorName): string
    {
        $parts = preg_split('/\+/', $colorName) ?: [];
        $normalized = [];

        foreach ($parts as $part) {
            $cleaned = trim((string) preg_replace('/\s+/', ' ', (string) $part));
            if ($cleaned === '') {
                continue;
            }

            $display = ucwords(strtolower($cleaned));
            $normalized[strtolower($display)] = $display;
        }

        if (empty($normalized)) {
            return trim((string) preg_replace('/\s+/', ' ', $colorName));
        }

        ksort($normalized, SORT_NATURAL | SORT_FLAG_CASE);

        return implode(' + ', array_values($normalized));
    }

    private function normalizeColorIdentity(string $colorName): string
    {
        return strtolower($this->canonicalizeColorName($colorName));
    }

    private function isDuplicateColorConstraintError(\Throwable $exception): bool
    {
        if (!$exception instanceof QueryException) {
            return false;
        }

        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $message = strtolower((string) $exception->getMessage());

        if ($sqlState !== '23000') {
            return false;
        }

        return str_contains($message, 'duplicate')
            && (
                str_contains($message, 'product_color_variants')
                || str_contains($message, 'color_name')
                || str_contains($message, 'product_id')
            );
    }

    /**
     * Validate premium entitlement and optional slot availability for showroom actions.
     */
    private function enforceShowroomEntitlement(
        int $shopOwnerId,
        bool $requireFreeSlot = false,
        ?int $exceptProductId = null
    ): ?JsonResponse {
        $shopOwner = ShopOwner::find($shopOwnerId);

        if (!$shopOwner) {
            return response()->json([
                'success' => false,
                'message' => 'Shop owner not found.',
            ], 404);
        }

        $businessType = $this->normalizeBusinessType((string) $shopOwner->business_type);
        if (!in_array($businessType, ['retail', 'both'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Virtual showroom is only available for retail-capable shops.',
                'business_type' => $businessType,
            ], 403);
        }

        $subscription = $this->getActivePremiumSubscription($shopOwnerId);

        if (!$subscription) {
            return response()->json([
                'success' => false,
                'message' => 'A valid premium subscription is required for virtual showroom actions.',
            ], 403);
        }

        if (!$requireFreeSlot) {
            return null;
        }

        $slotLimit = max((int) $subscription->showroom_slot_limit, 0);

        $usedSlots = Product::where('shop_owner_id', $shopOwnerId)
            ->where('is_active', true)
            ->where('is_featured', true)
            ->when($exceptProductId, function ($q) use ($exceptProductId) {
                $q->where('id', '!=', $exceptProductId);
            })
            ->count();

        if ($usedSlots >= $slotLimit) {
            return response()->json([
                'success' => false,
                'message' => 'Showroom slot limit reached for your active premium plan.',
                'slot_limit' => $slotLimit,
                'used_slots' => $usedSlots,
            ], 409);
        }

        return null;
    }

    private function isShowroomImageType(?string $imageType): bool
    {
        $normalized = strtolower(trim((string) $imageType));

        return in_array($normalized, [
            'showroom',
            'virtual_showroom',
            'showroom_360',
            '360',
        ], true);
    }

    /**
     * Get all active products (for customers)
     */
    public function index(Request $request)
    {
        try {
            $query = QueryBuilder::for(Product::class)
                ->allowedFilters([
                    // Exact token match for comma-separated category values.
                    // e.g. filter[category]=men must NOT match "shoes,women" because "women" ≠ "men".
                    AllowedFilter::callback('category', function ($query, $value) {
                        $token = strtolower(trim((string) $value));
                        $query->where(function ($q) use ($token) {
                            $q->whereRaw('LOWER(category) = ?', [$token])
                                ->orWhereRaw('LOWER(category) LIKE ?', [$token . ',%'])
                                ->orWhereRaw('LOWER(category) LIKE ?', ['%,' . $token])
                                ->orWhereRaw('LOWER(category) LIKE ?', ['%,' . $token . ',%']);
                        });
                    }),
                    AllowedFilter::exact('shop_id', 'shop_owner_id'),
                    AllowedFilter::partial('search', 'name'),
                    AllowedFilter::scope('search_all'),
                ])
                ->allowedSorts(['price', 'name', 'created_at', 'sales_count'])
                ->defaultSort('-created_at')
                ->where('is_active', true)
                ->withSum('variants as variants_stock_quantity', 'quantity')
                ->with([
                    'shopOwner:id,first_name,last_name,business_name,business_type,shop_latitude,shop_longitude',
                    'colorVariants' => function ($query) {
                        $query->active()->orderBy('sort_order');
                    },
                    'colorVariants.images' => function ($query) {
                        $query->whereRaw("LOWER(COALESCE(image_type, '')) NOT IN ('showroom', 'virtual_showroom', 'showroom_360', '360')");
                        $query->orderBy('sort_order');
                    }
                ]);

            // Filter by business type - only show products from retail or both shops
            $query->whereHas('shopOwner', function ($q) {
                $q->where('status', 'approved')
                    ->where(function ($subQuery) {
                        $subQuery->where('business_type', 'retail')
                            ->orWhere('business_type', 'both');
                    });
            });

            $products = $query->paginate($request->get('per_page', 12));

            $inventoryStockByProductId = InventoryItem::query()
                ->whereIn('product_id', $products->getCollection()->pluck('id')->all())
                ->whereNotNull('product_id')
                ->pluck('available_quantity', 'product_id');

            // Transform products to include full image URLs and shop owner info
            $products->getCollection()->transform(function ($product) use ($inventoryStockByProductId) {
                $product->main_image = $product->main_image_url;

                $linkedInventoryStock = $inventoryStockByProductId->get($product->id);
                $variantStockQuantity = (int) ($product->variants_stock_quantity ?? 0);
                $displayStockQuantity = $linkedInventoryStock !== null
                    ? (int) $linkedInventoryStock
                    : ($variantStockQuantity > 0 ? $variantStockQuantity : (int) $product->stock_quantity);

                $product->stock_quantity = $displayStockQuantity;

                $mediaImages = collect($product->image_urls ?? [])
                    ->pluck('url')
                    ->filter();

                $variantImages = collect($product->colorVariants ?? [])
                    ->flatMap(function ($variant) {
                        return collect($variant->images ?? [])->map(function ($img) {
                            return $img->image_url;
                        });
                    })
                    ->filter();

                $allImages = $mediaImages
                    ->merge($variantImages)
                    ->unique()
                    ->values();

                $product->gallery_images = $allImages
                    ->filter(fn($url) => $url && $url !== $product->main_image)
                    ->values();

                $product->hover_image = $allImages
                    ->first(fn($url) => $url && $url !== $product->main_image)
                    ?? null;

                // Add shop_owner for frontend compatibility
                if ($product->shopOwner) {
                    $product->shop_owner = [
                        'id'            => $product->shopOwner->id,
                        'name'          => $product->shopOwner->business_name ?: ($product->shopOwner->first_name . ' ' . $product->shopOwner->last_name),
                        'business_name' => $product->shopOwner->business_name,
                        'latitude'      => $product->shopOwner->shop_latitude  ? (float) $product->shopOwner->shop_latitude  : null,
                        'longitude'     => $product->shopOwner->shop_longitude ? (float) $product->shopOwner->shop_longitude : null,
                    ];
                    // Unset the relation so it doesn't overwrite the shop_owner attribute during serialization
                    $product->unsetRelation('shopOwner');
                }

                // Add average rating from reviews
                $product->average_rating = \App\Models\ProductReview::getAverageRating($product->id);

                return $product;
            });

                        return response()->json([
                'success' => true,
                'products' => $products,
                        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                            ->header('Pragma', 'no-cache')
                            ->header('Expires', '0');
        } catch (\Exception $e) {
            Log::error('Error fetching products', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch products',
            ], 500);
        }
    }

    /**
     * Get single product by slug
     */
    public function show($slug)
    {
        try {
            $product = Product::where('slug', $slug)
                ->where('is_active', true)
                ->with([
                    'shopOwner:id,name',
                    'colorVariants' => function ($query) {
                        $query->active()->orderBy('sort_order');
                    },
                    'colorVariants.images' => function ($query) {
                        $query->whereRaw("LOWER(COALESCE(image_type, '')) NOT IN ('showroom', 'virtual_showroom', 'showroom_360', '360')");
                        $query->orderBy('sort_order');
                    }
                ])
                ->firstOrFail();

            // Increment view count
            $product->incrementViews();

            return response()->json([
                'success' => true,
                'product' => $product,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);
        }
    }

    /**
     * Get products for shop owner or staff (their products)
     */
    public function myProducts(Request $request)
    {
        try {
            // Try shop_owner guard first, then fall back to user guard
            $user = Auth::guard('shop_owner')->user() ?? Auth::guard('user')->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = $this->getAuthenticatedShopOwnerId();

            $query = Product::where('shop_owner_id', $shopOwnerId);

            // Include inactive products for owner/staff
            if (!$request->get('include_inactive')) {
                $query->where('is_active', true);
            }

            $products = QueryBuilder::for($query)
                ->allowedFilters(['category', 'is_active'])
                ->allowedSorts(['name', 'price', 'created_at', 'stock_quantity'])
                ->defaultSort('-created_at')
                ->get();

            // Compute latest sold quantity from real order items so sales metrics remain accurate
            // even when products.sales_count is stale in long-lived deployments.
            $salesByProductId = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.shop_owner_id', $shopOwnerId)
                ->whereIn('orders.status', ['processing', 'shipped', 'delivered', 'completed'])
                ->where(function ($paymentQuery) {
                    $paymentQuery->whereNull('orders.payment_status')
                        ->orWhere('orders.payment_status', '!=', 'refunded');
                })
                ->whereNotNull('order_items.product_id')
                ->groupBy('order_items.product_id')
                ->select('order_items.product_id', DB::raw('SUM(order_items.quantity) as sold_qty'))
                ->pluck('sold_qty', 'order_items.product_id');

            $salesByProductName = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.shop_owner_id', $shopOwnerId)
                ->whereIn('orders.status', ['processing', 'shipped', 'delivered', 'completed'])
                ->where(function ($paymentQuery) {
                    $paymentQuery->whereNull('orders.payment_status')
                        ->orWhere('orders.payment_status', '!=', 'refunded');
                })
                ->whereNull('order_items.product_id')
                ->whereNotNull('order_items.product_name')
                ->groupBy(DB::raw('LOWER(TRIM(order_items.product_name))'))
                ->select(DB::raw('LOWER(TRIM(order_items.product_name)) as product_key'), DB::raw('SUM(order_items.quantity) as sold_qty'))
                ->pluck('sold_qty', 'product_key');

            Log::info('myProducts context', [
                'resolved_shop_owner_id' => $shopOwnerId,
                'user_guard_id' => Auth::guard('user')->id(),
                'user_guard_shop_owner_id' => Auth::guard('user')->user()?->shop_owner_id,
                'shop_owner_guard_id' => Auth::guard('shop_owner')->id(),
                'include_inactive' => $request->get('include_inactive'),
                'products_count' => $products->count(),
            ]);

            // Transform products to include full image URLs
            $products->transform(function ($product) use ($salesByProductId, $salesByProductName) {
                $product->main_image = $product->main_image_url;

                $productIdSales = (int) ($salesByProductId->get($product->id) ?? 0);
                $productNameKey = strtolower(trim((string) ($product->name ?? '')));
                $nameMatchedSales = $productNameKey !== ''
                    ? (int) ($salesByProductName->get($productNameKey) ?? 0)
                    : 0;

                $product->sales_count = max((int) ($product->sales_count ?? 0), $productIdSales, $nameMatchedSales);
                return $product;
            });

            return response()->json([
                'success' => true,
                'products' => $products,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching shop owner products', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch products',
            ], 500);
        }
    }

    /**
     * Get showroom entitlement and slot availability for the authenticated shop context.
     */
    public function showroomEntitlement(Request $request)
    {
        try {
            $shopOwnerId = $this->getAuthenticatedShopOwnerId();

            $shopOwner = ShopOwner::find($shopOwnerId);
            if (!$shopOwner) {
                return response()->json([
                    'success' => false,
                    'message' => 'Shop owner not found.',
                ], 404);
            }

            $businessType = $this->normalizeBusinessType((string) $shopOwner->business_type);
            $isEligible = in_array($businessType, ['retail', 'both'], true);

            $subscription = $isEligible
                ? $this->getActivePremiumSubscription((int) $shopOwnerId)
                : null;

            $hasActiveSubscription = (bool) $subscription;
            $slotLimit = $subscription ? max((int) $subscription->showroom_slot_limit, 0) : 0;

            $usedSlots = Product::where('shop_owner_id', $shopOwnerId)
                ->where('is_active', true)
                ->where('is_featured', true)
                ->count();

            $contextProductId = $request->integer('product_id');
            $contextProductFeatured = false;

            if (!empty($contextProductId)) {
                $contextProduct = Product::where('id', $contextProductId)
                    ->where('shop_owner_id', $shopOwnerId)
                    ->first();

                if (!$contextProduct) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Product context not found for this shop.',
                    ], 404);
                }

                $contextProductFeatured = (bool) $contextProduct->is_featured;
            }

            $effectiveUsedSlots = max($usedSlots - ($contextProductFeatured ? 1 : 0), 0);
            $remainingSlots = max($slotLimit - $effectiveUsedSlots, 0);

            $canUseShowroom = $isEligible
                && $hasActiveSubscription
                && ($remainingSlots > 0 || $contextProductFeatured);

            $status = !$isEligible
                ? 'not_eligible'
                : ($hasActiveSubscription ? 'active' : 'inactive');

            return response()->json([
                'success' => true,
                'entitlement' => [
                    'business_type' => $businessType,
                    'is_eligible' => $isEligible,
                    'status' => $status,
                    'has_active_subscription' => $hasActiveSubscription,
                    'plan_name' => $subscription?->plan_name,
                    'showroom_slot_limit' => $slotLimit,
                    'used_slots' => $effectiveUsedSlots,
                    'remaining_slots' => $remainingSlots,
                    'context_product_featured' => $contextProductFeatured,
                    'can_upload_360' => $canUseShowroom,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching showroom entitlement', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch showroom entitlement.',
            ], 500);
        }
    }

    /**
     * Create new product (shop owner or staff)
     */
    public function store(Request $request)
    {
        try {
            $user = Auth::guard('shop_owner')->user() ?? Auth::guard('user')->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'price' => 'required|numeric|min:0|max:999999.99',
                'compare_at_price' => 'nullable|numeric|min:0|max:999999.99',
                'brand' => 'nullable|string|max:100',
                'category' => 'nullable|string|max:255',
                'stock_quantity' => 'required|integer|min:0',
                'inventory_item_id' => 'nullable|integer|exists:inventory_items,id',
                'is_featured' => 'sometimes|boolean',
                'sizes_available' => 'nullable|array',
                'colors_available' => 'nullable|array',
                'sku' => 'nullable|string|max:100',
                'weight' => 'nullable|numeric|min:0',
                'main_image' => 'nullable|string',
                'additional_images' => 'nullable|array',
                'variants' => 'nullable|array',
                'variants.*.size' => 'required',
                'variants.*.color' => 'required|string',
                'variants.*.quantity' => 'required|integer|min:0',
                'variants.*.image' => 'nullable|string',
                'variants.*.sku' => 'nullable|string',
            ]);

            // Determine shop_owner_id for both shop owners and staff
            try {
                $validated['shop_owner_id'] = $this->getAuthenticatedShopOwnerId();
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 403);
            }
            $validated['is_active'] = true;

            if (!empty($validated['is_featured'])) {
                $entitlementError = $this->enforceShowroomEntitlement((int) $validated['shop_owner_id'], true);
                if ($entitlementError) {
                    return $entitlementError;
                }
            }

            $linkedInventoryItem = null;
            if (!empty($validated['inventory_item_id'])) {
                $linkedInventoryItem = InventoryItem::where('id', (int) $validated['inventory_item_id'])
                    ->where('shop_owner_id', (int) $validated['shop_owner_id'])
                    ->first();

                if (!$linkedInventoryItem) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Selected inventory item is invalid for this shop.',
                    ], 422);
                }

                if (!empty($linkedInventoryItem->product_id)) {
                    $linkedProduct = Product::withTrashed()
                        ->select('id', 'deleted_at')
                        ->find((int) $linkedInventoryItem->product_id);

                    // Allow re-linking when inventory still points to a deleted/missing product.
                    if (!$linkedProduct || $linkedProduct->trashed()) {
                        $linkedInventoryItem->update(['product_id' => null]);
                        $linkedInventoryItem->refresh();
                    }
                }

                if (!empty($linkedInventoryItem->product_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This inventory item is already linked to a product.',
                    ], 422);
                }
            }

            unset($validated['inventory_item_id']);

            DB::beginTransaction();
            try {
                $product = Product::create($validated);

                if ($linkedInventoryItem) {
                    $linkedInventoryItem->update([
                        'product_id' => $product->id,
                    ]);
                }

                // Create variants if provided. Normalize duplicate size/color entries
                // to avoid SQL unique key violations on (product_id, size, color).
                if (isset($validated['variants']) && is_array($validated['variants'])) {
                    $variantBuckets = [];

                    foreach ($validated['variants'] as $variantData) {
                        $size = trim((string) ($variantData['size'] ?? ''));
                        $color = trim((string) ($variantData['color'] ?? ''));
                        $quantity = (int) ($variantData['quantity'] ?? 0);

                        if ($size === '' || $color === '') {
                            continue;
                        }

                        $canonicalColor = $this->canonicalizeColorName($color);

                        $bucketKey = strtolower($size) . '|' . $this->normalizeColorIdentity($canonicalColor);

                        if (!isset($variantBuckets[$bucketKey])) {
                            $variantBuckets[$bucketKey] = [
                                'size' => $size,
                                'color' => $canonicalColor,
                                'quantity' => 0,
                                'image' => $variantData['image'] ?? null,
                                'sku' => $variantData['sku'] ?? null,
                            ];
                        }

                        $variantBuckets[$bucketKey]['quantity'] += max($quantity, 0);

                        if (empty($variantBuckets[$bucketKey]['image']) && !empty($variantData['image'])) {
                            $variantBuckets[$bucketKey]['image'] = $variantData['image'];
                        }

                        if (empty($variantBuckets[$bucketKey]['sku']) && !empty($variantData['sku'])) {
                            $variantBuckets[$bucketKey]['sku'] = $variantData['sku'];
                        }
                    }

                    foreach ($variantBuckets as $variantRow) {
                        ProductVariant::create([
                            'product_id' => $product->id,
                            'size' => $variantRow['size'],
                            'color' => $variantRow['color'],
                            'quantity' => $variantRow['quantity'],
                            'image' => $variantRow['image'],
                            'sku' => $variantRow['sku'],
                            'is_active' => true,
                        ]);
                    }
                }

                DB::commit();

                Log::info('Product created with variants', [
                    'product_id' => $product->id,
                    'shop_owner_id' => $validated['shop_owner_id'],
                    'user_guard_id' => Auth::guard('user')->id(),
                    'user_guard_shop_owner_id' => Auth::guard('user')->user()?->shop_owner_id,
                    'shop_owner_guard_id' => Auth::guard('shop_owner')->id(),
                    'name' => $product->name,
                    'variants_count' => count($validated['variants'] ?? []),
                ]);

                                return response()->json([
                    'success' => true,
                    'message' => 'Product created successfully',
                    'product' => $product->load('variants'),
                                ], 201)
                                    ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                                    ->header('Pragma', 'no-cache')
                                    ->header('Expires', '0');
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first() ?: 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating product', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create product',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create price change request (for staff price edits)
     */
    public function createPriceChangeRequest(Request $request)
    {
        try {
            $user = Auth::guard('user')->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized - Staff authentication required',
                ], 401);
            }

            $validated = $request->validate([
                'product_id' => 'required|exists:products,id',
                'product_name' => 'required|string',
                'current_price' => 'required|numeric|min:0',
                'proposed_price' => 'required|numeric|min:0',
                'reason' => 'required|string|max:1000',
            ]);

            $product = Product::findOrFail($validated['product_id']);
            $shopOwnerId = $product->shop_owner_id ?? $user->shop_owner_id;

            $priceChangeRequest = PriceChangeRequest::create([
                'product_id' => $validated['product_id'],
                'product_name' => $validated['product_name'],
                'current_price' => $validated['current_price'],
                'proposed_price' => $validated['proposed_price'],
                'reason' => $validated['reason'],
                'requested_by' => $user->id,
                'shop_owner_id' => $shopOwnerId,
                'status' => 'pending',
            ]);

            activity()
                ->causedBy($user)
                ->performedOn($priceChangeRequest)
                ->event('created')
                ->withProperties([
                    'attributes' => [
                        'product_id' => $validated['product_id'],
                        'product_name' => $validated['product_name'],
                        'current_price' => $validated['current_price'],
                        'proposed_price' => $validated['proposed_price'],
                        'reason' => $validated['reason'],
                        'status' => 'pending',
                    ],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ])
                ->log('Price change request submitted');

            return response()->json([
                'success' => true,
                'message' => 'Price change request submitted successfully',
                'data' => $priceChangeRequest,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating price change request from product edit', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create price change request',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update product (shop owner or staff)
     */
    public function update(Request $request, $id)
    {
        try {
            $user = Auth::guard('shop_owner')->user() ?? Auth::guard('user')->user();
            $activityCauser = $user instanceof Model ? $user : null;

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Determine shop_owner_id: if user is shop_owner, use their ID; if staff, use their shop_owner_id
            try {
                $shopOwnerId = $this->getAuthenticatedShopOwnerId();
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 403);
            }

            $product = Product::where('id', $id)
                ->where('shop_owner_id', $shopOwnerId)
                ->firstOrFail();

            $validated = $request->validate([
                'name' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'price' => 'sometimes|numeric|min:0|max:999999.99',
                'compare_at_price' => 'nullable|numeric|min:0|max:999999.99',
                'scheduled_sale_price' => 'nullable|numeric|min:0.01|max:999999.99',
                'sale_starts_at' => 'nullable|date',
                'sale_ends_at' => 'nullable|date|after_or_equal:sale_starts_at',
                'brand' => 'nullable|string|max:100',
                'category' => 'nullable|string|max:50',
                'stock_quantity' => 'sometimes|integer|min:0',
                'is_active' => 'sometimes|boolean',
                'is_featured' => 'sometimes|boolean',
                'sizes_available' => 'nullable|array',
                'colors_available' => 'nullable|array',
                'sku' => 'nullable|string|max:100',
                'weight' => 'nullable|numeric|min:0',
                'main_image' => 'nullable|string',
                'additional_images' => 'nullable|array',
                'variants' => 'nullable|array',
                'variants.*.size' => 'required|string',
                'variants.*.color' => 'required|string',
                'variants.*.quantity' => 'required|integer|min:0',
                'variants.*.image' => 'nullable|string',
                'variants.*.sku' => 'nullable|string',
            ]);

            $hasDiscountScheduleInput = array_key_exists('scheduled_sale_price', $validated)
                || array_key_exists('sale_starts_at', $validated)
                || array_key_exists('sale_ends_at', $validated);

            if ($hasDiscountScheduleInput) {
                $scheduledSalePrice = array_key_exists('scheduled_sale_price', $validated)
                    ? (is_null($validated['scheduled_sale_price']) ? null : (float) $validated['scheduled_sale_price'])
                    : null;

                // Clearing scheduled sale metadata is allowed by explicitly sending null scheduled_sale_price.
                if ($scheduledSalePrice === null) {
                    $validated['scheduled_sale_price'] = null;
                    $validated['sale_starts_at'] = null;
                    $validated['sale_ends_at'] = null;
                } else {
                    if (empty($validated['sale_starts_at']) || empty($validated['sale_ends_at'])) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Start and end date are required when scheduling a product discount.',
                        ], 422);
                    }

                    $startsAt = Carbon::parse((string) $validated['sale_starts_at'])->startOfDay();
                    $endsAt = Carbon::parse((string) $validated['sale_ends_at'])->endOfDay();

                    if ($endsAt->lessThanOrEqualTo(now())) {
                        return response()->json([
                            'success' => false,
                            'message' => 'End date must be in the future for scheduled discounts.',
                        ], 422);
                    }

                    $baselineOriginalPrice = $product->compare_at_price !== null
                        ? (float) $product->compare_at_price
                        : (float) $product->price;

                    if ($scheduledSalePrice >= $baselineOriginalPrice) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Scheduled sale price must be lower than the original price.',
                        ], 422);
                    }

                    // Keep the original price for future restore once schedule ends.
                    $validated['compare_at_price'] = $baselineOriginalPrice;

                    if ($startsAt->lessThanOrEqualTo(now())) {
                        $validated['price'] = $scheduledSalePrice;
                        $validated['scheduled_sale_price'] = null;
                    } else {
                        // Future schedule: keep current visible price until the start date.
                        $validated['price'] = (float) $product->price;
                        $validated['scheduled_sale_price'] = $scheduledSalePrice;
                    }

                    $validated['sale_starts_at'] = $startsAt->toDateTimeString();
                    $validated['sale_ends_at'] = $endsAt->toDateTimeString();
                }
            }

            $isStaffUpdate = Auth::guard('user')->check();

            if ($isStaffUpdate) {
                $linkedInventoryItem = InventoryItem::where('shop_owner_id', $shopOwnerId)
                    ->where('product_id', $product->id)
                    ->with(['colorVariants.sizes'])
                    ->first();

                $authoritativeStockQuantity = 0;

                if ($linkedInventoryItem) {
                    $authoritativeStockQuantity = (int) collect($linkedInventoryItem->colorVariants ?? [])
                        ->flatMap(static function ($colorVariant) {
                            return collect($colorVariant->sizes ?? []);
                        })
                        ->sum(static function ($sizeRow) {
                            return max(0, (int) ($sizeRow->quantity ?? 0));
                        });
                }

                if ($authoritativeStockQuantity <= 0) {
                    $authoritativeStockQuantity = (int) $product->variants()->sum('quantity');
                }

                if ($authoritativeStockQuantity <= 0) {
                    $authoritativeStockQuantity = (int) $product->stock_quantity;
                }

                if (
                    array_key_exists('stock_quantity', $validated)
                    && !in_array((int) $validated['stock_quantity'], [(int) $product->stock_quantity, $authoritativeStockQuantity], true)
                ) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Staff cannot edit stock quantities in product edit.',
                    ], 403);
                }

                if (isset($validated['variants']) && is_array($validated['variants'])) {
                    $incomingVariantTotal = (int) collect($validated['variants'])
                        ->sum(static function ($variant) {
                            return max(0, (int) ($variant['quantity'] ?? 0));
                        });

                    if ($incomingVariantTotal !== $authoritativeStockQuantity) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Staff cannot edit stock quantities in product edit.',
                        ], 403);
                    }
                }

                // Ignore stock_quantity on staff updates even when unchanged.
                unset($validated['stock_quantity']);
            }

            // IMPORTANT: Prevent direct price changes from STAFF members working for COMPANY-type shops.
            // This includes scheduled discount prices because they still modify customer-facing pricing.
            if (Auth::guard('user')->check()) {
                $currentPrice = (float) $product->price;
                $nextImmediatePrice = isset($validated['price']) ? (float) $validated['price'] : null;
                $nextScheduledPrice = array_key_exists('scheduled_sale_price', $validated) && !is_null($validated['scheduled_sale_price'])
                    ? (float) $validated['scheduled_sale_price']
                    : null;

                $isPriceMutation = ($nextImmediatePrice !== null && $nextImmediatePrice != $currentPrice)
                    || ($nextScheduledPrice !== null && $nextScheduledPrice != $currentPrice);

                if ($isPriceMutation) {
                    $shopOwner = \App\Models\ShopOwner::find($shopOwnerId);

                    // Only require approval for company-type shops.
                    if ($shopOwner && $shopOwner->registration_type === 'company') {
                        return response()->json([
                            'success' => false,
                            'message' => 'Direct price changes are not allowed for company-type shops. Please use the price approval workflow.',
                            'requires_approval' => true,
                        ], 403);
                    }
                }
            }

            // Shop owners (both individual and company) can change price directly via shop_owner guard
            // Staff from individual shops can change prices directly
            // Staff from company shops must use approval workflow

            if (array_key_exists('is_featured', $validated) && (bool) $validated['is_featured'] === true && !$product->is_featured) {
                $entitlementError = $this->enforceShowroomEntitlement((int) $shopOwnerId, true, (int) $product->id);
                if ($entitlementError) {
                    return $entitlementError;
                }
            }

            DB::beginTransaction();
            try {
                // Track changes for logging
                $changes = [];
                $oldStock = $product->stock_quantity;
                $oldPrice = $product->price;

                $product->update($validated);

                // Log stock quantity changes
                if (isset($validated['stock_quantity']) && $oldStock != $validated['stock_quantity']) {
                    $changes['stock_quantity'] = [
                        'old' => $oldStock,
                        'new' => $validated['stock_quantity'],
                        'change' => $validated['stock_quantity'] - $oldStock,
                    ];

                    activity()
                        ->causedBy($activityCauser)
                        ->performedOn($product)
                        ->withProperties([
                            'product_name' => $product->name,
                            'old_stock' => $oldStock,
                            'new_stock' => $validated['stock_quantity'],
                            'stock_change' => $validated['stock_quantity'] - $oldStock,
                            'updated_by_name' => $user->name ?? $user->shop_name,
                            'updated_by_role' => Auth::guard('shop_owner')->check() ? 'Shop Owner' : ($user->role ?? 'Staff'),
                        ])
                        ->log("Stock level adjusted: {$product->name} - {$oldStock} → {$validated['stock_quantity']}");
                }

                // Log price changes (if allowed - already validated above)
                if (isset($validated['price']) && $oldPrice != $validated['price']) {
                    $changes['price'] = [
                        'old' => $oldPrice,
                        'new' => $validated['price'],
                    ];

                    activity()
                        ->causedBy($activityCauser)
                        ->performedOn($product)
                        ->withProperties([
                            'product_name' => $product->name,
                            'old_price' => $oldPrice,
                            'new_price' => $validated['price'],
                            'updated_by_name' => $user->name ?? $user->shop_name,
                            'updated_by_role' => Auth::guard('shop_owner')->check() ? 'Shop Owner' : ($user->role ?? 'Staff'),
                        ])
                        ->log("Product price updated: {$product->name} - ₱{$oldPrice} → ₱{$validated['price']}");
                }

                // Update variants if provided
                if (isset($validated['variants']) && is_array($validated['variants'])) {
                    // Delete old variants
                    $product->variants()->delete();

                    // Create new variants
                    foreach ($validated['variants'] as $variantData) {
                        $canonicalColor = $this->canonicalizeColorName((string) ($variantData['color'] ?? ''));

                        ProductVariant::create([
                            'product_id' => $product->id,
                            'size' => $variantData['size'],
                            'color' => $canonicalColor,
                            'quantity' => $variantData['quantity'],
                            'image' => $variantData['image'] ?? null,
                            'sku' => $variantData['sku'] ?? null,
                            'is_active' => true,
                        ]);
                    }
                }

                DB::commit();

                Log::info('Product updated with variants', [
                    'product_id' => $product->id,
                    'shop_owner_id' => $user->id,
                    'changes' => $changes,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Product updated successfully',
                    'product' => $product->load('variants'),
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Error updating product', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update product',
            ], 500);
        }
    }

    /**
     * Delete product (soft delete)
     */
    public function destroy($id)
    {
        try {
            // Prefer staff user guard first (same policy as getAuthenticatedShopOwnerId)
            $user = Auth::guard('user')->user() ?? Auth::guard('shop_owner')->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Use centralized shop owner resolution to avoid cross-guard mismatch issues
            $shopOwnerId = $this->getAuthenticatedShopOwnerId();

            $product = Product::where('id', $id)
                ->where('shop_owner_id', $shopOwnerId)
                ->first();

            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found for this shop.',
                ], 404);
            }

            // Unlink any inventory item currently linked to this product
            InventoryItem::where('shop_owner_id', $shopOwnerId)
                ->where('product_id', $product->id)
                ->update(['product_id' => null]);

            $product->delete();

            Log::info('Product deleted', [
                'product_id' => $product->id,
                'shop_owner_id' => $shopOwnerId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Product deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting product', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete product',
            ], 500);
        }
    }

    /**
     * Upload product image
     */
    public function uploadImage(Request $request)
    {
        try {
            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240', // 10MB max
            ]);

            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $filename = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
                $path = $image->storeAs('products', $filename, 'public');

                return response()->json([
                    'success' => true,
                    'path' => '/storage/' . $path,
                    'url' => asset('storage/' . $path),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No image file provided',
            ], 400);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first('image'),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error uploading image', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to upload image: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get product variants
     */
    public function getVariants($productId)
    {
        try {
            $product = Product::findOrFail($productId);

            // Check if user has access (for shop owner or staff)
            $user = Auth::guard('shop_owner')->user() ?? Auth::guard('user')->user();
            if ($user && $product->shop_owner_id !== $user->id) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $variants = $product->variants()->orderBy('size')->orderBy('color')->get();

            return response()->json([
                'success' => true,
                'variants' => $variants,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching variants', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch variants',
            ], 500);
        }
    }

    /**
     * Get available quantity for a specific variant
     */
    public function getVariantStock(Request $request, $productId)
    {
        try {
            $validated = $request->validate([
                'size' => 'required|string',
                'color' => 'required|string',
            ]);

            $variant = ProductVariant::where('product_id', $productId)
                ->where('size', $validated['size'])
                ->where('color', $validated['color'])
                ->where('is_active', true)
                ->first();

            if (!$variant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Variant not found',
                    'quantity' => 0,
                ], 404);
            }

            return response()->json([
                'success' => true,
                'quantity' => $variant->quantity,
                'in_stock' => $variant->isInStock(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check stock',
            ], 500);
        }
    }

    /**
     * Get color variants for a product with images
     */
    public function getColorVariants($productId)
    {
        try {
            $product = Product::findOrFail($productId);
            $linkedInventory = InventoryItem::where('product_id', $productId)
                ->with(['colorVariants.sizes'])
                ->first();

            $fallbackVariantsByIdentity = ProductVariant::where('product_id', $productId)
                ->select('id', 'product_id', 'color', 'size', 'quantity', 'sku')
                ->get()
                ->groupBy(function ($variantRow) {
                    return $this->normalizeColorIdentity((string) $variantRow->color);
                });

            $colorVariants = $product->colorVariants()
                ->with([
                    'images' => function ($query) {
                        $query->orderBy('sort_order');
                    }
                ])
                ->orderBy('sort_order')
                ->get()
                ->map(function ($variant) use ($linkedInventory, $fallbackVariantsByIdentity) {
                    $sizeRows = [];

                    if ($linkedInventory) {
                        $matchedInventoryColor = $linkedInventory->colorVariants->first(function ($inventoryColor) use ($variant) {
                            if ($variant->inventory_color_id) {
                                return (int) $inventoryColor->id === (int) $variant->inventory_color_id;
                            }

                            return strcasecmp(
                                trim((string) $inventoryColor->color_name),
                                trim((string) $variant->color_name)
                            ) === 0;
                        });

                        if ($matchedInventoryColor) {
                            $sizeRows = collect($matchedInventoryColor->sizes ?? [])
                                ->map(function ($size) {
                                    return [
                                        'id' => $size->id,
                                        'size' => $size->size,
                                        'size_system' => $size->size_system,
                                        'quantity' => (int) $size->quantity,
                                        'sku' => null,
                                    ];
                                })
                                ->values()
                                ->all();
                        }
                    }

                    if (empty($sizeRows)) {
                        // Fallback for non-linked products / legacy rows.
                        $variantIdentity = $this->normalizeColorIdentity((string) $variant->color_name);

                        $sizeRows = collect($fallbackVariantsByIdentity->get($variantIdentity, collect()))
                            ->map(function ($size) {
                                return [
                                    'id' => $size->id,
                                    'size' => $size->size,
                                    'quantity' => (int) $size->quantity,
                                    'sku' => $size->sku,
                                ];
                            })
                            ->values()
                            ->all();
                    }

                    return [
                        'id' => $variant->id,
                        'color_name' => $variant->color_name,
                        'color_code' => $variant->color_code,
                        'sku_prefix' => $variant->sku_prefix,
                        'is_active' => $variant->is_active,
                        'sort_order' => $variant->sort_order,
                        'images' => $variant->images,
                        'sizes' => $sizeRows,
                    ];
                });

            return response()->json([
                'success' => true,
                'color_variants' => $colorVariants,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching color variants', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch color variants',
            ], 500);
        }
    }

    /**
     * Create or update color variant for a product
     */
    public function storeColorVariant(Request $request, $productId)
    {
        try {
            $user = Auth::guard('shop_owner')->user() ?? Auth::guard('user')->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = $this->getAuthenticatedShopOwnerId();

            $product = Product::where('id', $productId)
                ->where('shop_owner_id', $shopOwnerId)
                ->firstOrFail();

            $validated = $request->validate([
                'color_name' => 'required|string|max:100',
                'color_code' => 'nullable|string|max:7', // e.g., #FF0000
                'sku_prefix' => 'nullable|string|max:50',
                'is_active' => 'sometimes|boolean',
                'sort_order' => 'sometimes|integer',
                'assign_to_showroom' => 'sometimes|boolean',
                'images' => 'nullable|array|max:10', // Max 10 images per color
                'images.*.path' => 'required|string',
                'images.*.alt_text' => 'nullable|string',
                'images.*.is_thumbnail' => 'sometimes|boolean',
                'images.*.sort_order' => 'sometimes|integer',
                'images.*.image_type' => 'nullable|string',
            ]);

            $validated['color_name'] = $this->canonicalizeColorName($validated['color_name']);

            $hasShowroomImage = collect($validated['images'] ?? [])->contains(function ($imageData) {
                return $this->isShowroomImageType($imageData['image_type'] ?? null);
            });

            $assignToShowroom = $request->boolean('assign_to_showroom', false) || $product->is_featured || $hasShowroomImage;

            if ($assignToShowroom) {
                $entitlementError = $this->enforceShowroomEntitlement(
                    (int) $shopOwnerId,
                    !$product->is_featured,
                    (int) $product->id
                );

                if ($entitlementError) {
                    return $entitlementError;
                }
            }

            DB::beginTransaction();
            try {
                if ($assignToShowroom && !$product->is_featured) {
                    $product->update(['is_featured' => true]);
                }

                // Check if this is the first color variant (for auto-setting main_image)
                $isFirstColorVariant = $product->colorVariants()->count() === 0;

                // Create or update color variant
                $colorVariant = ProductColorVariant::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'color_name' => $validated['color_name'],
                    ],
                    [
                        'color_code' => $validated['color_code'] ?? null,
                        'sku_prefix' => $validated['sku_prefix'] ?? null,
                        'is_active' => $validated['is_active'] ?? true,
                        'sort_order' => $validated['sort_order'] ?? 0,
                    ]
                );

                $firstImagePath = null;

                // Add images if provided
                if (isset($validated['images']) && is_array($validated['images'])) {
                    foreach ($validated['images'] as $index => $imageData) {
                        ProductColorVariantImage::create([
                            'product_color_variant_id' => $colorVariant->id,
                            'image_path' => $imageData['path'],
                            'alt_text' => $imageData['alt_text'] ?? null,
                            'is_thumbnail' => $imageData['is_thumbnail'] ?? ($index === 0), // First image is thumbnail
                            'sort_order' => $imageData['sort_order'] ?? $index,
                            'image_type' => $imageData['image_type'] ?? 'product',
                        ]);

                        // Save first image path for potential main_image update
                        if ($index === 0) {
                            $firstImagePath = $imageData['path'];
                        }
                    }
                }

                // Auto-update product main_image if this is the first color variant and has images
                if ($isFirstColorVariant && $firstImagePath && !$product->main_image) {
                    $product->update(['main_image' => $firstImagePath]);

                    Log::info('Auto-updated product main_image from first color variant', [
                        'product_id' => $product->id,
                        'main_image' => $firstImagePath,
                    ]);
                }

                DB::commit();

                Log::info('Color variant created/updated', [
                    'product_id' => $product->id,
                    'color_variant_id' => $colorVariant->id,
                    'color_name' => $colorVariant->color_name,
                    'images_count' => count($validated['images'] ?? []),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Color variant saved successfully',
                    'color_variant' => $colorVariant->load('images'),
                ], 201);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            if ($this->isDuplicateColorConstraintError($e)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Combined color already exists for this product. Try a different color combination.',
                    'errors' => [
                        'color_name' => ['Duplicate combined color is not allowed.'],
                    ],
                ], 422);
            }

            Log::error('Error creating color variant', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create color variant',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update color variant
     */
    public function updateColorVariant(Request $request, $productId, $colorVariantId)
    {
        try {
            $user = Auth::guard('shop_owner')->user() ?? Auth::guard('user')->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = $this->getAuthenticatedShopOwnerId();

            $product = Product::where('id', $productId)
                ->where('shop_owner_id', $shopOwnerId)
                ->firstOrFail();

            $colorVariant = ProductColorVariant::where('id', $colorVariantId)
                ->where('product_id', $product->id)
                ->firstOrFail();

            $validated = $request->validate([
                'color_name' => 'sometimes|string|max:100',
                'color_code' => 'nullable|string|max:7',
                'sku_prefix' => 'nullable|string|max:50',
                'is_active' => 'sometimes|boolean',
                'sort_order' => 'sometimes|integer',
            ]);

            if (array_key_exists('color_name', $validated)) {
                $validated['color_name'] = $this->canonicalizeColorName((string) $validated['color_name']);
            }

            $colorVariant->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Color variant updated successfully',
                'color_variant' => $colorVariant->load('images'),
            ]);
        } catch (\Exception $e) {
            if ($this->isDuplicateColorConstraintError($e)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Combined color already exists for this product. Try a different color combination.',
                    'errors' => [
                        'color_name' => ['Duplicate combined color is not allowed.'],
                    ],
                ], 422);
            }

            Log::error('Error updating color variant', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update color variant',
            ], 500);
        }
    }

    /**
     * Delete color variant
     */
    public function deleteColorVariant($productId, $colorVariantId)
    {
        try {
            $user = Auth::guard('shop_owner')->user() ?? Auth::guard('user')->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = $this->getAuthenticatedShopOwnerId();

            $product = Product::where('id', $productId)
                ->where('shop_owner_id', $shopOwnerId)
                ->firstOrFail();

            $colorVariant = ProductColorVariant::where('id', $colorVariantId)
                ->where('product_id', $product->id)
                ->firstOrFail();

            // Delete associated images (cascade will handle this, but we can do it explicitly)
            $colorVariant->images()->delete();
            $colorVariant->delete();

            Log::info('Color variant deleted', [
                'product_id' => $product->id,
                'color_variant_id' => $colorVariantId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Color variant deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting color variant', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete color variant',
            ], 500);
        }
    }

    /**
     * Upload image for color variant
     */
    public function uploadColorVariantImage(Request $request, $productId, $colorVariantId)
    {
        try {
            $user = Auth::guard('shop_owner')->user() ?? Auth::guard('user')->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = $this->getAuthenticatedShopOwnerId();

            $product = Product::where('id', $productId)
                ->where('shop_owner_id', $shopOwnerId)
                ->firstOrFail();

            $colorVariant = ProductColorVariant::where('id', $colorVariantId)
                ->where('product_id', $product->id)
                ->firstOrFail();

            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240', // 10MB max
                'alt_text' => 'nullable|string|max:255',
                'is_thumbnail' => 'sometimes|boolean',
                'image_type' => 'nullable|string|max:50',
                'assign_to_showroom' => 'sometimes|boolean',
            ]);

            $imageType = $request->input('image_type', 'product');
            $isShowroomImage = $this->isShowroomImageType($imageType);
            $maxRegularImagesPerColorVariant = 10;
            $maxShowroomFramesPerColorVariant = 120;

            $existingImagesCount = $colorVariant->images()->count();

            $existingShowroomImagesCount = $colorVariant->images()
                ->whereRaw("LOWER(COALESCE(image_type, '')) IN ('showroom', 'virtual_showroom', 'showroom_360', '360')")
                ->count();

            $existingRegularImagesCount = $colorVariant->images()
                ->whereRaw("LOWER(COALESCE(image_type, '')) NOT IN ('showroom', 'virtual_showroom', 'showroom_360', '360')")
                ->count();

            if ($isShowroomImage && $existingShowroomImagesCount >= $maxShowroomFramesPerColorVariant) {
                return response()->json([
                    'success' => false,
                    'message' => "Maximum {$maxShowroomFramesPerColorVariant} showroom 360 frames allowed per color variant",
                ], 400);
            }

            if (!$isShowroomImage && $existingRegularImagesCount >= $maxRegularImagesPerColorVariant) {
                return response()->json([
                    'success' => false,
                    'message' => "Maximum {$maxRegularImagesPerColorVariant} product images allowed per color variant",
                ], 400);
            }

            $assignToShowroom = $request->boolean('assign_to_showroom', false)
                || $product->is_featured
                || $isShowroomImage;

            if ($assignToShowroom) {
                $entitlementError = $this->enforceShowroomEntitlement(
                    (int) $shopOwnerId,
                    !$product->is_featured,
                    (int) $product->id
                );

                if ($entitlementError) {
                    return $entitlementError;
                }

                if (!$product->is_featured) {
                    $product->update(['is_featured' => true]);
                }
            }

            if ($request->hasFile('image')) {
                $image = $request->file('image');
                $filename = time() . '_' . uniqid() . '.' . $image->getClientOriginalExtension();
                $path = $image->storeAs('products/colors', $filename, 'public');

                // Create image record
                $variantImage = ProductColorVariantImage::create([
                    'product_color_variant_id' => $colorVariant->id,
                    'image_path' => '/storage/' . $path,
                    'alt_text' => $request->input('alt_text'),
                    'is_thumbnail' => $request->input('is_thumbnail', $existingImagesCount === 0), // First image is thumbnail
                    'sort_order' => $existingImagesCount,
                    'image_type' => $imageType,
                ]);

                Log::info('Color variant image uploaded', [
                    'product_id' => $product->id,
                    'color_variant_id' => $colorVariant->id,
                    'image_id' => $variantImage->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Image uploaded successfully',
                    'image' => $variantImage,
                    'path' => '/storage/' . $path,
                    'url' => asset('storage/' . $path),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No image file provided',
            ], 400);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->validator->errors()->first(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error uploading color variant image', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to upload image: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update color variant image
     */
    public function updateColorVariantImage(Request $request, $productId, $colorVariantId, $imageId)
    {
        try {
            $user = Auth::guard('shop_owner')->user() ?? Auth::guard('user')->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = $this->getAuthenticatedShopOwnerId();

            $product = Product::where('id', $productId)
                ->where('shop_owner_id', $shopOwnerId)
                ->firstOrFail();

            $colorVariant = ProductColorVariant::where('id', $colorVariantId)
                ->where('product_id', $product->id)
                ->firstOrFail();

            $image = ProductColorVariantImage::where('id', $imageId)
                ->where('product_color_variant_id', $colorVariant->id)
                ->firstOrFail();

            $validated = $request->validate([
                'alt_text' => 'nullable|string|max:255',
                'is_thumbnail' => 'sometimes|boolean',
                'sort_order' => 'sometimes|integer',
                'image_type' => 'nullable|string|max:50',
            ]);

            $image->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Image updated successfully',
                'image' => $image,
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating color variant image', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update image',
            ], 500);
        }
    }

    /**
     * Delete color variant image
     */
    public function deleteColorVariantImage($productId, $colorVariantId, $imageId)
    {
        try {
            $user = Auth::guard('shop_owner')->user() ?? Auth::guard('user')->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = $this->getAuthenticatedShopOwnerId();

            $product = Product::where('id', $productId)
                ->where('shop_owner_id', $shopOwnerId)
                ->firstOrFail();

            $colorVariant = ProductColorVariant::where('id', $colorVariantId)
                ->where('product_id', $product->id)
                ->firstOrFail();

            $image = ProductColorVariantImage::where('id', $imageId)
                ->where('product_color_variant_id', $colorVariant->id)
                ->firstOrFail();

            // Delete the physical file
            $imagePath = str_replace('/storage/', '', $image->image_path);
            if (Storage::disk('public')->exists($imagePath)) {
                Storage::disk('public')->delete($imagePath);
            }

            $image->delete();

            Log::info('Color variant image deleted', [
                'product_id' => $product->id,
                'color_variant_id' => $colorVariant->id,
                'image_id' => $imageId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Image deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting color variant image', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete image',
            ], 500);
        }
    }

    /**
     * Reorder color variant images
     */
    public function reorderColorVariantImages(Request $request, $productId, $colorVariantId)
    {
        try {
            $user = Auth::guard('shop_owner')->user() ?? Auth::guard('user')->user();

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            $shopOwnerId = $this->getAuthenticatedShopOwnerId();

            $product = Product::where('id', $productId)
                ->where('shop_owner_id', $shopOwnerId)
                ->firstOrFail();

            $colorVariant = ProductColorVariant::where('id', $colorVariantId)
                ->where('product_id', $product->id)
                ->firstOrFail();

            $validated = $request->validate([
                'image_orders' => 'required|array',
                'image_orders.*.id' => 'required|integer|exists:product_color_variant_images,id',
                'image_orders.*.sort_order' => 'required|integer',
            ]);

            DB::beginTransaction();
            try {
                foreach ($validated['image_orders'] as $imageOrder) {
                    ProductColorVariantImage::where('id', $imageOrder['id'])
                        ->where('product_color_variant_id', $colorVariant->id)
                        ->update(['sort_order' => $imageOrder['sort_order']]);
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Images reordered successfully',
                    'images' => $colorVariant->images()->orderBy('sort_order')->get(),
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Error reordering color variant images', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reorder images',
            ], 500);
        }
    }
}
