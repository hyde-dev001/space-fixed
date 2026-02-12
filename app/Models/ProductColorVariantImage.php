<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductColorVariantImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_color_variant_id',
        'image_path',
        'alt_text',
        'is_thumbnail',
        'sort_order',
        'image_type',
    ];

    protected $casts = [
        'is_thumbnail' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Get the color variant that owns this image.
     */
    public function colorVariant(): BelongsTo
    {
        return $this->belongsTo(ProductColorVariant::class, 'product_color_variant_id');
    }

    /**
     * Get the product through the color variant.
     */
    public function product()
    {
        return $this->colorVariant->product();
    }

    /**
     * Get the full URL to the image.
     */
    public function getImageUrlAttribute(): string
    {
        if (filter_var($this->image_path, FILTER_VALIDATE_URL)) {
            return $this->image_path;
        }

        return Storage::url($this->image_path);
    }

    /**
     * Get the full public path to the image.
     */
    public function getPublicPathAttribute(): string
    {
        return Storage::path('public/' . $this->image_path);
    }

    /**
     * Scope for thumbnail images.
     */
    public function scopeThumbnails($query)
    {
        return $query->where('is_thumbnail', true);
    }

    /**
     * Scope for non-thumbnail images.
     */
    public function scopeGallery($query)
    {
        return $query->where('is_thumbnail', false)->orderBy('sort_order');
    }

    /**
     * Scope for specific image types.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('image_type', $type);
    }

    /**
     * Get alt text or generate from color variant info.
     */
    public function getEffectiveAltTextAttribute(): string
    {
        if ($this->alt_text) {
            return $this->alt_text;
        }

        $colorVariant = $this->colorVariant;
        $product = $colorVariant->product;
        
        $typeText = $this->is_thumbnail ? 'thumbnail' : 'gallery image';
        return "{$product->name} in {$colorVariant->color_name} - {$typeText}";
    }

    /**
     * Boot method to handle model events.
     */
    protected static function boot()
    {
        parent::boot();

        // When creating a new image, if it's marked as thumbnail,
        // remove thumbnail flag from other images in the same color variant
        static::creating(function ($image) {
            if ($image->is_thumbnail) {
                static::where('product_color_variant_id', $image->product_color_variant_id)
                      ->where('is_thumbnail', true)
                      ->update(['is_thumbnail' => false]);
            }
        });

        // Same for updating
        static::updating(function ($image) {
            if ($image->is_thumbnail && $image->isDirty('is_thumbnail')) {
                static::where('product_color_variant_id', $image->product_color_variant_id)
                      ->where('id', '!=', $image->id)
                      ->where('is_thumbnail', true)
                      ->update(['is_thumbnail' => false]);
            }
        });
    }
}