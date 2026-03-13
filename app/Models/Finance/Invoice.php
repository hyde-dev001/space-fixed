<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Traits\ShopScoped;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Invoice extends Model
{
    use HasFactory, SoftDeletes, ShopScoped, LogsActivity;

    protected $table = 'finance_invoices';

    protected $fillable = [
        'reference',
        'customer_id',
        'customer_name',
        'customer_email',
        'date',
        'due_date',
        'total',
        'tax_amount',
        'status',
        'journal_entry_id',
        'payment_date',
        'payment_method',
        'job_order_id',
        'job_reference',
        'notes',
        'meta',
        'shop_id',
    ];

    protected $casts = [
        'date' => 'date',
        'due_date' => 'date',
        'payment_date' => 'date',
        'total' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'meta' => 'array',
    ];

    public function items()
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id');
    }

    /**
     * Relationship to the Job Order (Staff module)
     */
    public function jobOrder()
    {
        return $this->belongsTo(\App\Models\Order::class, 'job_order_id');
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * Generate automatic journal entry for invoice posting
     * Debit: Accounts Receivable (Asset)
     * Credit: Revenue (single account for all items)
     */
    public function createJournalEntry()
    {
        if ($this->journal_entry_id) {
            return $this->journalEntry;
        }

        // Create journal entry in draft status
        $entry = JournalEntry::create([
            'reference' => 'INV-' . $this->reference,
            'date' => $this->date,
            'description' => "Invoice #{$this->reference} from {$this->customer_name}",
            'status' => 'draft',
            'meta' => [
                'source' => 'invoice',
                'invoice_id' => $this->id,
            ],
        ]);

        $this->journal_entry_id = $entry->id;
        $this->save();

        return $entry;
    }

    /**
     * Post invoice to ledger (transitions status and posts journal entry)
     */
    public function postToLedger()
    {
        if ($this->status === 'posted') {
            return response()->json(['message' => 'Invoice already posted'], 422);
        }

        // If no journal entry exists, create one
        if (!$this->journal_entry_id) {
            $this->createJournalEntry();
        }

        $entry = $this->journalEntry;

        if ($entry) {
            $entry->status = 'posted';
            $entry->posted_at = now();
            $entry->posted_by = (string) auth()->id();
            $entry->save();
        }

        // Update invoice status
        $this->status = 'posted';
        $this->save();

        return $entry;
    }

    /**
     * Activity Log Configuration
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['reference', 'customer_name', 'total', 'status', 'payment_date', 'payment_method'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => "Invoice {$eventName}");
    }
}
