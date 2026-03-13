<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JournalLine extends Model
{
    use HasFactory;

    protected $table = 'finance_journal_lines';

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'account_code',
        'account_name',
        'debit',
        'credit',
        'memo',
        'tax',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
