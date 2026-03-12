<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ShopDocument Model
 * 
 * Represents uploaded business documents for shop owner verification.
 * Each shop owner must upload multiple documents for admin review.
 * 
 * Database table: shop_documents
 * 
 * Document types:
 * - dti_registration: DTI/SEC Business Registration
 * - mayors_permit: Mayor's Permit / Business Permit
 * - bir_certificate: BIR Certificate of Registration
 * - valid_id: Valid Government ID of Owner
 * 
 * Status flow matches parent ShopOwner:
 * pending -> approved/rejected
 */
class ShopDocument extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     * 
     * @var array<int, string>
     */
    protected $fillable = [
        'shop_owner_id',    // Foreign key to shop_owners table
        'document_type',    // Type of document (see class docblock)
        'file_path',        // Storage path (stored in storage/app/public/shop_documents)
        'status',           // pending, approved, or rejected
    ];

    /**
     * Get the shop owner that owns this document
     * 
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function shopOwner()
    {
        return $this->belongsTo(ShopOwner::class);
    }

    /**
     * Scope query to only pending documents
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope query to only approved documents
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Get human-readable document type name
     * 
     * @return string
     */
    public function getDocumentTypeNameAttribute()
    {
        $types = [
            'dti_registration' => 'Business Registration (DTI/SEC)',
            'mayors_permit' => "Mayor's Permit",
            'bir_certificate' => 'BIR Certificate',
            'valid_id' => 'Valid ID',
        ];

        return $types[$this->document_type] ?? $this->document_type;
    }
}
