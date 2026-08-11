<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use App\Models\Traits\ShopScoped;

class Expense extends Model
{
    use HasFactory, SoftDeletes, LogsActivity, ShopScoped;

    protected $table = 'finance_expenses';

    protected $fillable = [
        'reference',
        'date',
        'due_date',
        'category',
        'vendor',
        'description',
        'amount',
        'tax_amount',
        'status',
        'expense_account_id',
        'payment_account_id',
        'approved_by',
        'approved_at',
        'approval_notes',
        'receipt_path',
        'receipt_original_name',
        'receipt_mime_type',
        'receipt_size',
        'shop_id',
        'created_by',
        'purchase_order_id',
        'procurement_receipt_id',
        'meta',
        'approval_id',
        'current_approval_level',
    ];

    protected $casts = [
        'date' => 'date',
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'meta' => 'array',
    ];

    public function supplierOrder()
    {
        return $this->belongsTo(\App\Models\SupplierOrder::class, 'purchase_order_id');
    }

    public function procurementReceipt()
    {
        return $this->belongsTo(\App\Models\PurchaseOrderReceipt::class, 'procurement_receipt_id');
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    /**
     * Get the approval workflow for this expense (polymorphic)
     */
    public function approval()
    {
        return $this->morphOne(\App\Models\Approval::class, 'approvable');
    }

    public function settlements()
    {
        return $this->hasMany(ExpenseSettlement::class, 'expense_id');
    }

    public function validSettledAmount(): string
    {
        return ExpenseSettlement::validSettledAmountForExpense((int) $this->getKey());
    }

    /**
     * Query scope for date range filtering (from)
     */
    public function scopeDateFrom($query, $date)
    {
        return $query->where('date', '>=', $date);
    }

    /**
     * Query scope for date range filtering (to)
     */
    public function scopeDateTo($query, $date)
    {
        return $query->where('date', '<=', $date);
    }

    /**
     * Query scope for searching across multiple fields
     */
    public function scopeSearchAll($query, $search)
    {
        return $query->where(function($q) use ($search) {
            $q->where('reference', 'like', "%{$search}%")
              ->orWhere('category', 'like', "%{$search}%")
              ->orWhere('vendor', 'like', "%{$search}%")
              ->orWhere('description', 'like', "%{$search}%");
        });
    }
    
    /**
     * Activity Log Configuration
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['reference', 'date', 'category', 'vendor', 'amount', 'status', 'approved_by'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => "Expense {$eventName}");
    }
}
