<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceChangeRequest extends Model
{
    protected $fillable = [
        'product_id',
        'product_name',
        'current_price',
        'proposed_price',
        'reason',
        'requested_by',
        'status',
        'finance_reviewed_by',
        'finance_reviewed_at',
        'finance_notes',
        'finance_rejection_reason',
        'owner_reviewed_by',
        'owner_reviewed_at',
        'owner_rejection_reason',
        'shop_owner_id',
    ];

    protected $casts = [
        'current_price' => 'decimal:2',
        'proposed_price' => 'decimal:2',
        'finance_reviewed_at' => 'datetime',
        'owner_reviewed_at' => 'datetime',
    ];

    // Relationships
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function financeReviewer()
    {
        return $this->belongsTo(User::class, 'finance_reviewed_by');
    }

    public function ownerReviewer()
    {
        return $this->belongsTo(ShopOwner::class, 'owner_reviewed_by');
    }

    public function shopOwner()
    {
        return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
    }

    // Helper methods
    public function isPending()
    {
        return $this->status === 'pending';
    }

    public function isFinanceApproved()
    {
        return $this->status === 'finance_approved';
    }

    public function isFinanceRejected()
    {
        return $this->status === 'finance_rejected';
    }

    public function isOwnerApproved()
    {
        return $this->status === 'owner_approved';
    }

    public function isOwnerRejected()
    {
        return $this->status === 'owner_rejected';
    }

    public function getPriceChangeAmount()
    {
        return $this->proposed_price - $this->current_price;
    }

    public function getPriceChangePercentage()
    {
        if ($this->current_price == 0) return 0;
        return (($this->proposed_price - $this->current_price) / $this->current_price) * 100;
    }
}
