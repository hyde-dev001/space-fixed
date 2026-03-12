<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAddress extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'region',
        'province',
        'city',
        'barangay',
        'postal_code',
        'address_line',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /**
     * Get the user that owns this address
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get formatted full address string
     */
    public function getFullAddressAttribute(): string
    {
        return implode(', ', array_filter([
            $this->address_line,
            $this->barangay,
            $this->city,
            $this->province,
            $this->region,
            $this->postal_code,
        ]));
    }

    /**
     * Set this address as default (unset others for this user)
     */
    public function setAsDefault(): void
    {
        // Unset all other addresses as default for this user
        $this->user->addresses()
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);
        
        // Set this as default
        $this->update(['is_default' => true]);
    }

    /**
     * Boot method to ensure only one default address per user
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($address) {
            // If this is being set as default, unset others
            if ($address->is_default && $address->user_id) {
                static::where('user_id', $address->user_id)
                    ->where('id', '!=', $address->id)
                    ->update(['is_default' => false]);
            }
        });
    }
}
