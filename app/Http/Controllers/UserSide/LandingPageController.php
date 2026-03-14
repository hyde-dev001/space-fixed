<?php

namespace App\Http\Controllers\UserSide;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\RepairPackage;
use App\Models\RepairService;
use App\Models\ShopOwner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->with([
                'shopOwner:id,first_name,last_name,business_name',
                'colorVariants' => function ($query) {
                    $query->where('is_active', true)->orderBy('sort_order');
                },
                'colorVariants.images' => function ($query) {
                    $query->orderBy('sort_order');
                },
            ])
            ->orderBy('created_at', 'desc')
            ->take(3)
            ->get()
            ->map(function ($product) {
                $mediaImages = collect($product->image_urls ?? [])
                    ->pluck('url')
                    ->filter()
                    ->values();

                $variantImages = collect($product->colorVariants ?? [])
                    ->flatMap(function ($variant) {
                        return collect($variant->images ?? [])->map(function ($img) {
                            return $img->image_url;
                        });
                    })
                    ->filter()
                    ->values();

                $allImages = $mediaImages
                    ->merge($variantImages)
                    ->filter()
                    ->unique()
                    ->values();

                $mainImage = $product->main_image_url;
                $hoverImage = $allImages->first(function ($url) use ($mainImage) {
                    return $url !== $mainImage;
                });

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'description' => $product->description,
                    'price' => $product->price,
                    'compare_at_price' => $product->compare_at_price,
                    'main_image' => $mainImage,
                    'hover_image' => $hoverImage,
                    'gallery_images' => $allImages
                        ->filter(function ($url) use ($mainImage) {
                            return $url !== $mainImage;
                        })
                        ->values()
                        ->all(),
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
     * Return search suggestions for products and shop profiles.
     */
    public function searchSuggestions(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('query', ''));
        $normalizedQuery = strtolower($query);
        $isShowroomQuery = str_contains($normalizedQuery, 'showroom');
        $showroomQualifier = trim((string) preg_replace('/\bshowroom\b/i', '', $query));

        if ($query === '') {
            return response()->json([
                'query' => $query,
                'products' => [],
                'shops' => [],
            ]);
        }

        $products = Product::query()
            ->where('is_active', true)
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('brand', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%")
                    ->orWhere('category', 'like', "%{$query}%");
            })
            ->whereHas('shopOwner', function ($q) {
                $q->where('status', 'approved');
            })
            ->with('shopOwner:id,business_name')
            ->orderByDesc('created_at')
            ->limit(6)
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'category' => $product->category,
                    'main_image' => $product->main_image_url,
                    'shop_name' => $product->shopOwner?->business_name,
                    'url' => route('products.show', ['slug' => $product->slug]),
                ];
            })
            ->values();

        $shopsQuery = ShopOwner::query()
            ->where('status', 'approved');

        if ($isShowroomQuery) {
            // Showroom search returns only shops that can actually showcase products.
            $shopsQuery
                ->whereIn('business_type', ['retail', 'both'])
                ->whereIn('id', Product::query()
                    ->where('is_active', true)
                    ->select('shop_owner_id')
                    ->distinct());

            if ($showroomQualifier !== '') {
                $shopsQuery->where(function ($q) use ($showroomQualifier) {
                    $q->where('business_name', 'like', "%{$showroomQualifier}%")
                        ->orWhere('first_name', 'like', "%{$showroomQualifier}%")
                        ->orWhere('last_name', 'like', "%{$showroomQualifier}%")
                        ->orWhere('business_address', 'like', "%{$showroomQualifier}%")
                        ->orWhere('city_state', 'like', "%{$showroomQualifier}%");
                });
            }
        } else {
            $shopsQuery->where(function ($q) use ($query) {
                $q->where('business_name', 'like', "%{$query}%")
                    ->orWhere('first_name', 'like', "%{$query}%")
                    ->orWhere('last_name', 'like', "%{$query}%")
                    ->orWhere('business_address', 'like', "%{$query}%")
                    ->orWhere('city_state', 'like', "%{$query}%")
                    ->orWhere('business_type', 'like', "%{$query}%");
            });
        }

        $shops = $shopsQuery
            ->orderBy('business_name')
            ->limit($isShowroomQuery ? 24 : 6)
            ->get()
            ->map(function ($shop) {
                $profilePhoto = $shop->profile_photo;

                if ($profilePhoto) {
                    $image = str_starts_with($profilePhoto, '/')
                        ? asset(ltrim($profilePhoto, '/'))
                        : asset('storage/' . ltrim($profilePhoto, '/'));
                } else {
                    $image = null;
                }

                return [
                    'id' => $shop->id,
                    'name' => $shop->business_name ?: trim($shop->first_name . ' ' . $shop->last_name),
                    'location' => trim((string) $shop->city_state),
                    'image' => $image,
                    'url' => route('shop-profile', ['id' => $shop->id]),
                    'virtual_showroom_url' => route('shop-profile.virtual-showroom', ['id' => $shop->id]),
                ];
            })
            ->values();

        return response()->json([
            'query' => $query,
            'products' => $products,
            'shops' => $shops,
        ]);
    }

    /**
     * Display a single product page.
     */
    public function productShow(string $slug): Response
    {
        $product = Product::where('is_active', true)
            ->where(function ($query) use ($slug) {
                $query->where('slug', $slug);

                if (is_numeric($slug)) {
                    $query->orWhere('id', (int) $slug);
                }
            })
            ->with([
                'shopOwner:id,first_name,last_name,business_name',
                'variants',
                'colorVariants' => function ($query) {
                    $query->where('is_active', true)->orderBy('sort_order');
                },
                'colorVariants.images' => function ($query) {
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
                'variants' => $product->variants ? $product->variants->map(function ($variant) {
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
                'colorVariants' => $product->colorVariants ? $product->colorVariants->map(function ($variant) {
                    return [
                        'id' => $variant->id,
                        'color_name' => $variant->color_name,
                        'color_code' => $variant->color_code,
                        'sku_prefix' => $variant->sku_prefix,
                        'sort_order' => $variant->sort_order,
                        'is_active' => $variant->is_active,
                        'images' => $variant->images ? $variant->images->map(function ($img) {
                            return [
                                'id' => $img->id,
                                'image_url' => $img->image_url,
                                'image_path' => $img->image_url,
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
            ->where(function ($query) {
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
                    'latitude' => $shop->shop_latitude ? (float) $shop->shop_latitude : null,
                    'longitude' => $shop->shop_longitude ? (float) $shop->shop_longitude : null,
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
        [$shop, $products] = $this->buildShopProfileData($id);

        return Inertia::render('UserSide/Profile/ShopProfile', [
            'shop' => $shop,
            'products' => $products,
        ]);
    }

    /**
     * Display full-screen virtual showroom page
     */
    public function virtualShowroom(string $id): Response
    {
        [$shop, $products] = $this->buildShopProfileData($id);

        return Inertia::render('UserSide/Profile/VirtualShowroomPage', [
            'shop' => $shop,
            'products' => $products,
        ]);
    }

    /**
     * Shared payload builder for shop profile and virtual showroom pages.
     */
    private function buildShopProfileData(string $id): array
    {
        if ($id === 'null' || !is_numeric($id)) {
            abort(404, 'Shop not found');
        }

        $shopOwner = ShopOwner::where('id', (int)$id)
            ->where('status', 'approved')
            ->firstOrFail();

        $products = Product::where('shop_owner_id', $id)
            ->where('is_active', true)
            ->with([
                'colorVariants' => function ($query) {
                    $query->active()->orderBy('sort_order');
                },
                'colorVariants.images' => function ($query) {
                    $query->orderBy('sort_order');
                }
            ])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($product) {
                $mainImage = $product->main_image_url;

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

                $legacyAdditionalImages = collect($product->additional_images ?? [])
                    ->map(function ($image) {
                        if (!$image) {
                            return null;
                        }

                        if (filter_var($image, FILTER_VALIDATE_URL)) {
                            return $image;
                        }

                        if (str_starts_with($image, '/')) {
                            return $image;
                        }

                        return asset('storage/products/' . ltrim($image, '/'));
                    })
                    ->filter();

                $allImages = $mediaImages
                    ->merge($variantImages)
                    ->merge($legacyAdditionalImages)
                    ->unique()
                    ->values();

                $galleryImages = $allImages
                    ->filter(fn($url) => $url && $url !== $mainImage)
                    ->values();

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'price' => $product->price,
                    'compare_at_price' => $product->compare_at_price,
                    'brand' => $product->brand,
                    'category' => $product->category,
                    'stock_quantity' => $product->stock_quantity,
                    'main_image' => $mainImage ?: ($allImages->first() ?? null),
                    'gallery_images' => $galleryImages,
                    'hover_image' => $galleryImages->first() ?? null,
                    'description' => $product->description,
                ];
            });

        $shop = [
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
        ];

        return [$shop, $products];
    }

    /**
     * Display a specific repair shop's services page
     */
    public function repairShow(string $id): Response
    {
        $shopOwner = ShopOwner::where('id', (int)$id)
            ->where('status', 'approved')
            ->where(function ($query) {
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
            ->where('status', 'Active')
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

        $repairPackages = RepairPackage::query()
            ->where('shop_owner_id', $shopOwner->id)
            ->active()
            ->with('services:id,name,category,price,duration,status,shop_owner_id')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (RepairPackage $package) {
                $serviceTotal = $package->services->sum(fn ($service) => (float) $service->price);

                return [
                    'id' => $package->id,
                    'name' => $package->name,
                    'description' => $package->description,
                    'package_price' => (float) $package->package_price,
                    'service_count' => $package->services->count(),
                    'services_total_price' => round($serviceTotal, 2),
                    'savings_amount' => round(max($serviceTotal - (float) $package->package_price, 0), 2),
                    'services' => $package->services->map(fn ($service) => [
                        'id' => $service->id,
                        'name' => $service->name,
                        'category' => $service->category,
                        'price' => (float) $service->price,
                        'duration' => $service->duration,
                    ])->values(),
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
            'repairPackages' => $repairPackages,
        ]);
    }
}
