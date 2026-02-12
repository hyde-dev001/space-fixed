<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasApiTokens, Notifiable, LogsActivity, HasRoles;
    
    /**
     * The guard name for this model (for Spatie Permission)
     */
    protected $guard_name = 'user';
    
    /**
     * Override to ensure guard name is always 'user'
     */
    public function getDefaultGuardName()
    {
        return 'user';
    }
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'name',
        'email',
        'phone',
        'age',
        'address',
        'valid_id_path',
        'password',
        'status',
        'last_login_at',
        'last_login_ip',
        'shop_owner_id',
        'role',
        'approval_limit',
        'force_password_change',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'password' => 'hashed',
        'force_password_change' => 'boolean',
    ];

    /**
     * Get the shop owner that this user belongs to
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    /**
     * Get the related employee record by email (if any)
     */
    public function employee()
    {
        return $this->hasOne(Employee::class, 'email', 'email');
    }

    /**
     * Get all shipping addresses for this user
     */
    public function addresses()
    {
        return $this->hasMany(UserAddress::class);
    }

    /**
     * Get the default shipping address
     */
    public function defaultAddress()
    {
        return $this->hasOne(UserAddress::class)->where('is_default', true);
    }

    /**
     * Check if user has a specific role (OLD COLUMN - Deprecated)
     * Use Spatie's hasRole() method instead for new code
     * This is kept for backward compatibility only
     */
    public function hasOldRole($role): bool
    {
        return strtoupper((string) $this->role) === strtoupper((string) $role);
    }

    /**
     * Check if user has any of the given roles (OLD COLUMN - Deprecated)
     * Use Spatie's hasAnyRole() method instead for new code
     * This is kept for backward compatibility only
     */
    public function hasAnyOldRole($roles): bool
    {
        $userRole = strtoupper((string) $this->role);
        $rolesArray = array_map('strtoupper', (array) $roles);
        return in_array($userRole, $rolesArray);
    }

    /**
     * Get available ERP module roles for the user's shop
     */
    public function getAvailableModuleRoles(): array
    {
        return ['MANAGER', 'STAFF'];
    }
    
    /**
     * Activity Log Configuration
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'role', 'status', 'approval_limit'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => "Employee {$eventName}");
    }
}
