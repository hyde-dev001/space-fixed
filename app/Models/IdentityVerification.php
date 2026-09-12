<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IdentityVerification extends Model
{
    use HasFactory;

    public const SCREENING_PENDING = 'pending';

    public const SCREENING_PROCESSING = 'processing';

    public const SCREENING_AUTOMATED_CHECK_PASSED = 'automated_check_passed';

    public const SCREENING_MANUAL_REVIEW_REQUIRED = 'manual_review_required';

    public const SCREENING_REJECTED = 'rejected';

    public const REVIEW_NOT_REQUIRED = 'not_required';

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    public const REJECTION_REASONS = [
        'id_unreadable',
        'wrong_document',
        'incomplete_details',
        'suspected_altered',
        'front_back_mismatch',
        'other',
    ];

    protected $attributes = [
        'screening_status' => self::SCREENING_PENDING,
        'review_status' => self::REVIEW_NOT_REQUIRED,
        'file_disk' => 'local',
    ];

    protected $fillable = [
        'user_id',
        'document_type',
        'screening_status',
        'review_status',
        'file_path',
        'file_disk',
        'back_file_path',
        'back_file_disk',
        'ocr_confidence',
        'classification_confidence',
        'failure_reason',
        'rejection_reason',
        'rejection_notes',
        'reviewed_by',
        'reviewed_at',
        'inspected_by',
        'inspected_at',
        'supersedes_verification_id',
    ];

    protected $hidden = [
        'file_path',
        'file_disk',
        'back_file_path',
        'back_file_disk',
    ];

    protected $casts = [
        'ocr_confidence' => 'float',
        'classification_confidence' => 'float',
        'reviewed_at' => 'datetime',
        'inspected_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'reviewed_by');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'inspected_by');
    }

    public function supersededVerification(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_verification_id');
    }

    public function replacementVerifications(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_verification_id');
    }
}
