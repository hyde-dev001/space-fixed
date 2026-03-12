<?php

namespace App\Models\HR;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Employee;
use App\Models\User;
use App\Models\ShopOwner;
use Carbon\Carbon;

class EmployeeDocument extends Model
{
    use SoftDeletes;

    protected $table = 'hr_employee_documents';

    protected $fillable = [
        'employee_id',
        'shop_owner_id',
        'document_type',
        'document_number',
        'document_name',
        'description',
        'file_path',
        'file_name',
        'file_type',
        'file_size',
        'issue_date',
        'expiry_date',
        'is_mandatory',
        'requires_renewal',
        'reminder_days',
        'status',
        'rejection_reason',
        'verified_at',
        'verified_by',
        'last_reminder_sent_at',
        'reminder_count',
        'uploaded_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'expiry_date' => 'date',
        'is_mandatory' => 'boolean',
        'requires_renewal' => 'boolean',
        'verified_at' => 'datetime',
        'last_reminder_sent_at' => 'datetime',
    ];

    // Document type constants
    const DOCUMENT_TYPES = [
        'passport' => 'Passport',
        'visa' => 'Visa',
        'work_permit' => 'Work Permit',
        'id_card' => 'ID Card',
        'drivers_license' => 'Driver\'s License',
        'birth_certificate' => 'Birth Certificate',
        'educational_certificate' => 'Educational Certificate',
        'professional_certificate' => 'Professional Certificate',
        'employment_contract' => 'Employment Contract',
        'nda' => 'Non-Disclosure Agreement',
        'background_check' => 'Background Check',
        'medical_certificate' => 'Medical Certificate',
        'insurance_card' => 'Insurance Card',
        'tax_form' => 'Tax Form',
        'bank_details' => 'Bank Details',
        'other' => 'Other',
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_VERIFIED = 'verified';
    const STATUS_REJECTED = 'rejected';
    const STATUS_EXPIRED = 'expired';

    /**
     * Relationships
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Scopes
     */
    public function scopeForShopOwner($query, $shopOwnerId)
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    public function scopeForEmployee($query, $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeOfType($query, $type)
    {
        return $query->where('document_type', $type);
    }

    public function scopeWithStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeVerified($query)
    {
        return $query->where('status', self::STATUS_VERIFIED);
    }

    public function scopeExpired($query)
    {
        return $query->where('status', self::STATUS_EXPIRED);
    }

    public function scopeExpiringWithin($query, $days)
    {
        $targetDate = Carbon::now()->addDays($days);
        return $query->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', $targetDate)
            ->where('expiry_date', '>', Carbon::now())
            ->whereIn('status', [self::STATUS_VERIFIED, self::STATUS_PENDING]);
    }

    public function scopeRequiringRenewal($query)
    {
        return $query->where('requires_renewal', true)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', Carbon::now())
            ->where('status', self::STATUS_VERIFIED);
    }

    public function scopeMandatory($query)
    {
        return $query->where('is_mandatory', true);
    }

    /**
     * Accessors & Mutators
     */
    public function getIsExpiredAttribute(): bool
    {
        if (!$this->expiry_date) {
            return false;
        }
        return Carbon::parse($this->expiry_date)->isPast();
    }

    public function getDaysUntilExpiryAttribute(): ?int
    {
        if (!$this->expiry_date) {
            return null;
        }
        return Carbon::now()->diffInDays(Carbon::parse($this->expiry_date), false);
    }

    public function getFileSizeReadableAttribute(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function getNeedsReminderAttribute(): bool
    {
        if (!$this->expiry_date || !$this->requires_renewal) {
            return false;
        }

        $daysUntilExpiry = $this->days_until_expiry;
        
        // Need reminder if within reminder window and hasn't been sent recently
        if ($daysUntilExpiry <= $this->reminder_days && $daysUntilExpiry > 0) {
            // Check if reminder was sent in last 7 days
            if ($this->last_reminder_sent_at) {
                $daysSinceLastReminder = Carbon::now()->diffInDays($this->last_reminder_sent_at);
                return $daysSinceLastReminder >= 7;
            }
            return true;
        }

        return false;
    }

    /**
     * Business Logic Methods
     */
    public function verify($verifierId): bool
    {
        $this->update([
            'status' => self::STATUS_VERIFIED,
            'verified_by' => $verifierId,
            'verified_at' => Carbon::now(),
            'rejection_reason' => null,
        ]);

        \Log::info('Document verified', [
            'document_id' => $this->id,
            'employee_id' => $this->employee_id,
            'document_type' => $this->document_type,
            'verified_by' => $verifierId,
        ]);

        return true;
    }

    public function reject($verifierId, $reason): bool
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'verified_by' => $verifierId,
            'verified_at' => Carbon::now(),
            'rejection_reason' => $reason,
        ]);

        \Log::info('Document rejected', [
            'document_id' => $this->id,
            'employee_id' => $this->employee_id,
            'document_type' => $this->document_type,
            'rejected_by' => $verifierId,
            'reason' => $reason,
        ]);

        return true;
    }

