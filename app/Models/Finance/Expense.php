<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Expense extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $table = 'finance_expenses';

    protected $fillable = [
        'reference',
        'date',
        'category',
        'vendor',
        'description',
        'amount',
        'tax_amount',
        'status',
        'journal_entry_id',
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
        'meta',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'meta' => 'array',
    ];

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function expenseAccount()
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function paymentAccount()
    {
        return $this->belongsTo(Account::class, 'payment_account_id');
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
