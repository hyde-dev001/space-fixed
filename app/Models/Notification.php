<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\NotificationType;

class Notification extends Model
{
    protected $fillable = [
        'user_id',
        'shop_owner_id',
        'super_admin_id',
        'type',
        'priority',
        'group_key',
        'title',
        'message',
        'data',
        'action_url',
        'is_read',
        'read_at',
        'requires_action',
        'is_archived',
        'archived_at',
        'expires_at',
        'shop_id',
    ];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
        'requires_action' => 'boolean',
        'is_archived' => 'boolean',
        'read_at' => 'datetime',
        'archived_at' => 'datetime',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'type' => NotificationType::class,
    ];

    /**
     * Get the user that owns the notification
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the shop owner that owns the notification
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class, 'shop_owner_id');
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(): void
    {
        if (!$this->is_read) {
            $this->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }
    }

    /**
     * Scope query to only unread notifications
     */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    /**
     * Scope query to notifications for a specific user
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope query to notifications for a specific shop owner
     */
    public function scopeForShopOwner($query, int $shopOwnerId)
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    /**
     * Scope query to notifications for a specific super admin
     */
    public function scopeForSuperAdmin($query, int $superAdminId)
    {
        return $query->where('super_admin_id', $superAdminId);
    }

    /**
     * Create a notification record for every active super admin.
     * Used to alert all admins about platform-level events.
     */
    public static function notifyAllSuperAdmins(
        \App\Enums\NotificationType $type,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?array $data = null
    ): void {
        $admins = \App\Models\SuperAdmin::all();
        foreach ($admins as $admin) {
            static::create([
                'super_admin_id' => $admin->id,
                'type'           => $type->value,
                'title'          => $title,
                'message'        => $message,
                'action_url'     => $actionUrl,
                'data'           => $data,
                'is_read'        => false,
                'requires_action'=> true,
                'priority'       => 'high',
            ]);
        }
    }

    /**
     * Scope query to notifications of a specific type
     */
    public function scopeOfType($query, NotificationType|string $type)
    {
        $typeValue = $type instanceof NotificationType ? $type->value : $type;
        return $query->where('type', $typeValue);
    }

    /**
     * Scope query to notifications by category
     */
    public function scopeByCategory($query, string $category)
    {
        // Get all notification types for this category
        $types = collect(NotificationType::cases())
            ->filter(fn($type) => $type->category() === $category)
            ->map(fn($type) => $type->value)
            ->toArray();
            
        return $query->whereIn('type', $types);
    }

    /**
     * Scope query to notifications that require action
     */
    public function scopeRequiresAction($query)
    {
        return $query->where('requires_action', true);
    }

    /**
     * Scope query to active (not archived) notifications
     */
    public function scopeActive($query)
    {
        return $query->where('is_archived', false);
    }

    /**
     * Scope query to archived notifications
     */
    public function scopeArchived($query)
    {
        return $query->where('is_archived', true);
    }

    /**
     * Scope query by priority
     */
    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope query to high priority notifications
     */
    public function scopeHighPriority($query)
    {
        return $query->where('priority', 'high');
    }

    /**
     * Scope query by group key
     */
    public function scopeByGroup($query, string $groupKey)
    {
        return $query->where('group_key', $groupKey);
    }

    /**
     * Scope query to grouped notifications
     */
    public function scopeGrouped($query)
    {
        return $query->whereNotNull('group_key');
    }

    /**
     * Mark notification as archived
     */
    public function archive(): void
    {
        if (!$this->is_archived) {
            $this->update([
                'is_archived' => true,
                'archived_at' => now(),
            ]);
        }
    }

    /**
     * Unarchive notification
     */
    public function unarchive(): void
    {
        if ($this->is_archived) {
            $this->update([
                'is_archived' => false,
                'archived_at' => null,
            ]);
        }
    }

    /**
     * Check if notification is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Get the notification type enum instance
     */
    public function getTypeEnumAttribute(): ?NotificationType
    {
        return $this->type;
    }

    /**
     * Get the notification category
     */
    public function getCategoryAttribute(): string
    {
        return $this->type?->category() ?? 'general';
    }

    /**
     * Check if notification requires action
     */
    public function requiresAction(): bool
    {
        return $this->type?->requiresAction() ?? false;
    }

    /**
     * Get human-readable type label
     */
    public function getTypeLabelAttribute(): string
    {
        return $this->type?->label() ?? ucfirst(str_replace('_', ' ', $this->type));
    }
}

