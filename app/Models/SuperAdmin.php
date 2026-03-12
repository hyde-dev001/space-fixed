<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * SuperAdmin Model
 * 
 * Represents a super administrator account with full system access.
 * Extends Authenticatable to enable Laravel's authentication features.
 * 
 * Database table: super_admins
 * 
 * Authentication:
 * - Uses separate authentication guard 'super_admin'
 * - Login credentials: email + password
 * - Password is automatically hashed on creation
 * 
 * Permissions:
 * Super admins have unrestricted access to:
 * - Shop owner registration approvals
 * - User account management
 * - System analytics and reports
 * - Flagged accounts review
 * - Notification system
 * 
 * Security:
 * - Passwords are hashed using bcrypt
 * - Last login tracking for audit trail
 * - Account status allows suspension
 * - Session-based authentication
 */
class SuperAdmin extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles;

    /**
     * The guard name for this model (for Spatie Permission)
     */
    protected $guard_name = 'super_admin';

    /**
     * The table associated with the model.
     * 
     * @var string
     */
    protected $table = 'super_admins';

    /**
     * The attributes that are mass assignable.
     * 
     * These fields can be set via create() or update() methods
     * 
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',        // Administrator's first name
        'last_name',         // Administrator's last name
        'email',             // Login email (unique)
        'password',          // Hashed password
        'phone',             // Contact phone number
        'role',              // admin or super_admin
        'status',            // active, suspended, or inactive
        'last_login_at',     // Timestamp of last successful login
        'last_login_ip',     // IP address of last login
    ];

    /**
     * The attributes that should be hidden for serialization.
     * 
     * These fields won't be included in JSON responses
     * 
     * @var array<int, string>
     */
    protected $hidden = [
        'password',          // Never expose password hash
        'remember_token',    // Keep session token private
    ];

    /**
     * The attributes that should be cast.
     * 
     * Automatically converts last_login_at to Carbon datetime instance
     * 
     * @var array<string, string>
     */
    protected $casts = [
        'last_login_at' => 'datetime',  // Auto convert to Carbon instance
        'password' => 'hashed',         // Auto hash on creation (Laravel 11+)
    ];

    /**
     * Check if the admin account is active
     * 
     * Only active accounts can login to the system
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if the admin account is suspended
     * 
     * @return bool
     */
    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * Update last login information
     * 
     * Called after successful authentication to track login activity
     * 
     * @param string|null $ipAddress - Client's IP address
     * @return void
     */
    public function updateLastLogin(?string $ipAddress = null): void
    {
        $this->update([
            'last_login_at' => now(),
            'last_login_ip' => $ipAddress ?? request()->ip(),
        ]);
    }

    /**
     * Scope query to only active admins
     * 
     * Usage: SuperAdmin::active()->get()
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope query to only suspended admins
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSuspended($query)
    {
        return $query->where('status', 'suspended');
    }

    /**
     * Get the guard that should be used for authentication
     * 
     * @return string
     */
    protected function getAuthGuardName(): string
    {
        return 'super_admin';
    }
}
