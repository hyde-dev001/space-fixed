<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class InventoryItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'shop_owner_id',
        'name',
        'sku',
        'category',
        'brand',
        'description',
        'notes',
        'unit',
        'available_quantity',
        'reserved_quantity',
        'reorder_level',
        'reorder_quantity',
        'price',
        'cost_price',
        'weight',
        'is_active',
        'main_image',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'available_quantity' => 'integer',
        'reserved_quantity' => 'integer',
        'reorder_level' => 'integer',
        'reorder_quantity' => 'integer',
        'price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'weight' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected $appends = ['status', 'total_quantity', 'total_stock_value'];

    /**
     * Get the product this inventory item is linked to
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the shop owner
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    /**
     * Get the user who created this item
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this item
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get all sizes for this inventory item
     */
    public function sizes(): HasMany
    {
        return $this->hasMany(InventorySize::class);
    }

    /**
     * Get all color variants for this inventory item
     */
    public function colorVariants(): HasMany
    {
        return $this->hasMany(InventoryColorVariant::class);
    }

    /**
     * Get all images for this inventory item
     */
    public function images(): HasMany
    {
        return $this->hasMany(InventoryImage::class);
    }

    /**
     * Get all stock movements for this inventory item
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Get all alerts for this inventory item
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(InventoryAlert::class);
    }

    /**
     * Scope a query to only include active items
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope a query to only include low stock items
     */
    public function scopeLowStock(Builder $query): void
    {
        $query->whereRaw('available_quantity > 0 AND available_quantity <= reorder_level');
    }

    /**
     * Scope a query to only include out of stock items
     */
    public function scopeOutOfStock(Builder $query): void
    {
        $query->where('available_quantity', 0);
    }

    /**
     * Scope a query by category
     */
    public function scopeByCategory(Builder $query, string $category): void
    {
        $query->where('category', $category);
    }

    /**
     * Scope a query by shop owner
     */
    public function scopeByShopOwner(Builder $query, int $shopOwnerId): void
    {
        $query->where('shop_owner_id', $shopOwnerId);
    }

    /**
     * Get the status attribute
     */
    public function getStatusAttribute(): string
    {
        if ($this->available_quantity <= 0) {
            return 'Out of Stock';
        }
        
        if ($this->available_quantity <= $this->reorder_level) {
            return 'Low Stock';
        }
        
        return 'In Stock';
    }

    /**
     * Get the total quantity (available + reserved)
     */
    public function getTotalQuantityAttribute(): int
    {
        return $this->available_quantity + $this->reserved_quantity;
    }

    /**
     * Get the total stock value
     */
    public function getTotalStockValueAttribute(): float
    {
        return ($this->cost_price ?? 0) * $this->available_quantity;
    }

    /**
     * Increment stock
     */
    public function incrementStock(int $quantity, string $type = 'stock_in', ?string $notes = null, ?int $userId = null): StockMovement
    {
        $quantityBefore = $this->available_quantity;
        $this->available_quantity += $quantity;
        $this->save();

        return $this->stockMovements()->create([
            'movement_type' => $type,
            'quantity_change' => $quantity,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $this->available_quantity,
            'notes' => $notes,
            'performed_by' => $userId,
            'performed_at' => now(),
        ]);
    }

    /**
     * Decrement stock
     */
    public function decrementStock(int $quantity, string $type = 'stock_out', ?string $notes = null, ?int $userId = null): StockMovement
    {
        $quantityBefore = $this->available_quantity;
        $this->available_quantity = max(0, $this->available_quantity - $quantity);
        $this->save();

        return $this->stockMovements()->create([
            'movement_type' => $type,
            'quantity_change' => -$quantity,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $this->available_quantity,
            'notes' => $notes,
            'performed_by' => $userId,
            'performed_at' => now(),
        ]);
    }

    /**
     * Adjust stock to a specific quantity
     */
    public function adjustStock(int $newQuantity, ?string $notes = null, ?int $userId = null): StockMovement
    {
        $quantityBefore = $this->available_quantity;
        $quantityChange = $newQuantity - $quantityBefore;
        $this->available_quantity = $newQuantity;
        $this->save();

        return $this->stockMovements()->create([
            'movement_type' => 'adjustment',
            'quantity_change' => $quantityChange,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $this->available_quantity,
            'notes' => $notes,
            'performed_by' => $userId,
            'performed_at' => now(),
        ]);
    }

    /**
     * Reserve stock
     */
    public function reserveStock(int $quantity): bool
    {
        if ($this->available_quantity < $quantity) {
            return false;
        }

        $this->available_quantity -= $quantity;
        $this->reserved_quantity += $quantity;
        $this->save();

        return true;
    }

    /**
     * Release reserved stock
     */
    public function releaseStock(int $quantity): void
    {
        $releaseAmount = min($this->reserved_quantity, $quantity);
        $this->reserved_quantity -= $releaseAmount;
        $this->available_quantity += $releaseAmount;
        $this->save();
    }

    /**
     * Check if item is at or below reorder level
     */
    public function checkReorderLevel(): bool
    {
        return $this->available_quantity <= $this->reorder_level;
    }
}
