<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductColorVariant;
use App\Models\ProductColorVariantImage;
use App\Models\PriceChangeRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class ProductController extends Controller
{
    /**
     * Get the shop owner ID for the authenticated user
     * Returns shop_owner's ID directly, or staff member's shop_owner_id
     */
    private function getAuthenticatedShopOwnerId()
    {
        if (Auth::guard('shop_owner')->check()) {
            return Auth::guard('shop_owner')->id();
        }
        
        $user = Auth::guard('user')->user();
        if ($user && $user->shop_owner_id) {
            return $user->shop_owner_id;
        }
        
        // If no shop_owner_id found, throw an error instead of returning null
        throw new \Exception('User is not authorized to create products. Only shop owners and staff can create products.');
    }

    /**
     * Get all active products (for customers)
     */
    public function index(Request $request)
    {
        try {
            $products = QueryBuilder::for(Product::class)
                ->allowedFilters([
                    'category',
                    AllowedFilter::exact('shop_id', 'shop_owner_id'),
                    AllowedFilter::partial('search', 'name'),
                    AllowedFilter::scope('search_all'),
                ])
                ->allowedSorts(['price', 'name', 'created_at', 'sales_count'])
                ->defaultSort('-created_at')
                ->where('is_active', true)
                ->with(['shopOwner:id,first_name,last_name,business_name'])
                ->paginate($request->get('per_page', 12));

            // Transform products to include full image URLs and shop owner info
            $products->getCollection()->transform(function ($product) {
                $product->main_image = $product->main_image_url;
                
                // Add shop_owner for frontend compatibility
                if ($product->shopOwner) {
                    $product->shop_owner = [
                        'id' => $product->shopOwner->id,
                        'name' => $product->shopOwner->business_name ?: ($product->shopOwner->first_name . ' ' . $product->shopOwner->last_name),
                    ];
                }
                
                return $product;
            });

            return response()->json([
                'success' => true,
                'products' => $products,
            ]);

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
                    'colorVariants' => function($query) {
                        $query->active()->orderBy('sort_order');
                    },
                    'colorVariants.images' => function($query) {
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

            // Transform products to include full image URLs
            $products->transform(function ($product) {
                $product->main_image = $product->main_image_url;
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
                'category' => 'nullable|string|max:50',
                'stock_quantity' => 'required|integer|min:0',
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

            DB::beginTransaction();
            try {
                $product = Product::create($validated);

                // Create variants if provided
                if (isset($validated['variants']) && is_array($validated['variants'])) {
                    foreach ($validated['variants'] as $variantData) {
                        ProductVariant::create([
                            'product_id' => $product->id,
                            'size' => $variantData['size'],
                            'color' => $variantData['color'],
                            'quantity' => $variantData['quantity'],
                            'image' => $variantData['image'] ?? null,
                            'sku' => $variantData['sku'] ?? null,
                            'is_active' => true,
                        ]);
                    }
                }

                DB::commit();

                Log::info('Product created with variants', [
                    'product_id' => $product->id,
                    'shop_owner_id' => $user->id,
                    'name' => $product->name,
                    'variants_count' => count($validated['variants'] ?? []),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Product created successfully',
                    'product' => $product->load('variants'),
                ], 201);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

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

            // IMPORTANT: Prevent direct price changes from STAFF members
            // Staff must use the price approval workflow
            if (Auth::guard('user')->check() && isset($validated['price'])) {
                $currentPrice = $product->price;
                $newPrice = $validated['price'];
                
                // If price is different, reject the direct update
                if ($newPrice != $currentPrice) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Direct price changes are not allowed. Please use the price approval workflow.',
                        'requires_approval' => true,
                    ], 403);
                }
            }

            // Shop owners can change price directly
            // Staff can update other fields (price should be excluded by frontend if changed)

            DB::beginTransaction();
            try {
                $product->update($validated);

                // Update variants if provided
                if (isset($validated['variants']) && is_array($validated['variants'])) {
                    // Delete old variants
                    $product->variants()->delete();
                    
                    // Create new variants
                    foreach ($validated['variants'] as $variantData) {
                        ProductVariant::create([
                            'product_id' => $product->id,
                            'size' => $variantData['size'],
                            'color' => $variantData['color'],
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
            $user = Auth::guard('shop_owner')->user() ?? Auth::guard('user')->user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Determine shop_owner_id: if user is shop_owner, use their ID; if staff, use their shop_owner_id
            $shopOwnerId = Auth::guard('shop_owner')->check() 
                ? Auth::guard('shop_owner')->id() 
                : $user->shop_owner_id;

            $product = Product::where('id', $id)
                ->where('shop_owner_id', $shopOwnerId)
                ->firstOrFail();

            $product->delete();

            Log::info('Product deleted', [
                'product_id' => $product->id,
                'shop_owner_id' => $user->id,
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
            
            $colorVariants = $product->colorVariants()
                ->with([
                    'images' => function($query) {
                        $query->orderBy('sort_order');
                    }
                ])
                ->orderBy('sort_order')
                ->get()
                ->map(function($variant) use ($productId) {
                    // Manually load size variants for this color
                    $sizeVariants = ProductVariant::where('product_id', $productId)
                        ->where('color', $variant->color_name)
                        ->select('id', 'product_id', 'color', 'size', 'quantity', 'sku')
                        ->get();
                    
                    return [
                        'id' => $variant->id,
                        'color_name' => $variant->color_name,
                        'color_code' => $variant->color_code,
                        'sku_prefix' => $variant->sku_prefix,
                        'is_active' => $variant->is_active,
                        'sort_order' => $variant->sort_order,
                        'images' => $variant->images,
                        'sizes' => $sizeVariants->map(function($size) {
                            return [
                                'id' => $size->id,
                                'size' => $size->size,
                                'quantity' => $size->quantity,
                                'sku' => $size->sku,
                            ];
                        }),
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
                'images' => 'nullable|array|max:10', // Max 10 images per color
                'images.*.path' => 'required|string',
                'images.*.alt_text' => 'nullable|string',
                'images.*.is_thumbnail' => 'sometimes|boolean',
                'images.*.sort_order' => 'sometimes|integer',
                'images.*.image_type' => 'nullable|string',
            ]);

            DB::beginTransaction();
            try {
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

            $colorVariant->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Color variant updated successfully',
                'color_variant' => $colorVariant->load('images'),
            ]);

        } catch (\Exception $e) {
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

            $product = Product::where('id', $productId)
                ->where('shop_owner_id', $user->id)
                ->firstOrFail();

            $colorVariant = ProductColorVariant::where('id', $colorVariantId)
                ->where('product_id', $product->id)
                ->firstOrFail();

            // Check image count limit (max 10)
            $existingImagesCount = $colorVariant->images()->count();
            if ($existingImagesCount >= 10) {
                return response()->json([
                    'success' => false,
                    'message' => 'Maximum 10 images allowed per color variant',
                ], 400);
            }

            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:10240', // 10MB max
                'alt_text' => 'nullable|string|max:255',
                'is_thumbnail' => 'sometimes|boolean',
                'image_type' => 'nullable|string|max:50',
            ]);

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
                    'image_type' => $request->input('image_type', 'product'),
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
