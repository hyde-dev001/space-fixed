<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Spatie\Permission\Traits\HasRoles;
use Spatie\OpeningHours\OpeningHours;
use App\Enums\ShopOwnerStatus;

/**
 * ShopOwner Model
 * 
 * Represents a shop owner/business registration in the system.
 * Shop owners can list products and services after admin approval.
 * 
 * Database table: shop_owners
 * 
 * Relationships:
 * - Has many ShopDocument (business permits, IDs, certificates)
 * 
 * Status flow:
 * 1. pending - Initial submission, awaiting admin review
 * 2. approved - Admin approved, shop owner can access system
 * 3. rejected - Admin rejected, may include rejection_reason
 */
class ShopOwner extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable, HasRoles;

    /**
     * The guard name for this model (for Spatie Permission)
     */
    protected $guard_name = 'shop_owner';

    /**
     * The attributes that are mass assignable.
     * 
     * These fields can be set via create() or update() methods
     * 
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',           // Shop owner's first name
        'last_name',            // Shop owner's last name
        'email',                // Contact email (must be unique)
        'profile_photo',        // Profile photo path
        'bio',                  // Shop/owner bio
        'phone',                // Contact phone number
        'password',             // Hashed password for authentication
        'business_name',        // Registered business name
        'business_address',     // Physical business location
        'country',              // Country
        'city_state',           // City or state
        'postal_code',          // Postal/ZIP code
        'tax_id',               // Tax identification number
        'business_type',        // Type: retail, repair, or both
        'registration_type',    // Individual or company registration
        'operating_hours',      // JSON field storing weekly schedule
        'status',               // pending, approved, or rejected
        'rejection_reason',     // Optional reason if rejected
        'suspension_reason',    // Optional reason if suspended
        // Individual operating hours columns
        'monday_open', 'monday_close',
        'tuesday_open', 'tuesday_close',
        'wednesday_open', 'wednesday_close',
        'thursday_open', 'thursday_close',
        'friday_open', 'friday_close',
        'saturday_open', 'saturday_close',
        'sunday_open', 'sunday_close',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     * 
     * Automatically converts operating_hours JSON to array
     * when accessing the property
     * 
     * @var array<string, string>
     */
    protected $casts = [
        'operating_hours' => 'array',  // Auto JSON encode/decode
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'status' => \App\Enums\ShopOwnerStatus::class,
    ];

    /**
     * Get all documents uploaded for this shop owner
     * 
     * Includes business permits, mayor's permit, BIR certificate, valid ID
     * 
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function documents()
    {
        return $this->hasMany(ShopDocument::class);
    }

    /**
     * Get all notifications for this shop owner
     * 
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class, 'shop_owner_id');
    }

    /**
     * Get unread notifications for this shop owner
     * 
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function unreadNotifications()
    {
        return $this->notifications()->where('is_read', false);
    }

    /**
     * Get notification preferences for this shop owner
     * Note: Shop owners use the same preferences table as users via a user record
     * 
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function notificationPreferences()
    {
        // If shop owner has an associated user record, get their preferences
        // Otherwise return null - will be created when needed
        return $this->hasOne(NotificationPreference::class, 'user_id', 'id');
    }

    /**
     * Scope query to only pending registrations
     * 
     * Usage: ShopOwner::pending()->get()
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', ShopOwnerStatus::PENDING);
    }

    /**
     * Scope query to only approved shop owners
     * 
     * Usage: ShopOwner::approved()->get()
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeApproved($query)
    {
        return $query->where('status', ShopOwnerStatus::APPROVED);
    }

    /**
     * Get the full name of the shop owner
     * 
     * @return string
     */
    public function getFullNameAttribute()
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Get the name attribute (alias for business_name for backward compatibility)
     * 
     * @return string
     */
    public function getNameAttribute()
    {
        return $this->business_name;
    }

    /**
     * Get OpeningHours instance from individual day columns
     * 
     * @return OpeningHours
     */
    public function getOpeningHoursInstanceAttribute(): OpeningHours
    {
        $hours = [];
        
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $open = $this->{$day . '_open'};
            $close = $this->{$day . '_close'};
            
            // Convert time format from H:i:s to H:i if needed
            if ($open && $close) {
                $open = substr($open, 0, 5); // Strip seconds: 09:00:00 -> 09:00
                $close = substr($close, 0, 5); // Strip seconds: 17:00:00 -> 17:00
                $hours[$day] = [$open . '-' . $close];
            } else {
                $hours[$day] = [];
            }
        }
        
        return OpeningHours::create($hours);
    }

    /**
     * Check if the shop is currently open
     * 
     * @return bool
     */
    public function isOpen(): bool
    {
        return $this->opening_hours_instance->isOpen();
    }

    /**
     * Check if the shop is currently closed
     * 
     * @return bool
     */
    public function isClosed(): bool
    {
        return $this->opening_hours_instance->isClosed();
    }

    /**
     * Get the next time the shop opens
     * 
     * @return \DateTime|null
     */
    public function nextOpen(\DateTimeInterface $dateTime = null)
    {
        try {
            return $this->opening_hours_instance->nextOpen($dateTime ?? now());
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the next time the shop closes
     * 
     * @return \DateTime|null
     */
    public function nextClose(\DateTimeInterface $dateTime = null)
    {
        try {
            return $this->opening_hours_instance->nextClose($dateTime ?? now());
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get opening hours for a specific day
     * 
     * @param string $day Day of the week (monday, tuesday, etc.)
     * @return array
     */
    public function hoursForDay(string $day): array
    {
        return $this->opening_hours_instance->forDay($day);
    }

    /**
     * Check if the shop is open on a specific day
     * 
     * @param string $day Day of the week
     * @return bool
     */
    public function isOpenOn(string $day): bool
    {
        $open = $this->{strtolower($day) . '_open'};
        $close = $this->{strtolower($day) . '_close'};
        
        return !empty($open) && !empty($close);
    }

    /**
     * Check if this is an individual registration
     * 
     * @return bool
     */
    public function isIndividual(): bool
    {
        return $this->registration_type === 'individual';
    }

    /**
     * Check if this is a company registration
     * 
     * @return bool
     */
    public function isCompany(): bool
    {
        return $this->registration_type === 'company';
    }

    /**
     * Get the maximum number of locations allowed based on registration type
     * 
     * @return int|null Null means unlimited
     */
    public function getMaxLocations(): ?int
    {
        return $this->isIndividual() ? 1 : null; // Individual = 1, Company = unlimited
    }

    /**
     * Check if the shop owner can add more locations
     * 
     * @return bool
     */
    public function canAddMoreLocations(): bool
    {
        $maxLocations = $this->getMaxLocations();
        
        if ($maxLocations === null) {
            return true; // Unlimited
        }
        
        // Count existing shops/locations (assuming you have a shops relationship)
        // For now, returning true if individual has no shops yet
        return $this->isIndividual() ? true : true;
    }

    /**
     * Check if the shop owner can manage staff
     * 
     * @return bool
     */
    public function canManageStaff(): bool
    {
        return $this->isCompany();
    }

    /**
     * Get business type display name
     * 
     * @return string
     */
    public function getBusinessTypeDisplayAttribute(): string
    {
        return match($this->business_type) {
            'retail' => 'Retail',
            'repair' => 'Repair',
            'both (retail & repair)' => 'Both (Retail & Repair)',
            default => ucfirst($this->business_type),
        };
    }

    /**
     * Get registration type display name
     * 
     * @return string
     */
    public function getRegistrationTypeDisplayAttribute(): string
    {
        return ucfirst($this->registration_type);
    }
}
