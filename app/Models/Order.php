<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use App\Enums\OrderStatus;
use App\Models\OrderRefund;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Order extends Model
{
    use HasFactory, LogsActivity;

    public const TERMINAL_FULFILLMENT_STATUSES = ['delivered', 'completed'];

    public const TERMINAL_REFUND_STATUSES = [
        'succeeded',
        'successful',
        'rejected',
        'cancelled',
        'canceled',
    ];

    public const TERMINAL_RETURN_STATUSES = [
        'not_required',
        'received',
        'rejected',
        'cancelled',
        'canceled',
    ];

    public const OPEN_PAYMENT_STATUSES = [
        'pending',
        'partially_paid',
        'failed',
        'expired',
    ];

    protected $table = 'orders';

    protected $fillable = [
        'shop_owner_id',
        'customer_id',
        'order_number',
        'total_amount',
        'shipping_fee',
        'vat_amount',
        'vat_rate',
        'status',
        'customer_receipt_status',
        'customer_received_at',
        'customer_receipt_disputed_at',
        'customer_name',
        'customer_email',
        'customer_phone',
        'customer_address',
        'payment_method',
        'accepted_shop_policy_version_id',
        'payment_status',
        'paymongo_link_id',
        'paymongo_payment_id',
        'paymongo_refund_id',
        'cancellation_refund_window_started_at',
        'cancellation_refund_window_minutes',
        'paid_at',
        'refunded_at',
        'payment_link_created_at',
        'payment_expires_at',
        'payment_failed_at',
        'payment_failure_reason',
        'refund_reason',
        'refund_note',
        'cancellation_other_reason_note',
        'payment_expired_at',
        'payment_released_at',
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
        'assigned_staff_id',
        'assigned_at',
        'assignment_method',
        'assigned_by',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'total' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'shipping_fee' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'quantity' => 'integer',
        'invoice_generated' => 'boolean',
        'pickup_enabled' => 'boolean',
        'pickup_enabled_at' => 'datetime',
        'assigned_at' => 'datetime',
        'customer_received_at' => 'datetime',
        'customer_receipt_disputed_at' => 'datetime',
        'payment_link_created_at' => 'datetime',
        'payment_expires_at' => 'datetime',
        'payment_failed_at' => 'datetime',
        'payment_expired_at' => 'datetime',
        'payment_released_at' => 'datetime',
        'cancellation_refund_window_started_at' => 'datetime',
        'cancellation_refund_window_minutes' => 'integer',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public static function defaultCancellationRefundWindowMinutes(): int
    {
        return max(1, (int) config('orders.cancellation_refund_window_minutes', 10080));
    }

    public function resolveCancellationRefundWindowStartedAt(): ?Carbon
    {
        return $this->cancellation_refund_window_started_at
            ? $this->cancellation_refund_window_started_at->copy()->utc()
            : ($this->created_at ? $this->created_at->copy()->utc() : null);
    }

    public function resolveCancellationRefundWindowMinutes(): int
    {
        $minutes = (int) ($this->cancellation_refund_window_minutes ?? 0);

        if ($minutes > 0) {
            return $minutes;
        }

        $shopDays = null;

        if ($this->relationLoaded('shopOwner')) {
            $shopDays = (int) ($this->shopOwner?->order_refund_deadline_days ?? 0);
        } elseif (!empty($this->shop_owner_id)) {
            $shopDays = (int) ($this->shopOwner()->value('order_refund_deadline_days') ?? 0);
        }

        if ($shopDays > 0) {
            return $shopDays * 1440;
        }

        return self::defaultCancellationRefundWindowMinutes();
    }

    public function getCancellationRefundDeadlineAtAttribute(): ?Carbon
    {
        $startedAt = $this->resolveCancellationRefundWindowStartedAt();

        if (!$startedAt) {
            return null;
        }

        return $startedAt->copy()->addMinutes($this->resolveCancellationRefundWindowMinutes());
    }

    public function isCancellationRefundWindowOpen(?Carbon $referenceTime = null): bool
    {
        $deadlineAt = $this->cancellation_refund_deadline_at;

        if (!$deadlineAt) {
            return true;
        }

        $reference = $referenceTime ? $referenceTime->copy()->utc() : now()->utc();

        return $reference->lessThanOrEqualTo($deadlineAt);
    }

    public function scopePayable($query)
    {
        return $query
            ->where('payment_status', 'pending')
            ->whereNull('payment_expired_at');
    }

    public function scopeExpiredPayable($query)
    {
        return $query->payable()
            ->whereNotNull('payment_expires_at')
            ->where('payment_expires_at', '<=', now());
    }

    public function scopeTerminalFulfillment(Builder $query): Builder
    {
        return $query->whereIn(
            $query->getModel()->qualifyColumn('status'),
            self::TERMINAL_FULFILLMENT_STATUSES,
        );
    }

    public function scopeBusinessClosed(Builder $query): Builder
    {
        $paymentStatusColumn = $query->getModel()->qualifyColumn('payment_status');
        $refundStatusColumn = (new OrderRefund())->qualifyColumn('status');
        $returnStatusColumn = (new OrderRefund())->qualifyColumn('return_status');
        $normalizedPaymentStatus = "LOWER(TRIM(COALESCE({$paymentStatusColumn}, '')))";
        $normalizedRefundStatus = "LOWER(TRIM(COALESCE({$refundStatusColumn}, '')))";
        $normalizedReturnStatus = "LOWER(TRIM(COALESCE({$returnStatusColumn}, '')))";
        $terminalRefundPlaceholders = implode(',', array_fill(0, count(self::TERMINAL_REFUND_STATUSES), '?'));
        $terminalReturnPlaceholders = implode(',', array_fill(0, count(self::TERMINAL_RETURN_STATUSES), '?'));
        $openPaymentPlaceholders = implode(',', array_fill(0, count(self::OPEN_PAYMENT_STATUSES), '?'));

        return $query
            ->terminalFulfillment()
            ->whereDoesntHave('refunds', function (Builder $refundQuery) use (
                $normalizedRefundStatus,
                $normalizedReturnStatus,
                $terminalRefundPlaceholders,
                $terminalReturnPlaceholders,
            ): void {
                $refundQuery->where(function (Builder $openQuery) use (
                    $normalizedRefundStatus,
                    $normalizedReturnStatus,
                    $terminalRefundPlaceholders,
                    $terminalReturnPlaceholders,
                ): void {
                    $openQuery->where(function (Builder $statusQuery) use (
                        $normalizedRefundStatus,
                        $terminalRefundPlaceholders,
                    ): void {
                        $statusQuery
                            ->whereRaw("{$normalizedRefundStatus} = ''")
                            ->orWhereRaw(
                                "{$normalizedRefundStatus} NOT IN ({$terminalRefundPlaceholders})",
                                self::TERMINAL_REFUND_STATUSES,
                            );
                    })->orWhere(function (Builder $statusQuery) use (
                        $normalizedReturnStatus,
                        $terminalReturnPlaceholders,
                    ): void {
                        $statusQuery
                            ->whereRaw("{$normalizedReturnStatus} <> ''")
                            ->whereRaw(
                                "{$normalizedReturnStatus} NOT IN ({$terminalReturnPlaceholders})",
                                self::TERMINAL_RETURN_STATUSES,
                            );
                    });
                });
            })
            ->whereRaw(
                "{$normalizedPaymentStatus} NOT IN ({$openPaymentPlaceholders})",
                self::OPEN_PAYMENT_STATUSES,
            );
    }

    /**
     * Get the order items
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(OrderRefund::class);
    }

    public function deliveryDisputes(): HasMany
    {
        return $this->hasMany(DeliveryDispute::class);
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
     * Get the user who last updated this order
     */
    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_staff_id');
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

    public function acceptedShopPolicyVersion(): BelongsTo
    {
        return $this->belongsTo(ShopPolicyVersion::class, 'accepted_shop_policy_version_id');
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

    /**
     * Activity Log Configuration
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['order_number', 'status', 'payment_status', 'total_amount', 'customer_name'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => "Order {$eventName}");
    }
}
