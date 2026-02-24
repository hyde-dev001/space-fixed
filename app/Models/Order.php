<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Enums\OrderStatus;
use App\Models\Notification;

class Order extends Model
{
    protected $table = 'orders';

    protected $fillable = [
        'shop_owner_id',
        'customer_id',
        'order_number',
        'total_amount',
        'status',
        'customer_name',
        'customer_email',
        'customer_phone',
        'customer_address',
        'payment_method',
        'payment_status',
        'invoice_generated',
        'invoice_id',
        // Structured address fields
        'address_id',
        'shipping_region',
        'shipping_province',
        'shipping_city',
        'shipping_barangay',
        'shipping_postal_code',
        'shipping_address_line',
        // Legacy fields (for backward compatibility)
        'customer',
        'product',
        'quantity',
        'total',
        // Pickup confirmation fields
        'pickup_enabled',
        'pickup_enabled_at',
        'pickup_enabled_by',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'total' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'quantity' => 'integer',
        'invoice_generated' => 'boolean',
        'pickup_enabled' => 'boolean',
        'pickup_enabled_at' => 'datetime',
    ];

    /**
     * Get the order items
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Get the customer who placed the order
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * Get the shop owner who received the order
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
    }

    /**
     * Relationship back to the invoice (if generated)
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Finance\Invoice::class, 'invoice_id');
    }

    /**
     * Get the structured address associated with this order
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(UserAddress::class, 'address_id');
    }

    /**
     * Get the full formatted shipping address
     * Returns structured address if available, otherwise falls back to shipping_address field
     */
    public function getFullShippingAddressAttribute(): string
    {
        if ($this->shipping_address_line && $this->shipping_barangay && $this->shipping_city) {
            $parts = array_filter([
                $this->shipping_address_line,
                $this->shipping_barangay,
                $this->shipping_city,
                $this->shipping_province,
                $this->shipping_region,
                $this->shipping_postal_code,
            ]);
            return implode(', ', $parts);
        }
        
        return $this->shipping_address ?? $this->customer_address ?? 'No address provided';
    }

    /**
     * Generate a unique order number
     */
    public static function generateOrderNumber(): string
    {
        return 'ORD-' . date('YmdHis') . '-' . str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
    }

    protected static function booted(): void
    {
        static::updated(function (Order $order): void {
            if (!$order->wasChanged('status') || !$order->customer_id) {
                return;
            }

            $status = $order->status instanceof OrderStatus
                ? $order->status->value
                : (string) $order->status;

            if ($status === 'pending') {
                return;
            }

            $statusLabel = str_replace('_', ' ', $status);

            Notification::create([
                'user_id' => $order->customer_id,
                'type' => 'order_status_update',
                'title' => 'Order Status Updated',
                'message' => "Order {$order->order_number} is now {$statusLabel}.",
                'data' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $status,
                ],
                'action_url' => '/my-orders',
                'shop_id' => $order->shop_owner_id,
            ]);
        });
    }
}
