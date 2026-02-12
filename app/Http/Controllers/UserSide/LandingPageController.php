<?php

namespace App\Http\Controllers\UserSide;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ShopOwner;
use Inertia\Inertia;
use Inertia\Response;

class LandingPageController extends Controller
{
    /**
     * Display the landing page.
     */
    public function index(): Response
    {
        // Get featured products (limit to 3 for landing page)
        $products = Product::where('is_active', true)
            ->with('shopOwner:id,first_name,last_name,business_name')
            ->orderBy('created_at', 'desc')
            ->take(3)
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'description' => $product->description,
                    'price' => $product->price,
                    'compare_at_price' => $product->compare_at_price,
                    'main_image' => $product->main_image_url,
                    'stock_quantity' => $product->stock_quantity,
                    'shop_owner' => $product->shopOwner ? [
                        'id' => $product->shopOwner->id,
                        'business_name' => $product->shopOwner->business_name,
                    ] : null,
                ];
            });

        return Inertia::render('UserSide/LandingPage', [
            'products' => $products,
        ]);
    }

    /**
     * Display the products page.
     */
    public function products(): Response
    {
        return Inertia::render('UserSide/Products');
    }

    /**
     * Display a single product page.
     */
    public function productShow(string $slug): Response
    {
        $product = Product::where('slug', $slug)
            ->where('is_active', true)
            ->with([
                'shopOwner:id,first_name,last_name,business_name', 
                'variants',
                'colorVariants' => function($query) {
                    $query->where('is_active', true)->orderBy('sort_order');
                },
                'colorVariants.images' => function($query) {
                    $query->orderBy('sort_order');
                }
            ])
            ->firstOrFail();

        // Increment view count
        $product->incrementViews();
        
        // Format shop owner data for frontend
        if ($product->shopOwner) {
            $product->shop = [
                'id' => $product->shopOwner->id,
                'name' => $product->shopOwner->business_name ?: ($product->shopOwner->first_name . ' ' . $product->shopOwner->last_name),
            ];
        }

        // Parse sizes and colors from JSON
        $sizes = $product->sizes_available ?? [];
        $colors = $product->colors_available ?? [];

        // Get all product images with full URLs using accessor
        $images = $product->image_urls;
        
        // Ensure at least one image exists
        if (empty($images) && $product->main_image_url) {
            $images = [$product->main_image_url];
        }

        // Prepare shop data with null safety
        $shop = $product->shopOwner ? [
            'id' => $product->shopOwner->id,
            'name' => $product->shopOwner->business_name,
            'slug' => 'shop-' . $product->shopOwner->id,
            'colors' => $colors,
        ] : [
            'id' => null,
            'name' => 'Unknown Shop',
            'slug' => 'unknown',
            'colors' => $colors,
        ];

        return Inertia::render('UserSide/ProductShow', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'price' => '₱' . number_format($product->price, 0),
                'compare_at_price' => $product->compare_at_price ? '₱' . number_format($product->compare_at_price, 0) : null,
                'primary' => $product->main_image_url,
                'images' => $images,
                'sizes' => $sizes,
                'colors' => $colors,
                'colors_available' => $colors,
                'shop' => $shop,
                'description' => $product->description,
                'brand' => $product->brand,
                'category' => $product->category,
                'stock_quantity' => $product->stock_quantity,
                'sku' => $product->sku,
                'weight' => $product->weight,
                'views_count' => $product->views_count,
                'variants' => $product->variants ? $product->variants->map(function($variant) {
                    return [
                        'id' => $variant->id,
                        'size' => $variant->size,
                        'color' => $variant->color,
                        'quantity' => $variant->quantity,
                        'image' => $variant->image,
                        'sku' => $variant->sku,
                    ];
                })->toArray() : [],
                'sales_count' => $product->sales_count,
                'colorVariants' => $product->colorVariants ? $product->colorVariants->map(function($variant) {
                    return [
                        'id' => $variant->id,
                        'color_name' => $variant->color_name,
                        'color_code' => $variant->color_code,
                        'sku_prefix' => $variant->sku_prefix,
                        'sort_order' => $variant->sort_order,
                        'is_active' => $variant->is_active,
                        'images' => $variant->images ? $variant->images->map(function($img) {
                            return [
                                'id' => $img->id,
                                'image_url' => $img->image_path,
                                'image_path' => $img->image_path,
                                'is_thumbnail' => $img->is_thumbnail,
                                'sort_order' => $img->sort_order,
                                'alt_text' => $img->alt_text,
                            ];
                        })->toArray() : [],
                    ];
                })->toArray() : [],
            ],
        ]);
    }

    /**
     * Display the repair services page.
     */
    public function repair(): Response
    {
        return Inertia::render('UserSide/Repair');
    }

    /**
     * Display the services page.
     */
    public function services(): Response
    {
        return Inertia::render('UserSide/Services');
    }

    /**
     * Display the contact page.
     */
    public function contact(): Response
    {
        return Inertia::render('UserSide/Contact');
    }

    /**
     * Display the register page.
     */
    public function register(): Response
    {
        return Inertia::render('UserSide/Register');
    }

    /**
     * Display the login page.
     */
    public function login(): Response
    {
        return Inertia::render('UserSide/Login');
    }

    /**
     * Display the shop owner registration page.
     */
    public function shopOwnerRegister(): Response
    {
        return Inertia::render('UserSide/ShopOwnerRegistration');
    }

    /**
     * Display shop profile page with products
     */
    public function shopProfile(string $id): Response
    {
        // Handle null or invalid ID
        if ($id === 'null' || !is_numeric($id)) {
            abort(404, 'Shop not found');
        }
        
        $shopOwner = ShopOwner::where('id', (int)$id)
            ->where('status', 'approved')
            ->firstOrFail();

        // Get all active products from this shop
        $products = Product::where('shop_owner_id', $id)
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'price' => $product->price,
                    'compare_at_price' => $product->compare_at_price,
                    'brand' => $product->brand,
                    'category' => $product->category,
                    'stock_quantity' => $product->stock_quantity,
                    'main_image' => $product->main_image,
                    'description' => $product->description,
                ];
            });

        return Inertia::render('UserSide/ShopProfile', [
            'shop' => [
                'id' => $shopOwner->id,
                'name' => $shopOwner->business_name,
                'description' => 'Premium footwear products and services',
                'address' => $shopOwner->business_address,
                'phone' => $shopOwner->phone,
                'email' => $shopOwner->email,
                'cover_image' => '/images/shop/shop-cover.jpg',
                'profile_icon' => '/images/shop/shop-icon.png',
                'rating' => 4.8,
                'total_reviews' => 0,
                'established_year' => 2024,
            ],
            'products' => $products,
        ]);
    }
}
