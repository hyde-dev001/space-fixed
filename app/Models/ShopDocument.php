<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ShopDocument Model
 * 
 * Represents uploaded business documents for shop owner verification.
 * Each shop owner must upload multiple documents for admin review.
 * 
 * Database table: shop_documents
 * 
 * Document types:
 * - dti_registration / sec_registration: Business Registration authority
 * - mayors_permit: Mayor's Permit / Business Permit
 * - bir_certificate: BIR Certificate of Registration
 * - valid_id: Valid Government ID of Owner
 * 
 * Lifecycle rows are immutable after creation; review promotes a pending row
 * or records a rejection while preserving predecessor history.
 */
class ShopDocument extends Model
{
    use HasFactory;

    protected $hidden = [
        'file_path',
        'checksum_sha256',
    ];

    protected $attributes = [
        'status' => 'pending',
        'is_current' => null,
    ];

    /**
     * The attributes that are mass assignable.
     * 
     * @var array<int, string>
     */
    protected $fillable = [
        'shop_owner_id',    // Foreign key to shop_owners table
        'document_type',    // Type of document (see class docblock)
        'logical_slot',
        'version_number',
        'predecessor_document_id',
        'file_path',        // Storage path (stored in storage/app/public/shop_documents)
        'disk',
        'status',           // pending, approved, or rejected
        'is_current',
        'superseded_at',
        'issued_on',
        'expiration_mode',
        'expires_on',
        'reviewed_by_super_admin_id',
        'reviewed_at',
        'rejection_reason',
        'submission_key',
        'checksum_sha256',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'is_current' => 'boolean',
        'issued_on' => 'date',
        'expires_on' => 'date',
        'superseded_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    /**
     * Get the shop owner that owns this document
     * 
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'predecessor_document_id');
    }

    public function successors(): HasMany
    {
        return $this->hasMany(self::class, 'predecessor_document_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'reviewed_by_super_admin_id');
    }

    public function reminderDeliveries(): HasMany
    {
        return $this->hasMany(ShopDocumentReminderDelivery::class);
    }

    /**
     * Scope query to only pending documents
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope query to only approved documents
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function scopeCurrentApproved(Builder $query): Builder
    {
        return $query
            ->where('is_current', true)
            ->where('status', 'approved')
            ->whereNotNull('reviewed_by_super_admin_id')
            ->whereNotNull('reviewed_at');
    }

    public function scopePendingRenewals(Builder $query): Builder
    {
        return $query
            ->where('status', 'pending')
            ->where(function (Builder $query): void {
                $query->whereNull('is_current')->orWhere('is_current', false);
            })
            ->whereNotNull('predecessor_document_id');
    }

    public function scopeDatedReminderCandidates(Builder $query): Builder
    {
        return $query
            ->currentApproved()
            ->where('expiration_mode', 'dated')
            ->whereNotNull('expires_on');
    }

    /**
     * Get human-readable document type name
     * 
     * @return string
     */
    public function getDocumentTypeNameAttribute()
    {
        $types = [
            'dti_registration' => 'Business Registration (DTI)',
            'sec_registration' => 'Business Registration (SEC)',
            'mayors_permit' => "Mayor's Permit",
            'bir_certificate' => 'BIR Certificate',
            'valid_id' => 'Valid ID',
            'supporting_document' => 'Supporting Document',
            'other_supporting_document' => 'Other Supporting Document',
        ];

        return $types[$this->document_type] ?? $this->document_type;
    }
}
