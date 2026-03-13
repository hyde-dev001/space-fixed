<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    use HasFactory;

    protected $table = 'finance_journal_entries';

    protected $fillable = [
        'reference',
        'date',
        'description',
        'status',
        'posted_by',
        'posted_at',
        'meta',
    ];

    protected $casts = [
        'date' => 'date',
        'posted_at' => 'datetime',
        'meta' => 'array',
    ];

    public function lines()
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id');
    }
}
