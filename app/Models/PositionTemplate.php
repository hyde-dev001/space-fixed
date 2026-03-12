<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Position Template Model
 * 
 * Represents a predefined set of permissions for common job positions
 * Used to quickly apply permission sets to users during onboarding
 */
class PositionTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'category',
        'recommended_role',
        'is_active',
        'usage_count',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'usage_count' => 'integer',
    ];

    /**
     * Boot method to auto-generate slug
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($template) {
            if (empty($template->slug)) {
                $template->slug = Str::slug($template->name);
            }
        });
    }

    /**
     * Get the permissions for this template
     */
    public function templatePermissions(): HasMany
    {
        return $this->hasMany(PositionTemplatePermission::class);
    }

    /**
     * Alias for templatePermissions (for convenience)
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(PositionTemplatePermission::class);
    }

    /**
     * Get permission names as array
     */
    public function getPermissionsAttribute(): array
    {
        return $this->templatePermissions()
            ->pluck('permission_name')
            ->toArray();
    }

    /**
     * Apply this template to a user
     * 
     * @param \App\Models\User $user
     * @param bool $preserveExisting Whether to keep existing permissions
     * @return array Applied permissions
     */
    public function applyToUser(User $user, bool $preserveExisting = true): array
    {
        $templatePermissions = $this->templatePermissions()
            ->pluck('permission_name')
            ->toArray();

        if ($preserveExisting) {
            // Get existing direct permissions
            $existingPermissions = $user->getDirectPermissions()->pluck('name')->toArray();
            
            // Merge with template permissions
            $allPermissions = array_unique(array_merge($existingPermissions, $templatePermissions));
            
            $user->syncPermissions($allPermissions);
        } else {
            // Replace all direct permissions with template
            $user->syncPermissions($templatePermissions);
        }

        // Increment usage count
        $this->increment('usage_count');

        return $templatePermissions;
    }

    /**
     * Scope to only active templates
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope by category
     */
    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
