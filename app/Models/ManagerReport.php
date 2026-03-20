<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagerReport extends Model
{
    protected $fillable = [
        'shop_owner_id',
        'report_type',
        'date_range',
        'period_start',
        'period_end',
        'status',
        'notes',
        'report_data',
        'file_path',
        'generated_by',
        'generated_at',
        'sent_by',
        'sent_at',
        'downloaded_at',
    ];

    protected $casts = [
        'report_data' => 'array',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'generated_at' => 'datetime',
        'sent_at' => 'datetime',
        'downloaded_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
