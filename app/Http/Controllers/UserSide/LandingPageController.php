<?php

namespace App\Http\Controllers\UserSide;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\RepairService;
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

        return Inertia::render('UserSide/Products/LandingPage', [
            'products' => $products,
        ]);
    }

    /**
     * Display the products page.
     */
    public function products(): Response
    {
        return Inertia::render('UserSide/Products/Products');
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

        return Inertia::render('UserSide/Products/ProductShow', [
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
        // Get all approved shop owners who offer repair services
        $shops = ShopOwner::where('status', 'approved')
            ->where(function($query) {
                $query->where('business_type', 'repair')
                      ->orWhere('business_type', 'both');
            })
            ->orderBy('business_name')
            ->get()
            ->map(function ($shop) {
                // Handle profile photo path - check if it starts with / (public path) or not (storage path)
                $profilePhoto = $shop->profile_photo;
                if ($profilePhoto) {
                    $image = str_starts_with($profilePhoto, '/') 
                        ? asset(ltrim($profilePhoto, '/'))
                        : asset('storage/' . $profilePhoto);
                } else {
                    // Generate default SVG with shop initials
                    $initials = strtoupper(substr($shop->business_name ?? $shop->first_name, 0, 1) . substr($shop->last_name ?? '', 0, 1));
                    $colors = ['#3B82F6', '#8B5CF6', '#10B981', '#F59E0B', '#EF4444', '#EC4899'];
                    $bgColor = $colors[abs(crc32($shop->id)) % count($colors)];
                    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200"><rect width="200" height="200" fill="' . $bgColor . '"/><text x="50%" y="50%" font-size="80" font-family="Arial, sans-serif" font-weight="bold" fill="white" text-anchor="middle" dominant-baseline="central">' . htmlspecialchars($initials) . '</text></svg>';
                    $image = 'data:image/svg+xml;base64,' . base64_encode($svg);
                }
                
                return [
                    'id' => $shop->id,
                    'shopName' => $shop->business_name ?? $shop->getFullNameAttribute(),
                    'shopLocation' => trim(($shop->city_state ?? '') . ($shop->city_state && $shop->country ? ', ' : '') . ($shop->country ?? '')) ?: 'Location not specified',
                    'shopRating' => 0, // Will be calculated from actual reviews
                    'image' => $image,
                    'phone' => $shop->phone,
                    'email' => $shop->email,
                    'bio' => $shop->bio,
                ];
            });

        return Inertia::render('UserSide/Repairs/Repair', [
            'shops' => $shops,
        ]);
    }

    /**
     * Display the services page.
     */
    public function services(): Response
    {
        return Inertia::render('UserSide/Shared/Services');
    }

    /**
     * Display the contact page.
     */
    // public function contact(): Response
    // {
    //     return Inertia::render('UserSide/Contact');
    // }

    /**
     * Display the register page.
     */
    public function register(): Response
    {
        return Inertia::render('UserSide/Auth/Register');
    }

    /**
     * Display the login page.
     */
    public function login(): Response
    {
        return Inertia::render('UserSide/Auth/Login');
    }

    /**
     * Display the shop owner registration page.
     */
    public function shopOwnerRegister(): Response
    {
        return Inertia::render('UserSide/Auth/ShopOwnerRegistration');
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

        return Inertia::render('UserSide/Profile/ShopProfile', [
            'shop' => [
                'id' => $shopOwner->id,
                'name' => $shopOwner->business_name ?? $shopOwner->name,
                'description' => $shopOwner->bio ?? 'Premium footwear products and services',
                'address' => $shopOwner->business_address ?? $shopOwner->city_state,
                'phone' => $shopOwner->phone,
                'email' => $shopOwner->email,
                'profile_photo' => $shopOwner->profile_photo && str_starts_with($shopOwner->profile_photo, '/') 
                    ? $shopOwner->profile_photo
                    : ($shopOwner->profile_photo ? "/storage/{$shopOwner->profile_photo}" : null),
                'cover_image' => '/images/shop/shop-cover.jpg',
                'rating' => 4.8,
                'total_reviews' => 0,
                'established_year' => 2024,
                'country' => $shopOwner->country,
                'postal_code' => $shopOwner->postal_code,
                'tax_id' => $shopOwner->tax_id,
                'monday_open' => $shopOwner->monday_open,
                'monday_close' => $shopOwner->monday_close,
                'tuesday_open' => $shopOwner->tuesday_open,
                'tuesday_close' => $shopOwner->tuesday_close,
                'wednesday_open' => $shopOwner->wednesday_open,
                'wednesday_close' => $shopOwner->wednesday_close,
                'thursday_open' => $shopOwner->thursday_open,
                'thursday_close' => $shopOwner->thursday_close,
                'friday_open' => $shopOwner->friday_open,
                'friday_close' => $shopOwner->friday_close,
                'saturday_open' => $shopOwner->saturday_open,
                'saturday_close' => $shopOwner->saturday_close,
                'sunday_open' => $shopOwner->sunday_open,
                'sunday_close' => $shopOwner->sunday_close,
            ],
            'products' => $products,
        ]);
    }

    /**
     * Display a specific repair shop's services page
     */
    public function repairShow(string $id): Response
    {
        $shopOwner = ShopOwner::where('id', (int)$id)
            ->where('status', 'approved')
            ->where(function($query) {
                $query->where('business_type', 'repair')
                      ->orWhere('business_type', 'both');
            })
            ->firstOrFail();

        // Format location
        $location = $shopOwner->business_address ?? 'Location not specified';
        if ($shopOwner->city_state || $shopOwner->country) {
            $location = trim(
                ($shopOwner->business_address ? $shopOwner->business_address . ', ' : '') .
                ($shopOwner->city_state ?? '') . 
                ($shopOwner->city_state && $shopOwner->country ? ', ' : '') . 
                ($shopOwner->country ?? '')
            );
        }

        // Format operating hours from individual column fields
        $operatingHours = null;
        
        $daysOfWeek = [
            ['day' => 'Monday', 'open' => 'monday_open', 'close' => 'monday_close'],
            ['day' => 'Tuesday', 'open' => 'tuesday_open', 'close' => 'tuesday_close'],
            ['day' => 'Wednesday', 'open' => 'wednesday_open', 'close' => 'wednesday_close'],
            ['day' => 'Thursday', 'open' => 'thursday_open', 'close' => 'thursday_close'],
            ['day' => 'Friday', 'open' => 'friday_open', 'close' => 'friday_close'],
            ['day' => 'Saturday', 'open' => 'saturday_open', 'close' => 'saturday_close'],
            ['day' => 'Sunday', 'open' => 'sunday_open', 'close' => 'sunday_close'],
        ];
        
        $hasAnyHours = false;
        $tempHours = [];
        
        foreach ($daysOfWeek as $day) {
            $openTime = $shopOwner->{$day['open']};
            $closeTime = $shopOwner->{$day['close']};
            
            if ($openTime && $closeTime) {
                $hasAnyHours = true;
                $tempHours[] = [
                    'day' => $day['day'],
                    'open' => date('g:i A', strtotime($openTime)),
                    'close' => date('g:i A', strtotime($closeTime)),
                    'is_closed' => false,
                ];
            } else {
                $tempHours[] = [
                    'day' => $day['day'],
                    'open' => null,
                    'close' => null,
                    'is_closed' => true,
                ];
            }
        }
        
        if ($hasAnyHours) {
            $operatingHours = $tempHours;
        }

        // Fetch repair services for this shop owner (including services created by shop owner and their staff)
        $repairServices = RepairService::where('shop_owner_id', $shopOwner->id)
            ->whereIn('status', ['Active', 'Under Review', 'Pending Owner Approval', 'Pending'])
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(function ($service) {
                return [
                    'id' => $service->id,
                    'title' => $service->name,
                    'price' => '₱' . number_format($service->price, 0),
                    'description' => $service->description ?? 'Professional ' . strtolower($service->category) . ' service',
                    'category' => $service->category,
                    'duration' => $service->duration,
                ];
            });

        // Handle shop image
        if ($shopOwner->profile_photo) {
            $shopImage = str_starts_with($shopOwner->profile_photo, '/') 
                ? asset(ltrim($shopOwner->profile_photo, '/'))
                : asset('storage/' . $shopOwner->profile_photo);
        } else {
            // Generate default SVG with shop initials
            $initials = strtoupper(substr($shopOwner->business_name ?? $shopOwner->first_name, 0, 1) . substr($shopOwner->last_name ?? '', 0, 1));
            $colors = ['#3B82F6', '#8B5CF6', '#10B981', '#F59E0B', '#EF4444', '#EC4899'];
            $bgColor = $colors[abs(crc32($shopOwner->id)) % count($colors)];
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200"><rect width="200" height="200" fill="' . $bgColor . '"/><text x="50%" y="50%" font-size="80" font-family="Arial, sans-serif" font-weight="bold" fill="white" text-anchor="middle" dominant-baseline="central">' . htmlspecialchars($initials) . '</text></svg>';
            $shopImage = 'data:image/svg+xml;base64,' . base64_encode($svg);
        }

        return Inertia::render('UserSide/Repairs/repairShow', [
            'shop' => [
                'id' => $shopOwner->id,
                'name' => $shopOwner->business_name ?? $shopOwner->getFullNameAttribute(),
                'location' => $location,
                'rating' => 0, // Will be calculated from reviews on frontend
                'image' => $shopImage,
                'description' => $shopOwner->bio ?? 'Premium shoe repair and restoration services. We specialize in professional repairs for all types of footwear.',
                'hours' => $operatingHours,
                'phone' => $shopOwner->phone ?? 'Not available',
                'email' => $shopOwner->email,
                'address' => $shopOwner->business_address ?? 'Not specified',
            ],
            'repairServices' => $repairServices,
        ]);
    }
}
