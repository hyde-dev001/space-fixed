<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

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

    /**
     * Generate automatic journal entry for invoice posting
     * Debit: Accounts Receivable (Asset)
     * Credit: Revenue (single account for all items)
     */
    public function createJournalEntry()
    {
        // Get or create AR account - prefer existing AR account by code 1100
        $arAccount = Account::where('code', '1100')
            ->orWhere(function($q) {
                $q->where('type', 'Asset')
                  ->where('name', 'LIKE', '%Receivable%');
            })
            ->first();

        if (!$arAccount) {
            $arAccount = Account::create([
                'code' => '1100',
                'name' => 'Accounts Receivable',
                'type' => 'Asset',
                'normal_balance' => 'Debit',
                'group' => 'Receivables',
                'active' => true,
                'shop_id' => $this->shop_id,
            ]);
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

        // Create debit line for AR (total amount including tax)
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $arAccount->id,
            'account_code' => $arAccount->code,
            'account_name' => $arAccount->name,
            'debit' => $this->total,
            'credit' => 0,
            'memo' => "A/R for Invoice #{$this->reference}",
        ]);

        // Get or create default revenue account - use first active Revenue account
        $revenueAccount = Account::where('type', 'Revenue')
            ->where('active', true)
            ->orderBy('code')
            ->first();

        if (!$revenueAccount) {
            $revenueAccount = Account::create([
                'code' => '4000',
                'name' => 'Sales Revenue',
                'type' => 'Revenue',
                'normal_balance' => 'Credit',
                'group' => 'Revenue',
                'active' => true,
                'shop_id' => $this->shop_id,
            ]);
        }

        // Create single credit line for revenue
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $revenueAccount->id,
            'account_code' => $revenueAccount->code,
            'account_name' => $revenueAccount->name,
            'debit' => 0,
            'credit' => $this->total,
            'memo' => "Revenue from Invoice #{$this->reference}",
            'tax' => 'GST',
        ]);

        // Update invoice with journal entry link
        $this->update([
            'journal_entry_id' => $entry->id,
        ]);

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

        // Post the journal entry
        $entry->status = 'posted';
        $entry->posted_at = now();
        $entry->posted_by = auth()->id();
        $entry->save();

        // Update invoice status
        $this->status = 'posted';
        $this->save();

        // Update account balances
        foreach ($entry->lines as $line) {
            $account = Account::find($line->account_id);
            $oldBalance = $account->balance;

            if ($account->normal_balance === 'Debit') {
                // Debit accounts (Assets, Expenses): increase with debits, decrease with credits
                $newBalance = $oldBalance + $line->debit - $line->credit;
            } else {
                // Credit accounts (Liabilities, Equity, Revenue): increase with credits, decrease with debits
                $newBalance = $oldBalance + $line->credit - $line->debit;
            }

            \Log::info("Updating account {$account->code}: {$oldBalance} → {$newBalance}");
            $account->update(['balance' => $newBalance]);
            \Log::info("Account {$account->code} updated, new balance in DB: " . $account->fresh()->balance);
        }

        return $entry;
    }
}
