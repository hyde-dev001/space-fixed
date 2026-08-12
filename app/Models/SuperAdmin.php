<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public const ROLE_ADMIN = 'admin';
    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_PENDING_SETUP = 'pending_setup';

    public const CAP_VIEW_MONITORING = 'view_monitoring';
    public const CAP_REVIEW_REGISTRATIONS = 'review_registrations';
    public const CAP_INTERVENE_ACCOUNTS = 'intervene_accounts';
    public const CAP_MODERATE_REPORTS = 'moderate_reports';
    public const CAP_VIEW_APPEALS = 'view_appeals';
    public const CAP_RESOLVE_APPEALS = 'resolve_appeals';
    public const CAP_MANAGE_ADMINISTRATORS = 'manage_administrators';
    public const CAP_MANAGE_PLANS = 'manage_plans';
    public const CAP_INTERVENE_SUBSCRIPTIONS = 'intervene_subscriptions';
    public const CAP_VIEW_PRIVILEGED_AUDIT = 'view_privileged_audit';
    public const CAP_MANAGE_OWN_SECURITY = 'manage_own_security';
    public const CAP_MANAGE_PLATFORM_SECURITY = 'manage_platform_security';

    private const CAPABILITIES_BY_ROLE = [
        self::ROLE_ADMIN => [
            self::CAP_VIEW_MONITORING,
            self::CAP_REVIEW_REGISTRATIONS,
            self::CAP_INTERVENE_ACCOUNTS,
            self::CAP_MODERATE_REPORTS,
            self::CAP_VIEW_APPEALS,
            self::CAP_VIEW_PRIVILEGED_AUDIT,
            self::CAP_MANAGE_OWN_SECURITY,
        ],
        self::ROLE_SUPER_ADMIN => [
            self::CAP_VIEW_MONITORING,
            self::CAP_REVIEW_REGISTRATIONS,
            self::CAP_INTERVENE_ACCOUNTS,
            self::CAP_MODERATE_REPORTS,
            self::CAP_VIEW_APPEALS,
            self::CAP_RESOLVE_APPEALS,
            self::CAP_MANAGE_ADMINISTRATORS,
            self::CAP_MANAGE_PLANS,
            self::CAP_INTERVENE_SUBSCRIPTIONS,
            self::CAP_VIEW_PRIVILEGED_AUDIT,
            self::CAP_MANAGE_OWN_SECURITY,
            self::CAP_MANAGE_PLATFORM_SECURITY,
        ],
    ];

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
        'mfa_secret',
        'mfa_recovery_codes',
        'bootstrap_marker',
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
        'mfa_secret' => 'encrypted',
        'mfa_recovery_codes' => 'array',
        'mfa_confirmed_at' => 'datetime',
        'mfa_last_used_timestep' => 'integer',
        'security_version' => 'integer',
        'password_changed_at' => 'datetime',
    ];

    protected $attributes = [
        'security_version' => 1,
    ];

    public function hasCompletedMfaSetup(): bool
    {
        return $this->mfa_confirmed_at !== null
            && $this->mfa_secret !== null
            && $this->mfa_recovery_codes !== null;
    }

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, self::CAPABILITIES_BY_ROLE[$this->role] ?? [], true);
    }

    /**
     * Check if the admin account is active
     * 
     * Only active accounts can login to the system
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if the admin account is suspended
     * 
     * @return bool
     */
    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
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
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope query to only suspended admins
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSuspended($query)
    {
        return $query->where('status', self::STATUS_SUSPENDED);
    }

    public function securityTokens(): HasMany
    {
        return $this->hasMany(PrivilegedSecurityToken::class);
    }

    public function privilegedSessions(): HasMany
    {
        return $this->hasMany(PrivilegedSession::class);
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
