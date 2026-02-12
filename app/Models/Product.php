<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Product extends Model
{
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'shop_owner_id',
        'name',
        'slug',
        'description',
        'price',
        'compare_at_price',
        'brand',
        'category',
        'stock_quantity',
        'is_active',
        'is_featured',
        'main_image',
        'additional_images',
        'sizes_available',
        'colors_available',
        'sku',
        'weight',
        'views_count',
        'sales_count',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'weight' => 'decimal:2',
        'stock_quantity' => 'integer',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'views_count' => 'integer',
        'sales_count' => 'integer',
        'additional_images' => 'array',
        'sizes_available' => 'array',
        'colors_available' => 'array',
    ];

    protected $attributes = [
        'category' => 'shoes',
        'is_active' => true,
        'is_featured' => false,
        'stock_quantity' => 0,
        'views_count' => 0,
        'sales_count' => 0,
    ];

    /**
     * Get the main image URL
     */
    public function getMainImageUrlAttribute(): ?string
    {
        if (!$this->main_image) {
            return null;
        }

        // If already a full URL, return as is
        if (filter_var($this->main_image, FILTER_VALIDATE_URL)) {
            return $this->main_image;
        }

        // If it already starts with /storage/, return as asset
        if (str_starts_with($this->main_image, '/storage/')) {
            return asset(ltrim($this->main_image, '/'));
        }

        // Otherwise, assume it's just the filename and prepend storage/products/
        return asset('storage/products/' . $this->main_image);
    }

    /**
     * Get all image URLs (main + additional)
     */
    public function getImageUrlsAttribute(): array
    {
        $images = [];

        // Add main image
        if ($this->main_image_url) {
            $images[] = $this->main_image_url;
        }

        // Add additional images
        if ($this->additional_images && is_array($this->additional_images)) {
            foreach ($this->additional_images as $image) {
                // If already a full URL, use as is
                if (filter_var($image, FILTER_VALIDATE_URL)) {
                    $images[] = $image;
                } else {
                    // Convert relative path to full URL
                    $images[] = asset('storage/products/' . $image);
                }
            }
        }

        return $images;
    }

    /**
     * Automatically generate slug from name
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($product) {
            if (empty($product->slug)) {
                $product->slug = Str::slug($product->name . '-' . Str::random(6));
            }
        });
    }

    /**
     * Relationship: Product belongs to a shop owner
     */
    public function shopOwner()
    {
        return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
    }

    /**
     * Relationship: Product has many variants
     */
    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * Relationship: Product has many color variants
     */
    public function colorVariants()
    {
        return $this->hasMany(ProductColorVariant::class)->orderBy('sort_order');
    }

    /**
     * Relationship: Product has many reviews
     */
    public function reviews()
    {
        return $this->hasMany(ProductReview::class);
    }

    /**
     * Get approved reviews only
     */
    public function approvedReviews()
    {
        return $this->hasMany(ProductReview::class)->where('is_approved', true);
    }

    /**
     * Get all color variant images through color variants
     */
    public function colorVariantImages()
    {
        return $this->hasManyThrough(
            ProductColorVariantImage::class,
            ProductColorVariant::class,
            'product_id',
            'product_color_variant_id'
        )->orderBy('sort_order');
    }

    /**
     * Get only thumbnail images for each color variant
     */
    public function colorThumbnails()
    {
        return $this->colorVariantImages()->where('is_thumbnail', true);
    }

    /**
     * Get total available stock across all variants
     */
    public function getTotalStockAttribute(): int
    {
        return $this->variants()->sum('quantity');
    }

    /**
     * Check if product is in stock
     */
    public function isInStock(): bool
    {
        return $this->stock_quantity > 0;
    }

    /**
     * Check if product is on sale
     */
    public function isOnSale(): bool
    {
        return $this->compare_at_price && $this->compare_at_price > $this->price;
    }

    /**
     * Get discount percentage
     */
    public function getDiscountPercentage(): ?int
    {
        if (!$this->isOnSale()) {
            return null;
        }

        return (int) round((($this->compare_at_price - $this->price) / $this->compare_at_price) * 100);
    }

    /**
     * Increment view count
     */
    public function incrementViews()
    {
        $this->increment('views_count');
    }

    /**
     * Increment sales count and decrease stock
     */
    public function recordSale(int $quantity = 1)
    {
        $this->increment('sales_count', $quantity);
        $this->decrement('stock_quantity', $quantity);
    }

    /**
     * Query scope for searching across multiple fields
     * Used by Laravel Query Builder
     */
    public function scopeSearchAll($query, $search)
    {
        return $query->where(function($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('description', 'like', "%{$search}%")
              ->orWhere('brand', 'like', "%{$search}%")
              ->orWhere('category', 'like', "%{$search}%");
        });
    }
    
    /**
     * Activity Log Configuration
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'price', 'stock_quantity', 'is_active', 'is_featured', 'category'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => "Product {$eventName}");
    }
}