    public function markAsExpired(): bool
    {
        if ($this->status !== self::STATUS_EXPIRED) {
            $this->update(['status' => self::STATUS_EXPIRED]);

            \Log::warning('Document expired', [
                'document_id' => $this->id,
                'employee_id' => $this->employee_id,
                'document_type' => $this->document_type,
                'expiry_date' => $this->expiry_date,
            ]);

            return true;
        }

        return false;
    }

    public function recordReminderSent(): void
    {
        $this->increment('reminder_count');
        $this->update(['last_reminder_sent_at' => Carbon::now()]);

        \Log::info('Document expiry reminder sent', [
            'document_id' => $this->id,
            'employee_id' => $this->employee_id,
            'document_type' => $this->document_type,
            'days_until_expiry' => $this->days_until_expiry,
            'reminder_count' => $this->reminder_count,
        ]);
    }

    /**
     * Static Helper Methods
     */
    public static function getDocumentTypes(): array
    {
        return self::DOCUMENT_TYPES;
    }

    public static function getExpiringDocuments($shopOwnerId, $days = 30)
    {
        return self::forShopOwner($shopOwnerId)
            ->expiringWithin($days)
            ->with(['employee', 'uploader'])
            ->orderBy('expiry_date', 'asc')
            ->get();
    }

    public static function getExpiredDocuments($shopOwnerId)
    {
        return self::forShopOwner($shopOwnerId)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<', Carbon::now())
            ->whereIn('status', [self::STATUS_VERIFIED, self::STATUS_PENDING])
            ->with(['employee', 'uploader'])
            ->orderBy('expiry_date', 'desc')
            ->get();
    }

    public static function getMissingMandatoryDocuments($employeeId, $shopOwnerId)
    {
        $employee = Employee::find($employeeId);
        if (!$employee) {
            return collect();
        }

        // Get all document types that should be mandatory based on employee status
        $mandatoryTypes = ['id_card', 'employment_contract', 'tax_form', 'bank_details'];
        
        // Get existing documents for this employee
        $existingTypes = self::forEmployee($employeeId)
            ->forShopOwner($shopOwnerId)
            ->whereIn('document_type', $mandatoryTypes)
            ->verified()
            ->pluck('document_type')
            ->toArray();

        // Return missing types
        return collect(array_diff($mandatoryTypes, $existingTypes))
            ->map(fn($type) => [
                'type' => $type,
                'name' => self::DOCUMENT_TYPES[$type] ?? $type
            ]);
    }

    /**
     * Boot method for model events
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($document) {
            // Auto-set status to pending if not specified
            if (!$document->status) {
                $document->status = self::STATUS_PENDING;
            }
        });

        static::updating(function ($document) {
            // Auto-mark as expired if expiry date passes
            if ($document->expiry_date && Carbon::parse($document->expiry_date)->isPast()) {
                if ($document->status !== self::STATUS_EXPIRED) {
                    $document->status = self::STATUS_EXPIRED;
                }
            }
        });
    }
}
