<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Permission Audit Log Model
 * 
 * Tracks all changes to user permissions and roles for compliance.
 * Critical for security audits, regulatory compliance, and incident investigation.
 * 
 * Usage:
 *   PermissionAuditLog::logRoleAssigned($user, 'Manager', 'Promoted to management');
 *   PermissionAuditLog::logPermissionGranted($user, 'approve-expenses');
 */
class PermissionAuditLog extends Model
{
    const UPDATED_AT = null; // Only created_at, no updates

    protected $fillable = [
        'shop_owner_id',
        'actor_id',
        'actor_type',
        'actor_name',
        'subject_id',
        'subject_type',
        'subject_name',
        'action',
        'role_name',
        'permission_name',
        'position_name',
        'position_template_id',
        'old_value',
        'new_value',
        'reason',
        'notes',
        'severity',
        'ip_address',
        'user_agent',
        'request_method',
        'request_url',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'created_at' => 'datetime',
    ];

    // ========================================
    // RELATIONSHIPS
    // ========================================

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_id');
    }

    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    // ========================================
    // STATIC LOGGING METHODS
    // ========================================

    /**
     * Log role assignment
     */
    public static function logRoleAssigned(
        User $user, 
        string $roleName, 
        ?string $reason = null,
        string $severity = 'medium'
    ): self {
        return self::createLog([
            'subject_id' => $user->id,
            'subject_name' => $user->name,
            'action' => 'role_assigned',
            'role_name' => $roleName,
            'new_value' => ['role' => $roleName],
            'reason' => $reason,
            'severity' => $severity,
        ]);
    }

    /**
     * Log role removal
     */
    public static function logRoleRemoved(
        User $user,
        string $roleName,
        ?string $reason = null,
        string $severity = 'high'
    ): self {
        return self::createLog([
            'subject_id' => $user->id,
            'subject_name' => $user->name,
            'action' => 'role_removed',
            'role_name' => $roleName,
            'old_value' => ['role' => $roleName],
            'reason' => $reason,
            'severity' => $severity,
        ]);
    }

    /**
     * Log permission granted
     */
    public static function logPermissionGranted(
        User $user,
        string $permissionName,
        ?string $reason = null,
        string $severity = 'medium'
    ): self {
        return self::createLog([
            'subject_id' => $user->id,
            'subject_name' => $user->name,
            'action' => 'permission_granted',
            'permission_name' => $permissionName,
            'new_value' => ['permission' => $permissionName],
            'reason' => $reason,
            'severity' => $severity,
        ]);
    }

    /**
     * Log permission revoked
     */
    public static function logPermissionRevoked(
        User $user,
        string $permissionName,
        ?string $reason = null,
        string $severity = 'high'
    ): self {
        return self::createLog([
            'subject_id' => $user->id,
            'subject_name' => $user->name,
            'action' => 'permission_revoked',
            'permission_name' => $permissionName,
            'old_value' => ['permission' => $permissionName],
            'reason' => $reason,
            'severity' => $severity,
        ]);
    }

    /**
     * Log position assignment (with template)
     */
    public static function logPositionAssigned(
        User $user,
        string $positionName,
        ?int $templateId = null,
        array $permissions = [],
        ?string $reason = null
    ): self {
        return self::createLog([
            'subject_id' => $user->id,
            'subject_name' => $user->name,
            'action' => 'position_assigned',
            'position_name' => $positionName,
            'position_template_id' => $templateId,
            'new_value' => [
                'position' => $positionName,
                'template_id' => $templateId,
                'permissions' => $permissions,
            ],
            'reason' => $reason,
            'severity' => 'medium',
        ]);
    }

    /**
     * Log role change (from one role to another)
     */
    public static function logRoleChanged(
        User $user,
        string $oldRole,
        string $newRole,
        ?string $reason = null
    ): self {
        return self::createLog([
            'subject_id' => $user->id,
            'subject_name' => $user->name,
            'action' => 'role_changed',
            'role_name' => $newRole,
            'old_value' => ['role' => $oldRole],
            'new_value' => ['role' => $newRole],
            'reason' => $reason,
            'severity' => 'high',
        ]);
    }

    /**
     * Log bulk permission sync
     */
    public static function logPermissionsSynced(
        User $user,
        array $oldPermissions,
        array $newPermissions,
        ?string $reason = null
    ): self {
        $added = array_diff($newPermissions, $oldPermissions);
        $removed = array_diff($oldPermissions, $newPermissions);

        return self::createLog([
            'subject_id' => $user->id,
            'subject_name' => $user->name,
            'action' => 'permissions_synced',
            'old_value' => [
                'permissions' => $oldPermissions,
                'count' => count($oldPermissions),
            ],
            'new_value' => [
                'permissions' => $newPermissions,
                'count' => count($newPermissions),
                'added' => array_values($added),
                'removed' => array_values($removed),
            ],
            'reason' => $reason,
            'severity' => 'high',
        ]);
    }

    // ========================================
    // QUERY SCOPES
    // ========================================

    public function scopeForShop($query, int $shopOwnerId)
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('subject_id', $userId);
    }

    public function scopeByActor($query, int $actorId)
    {
        return $query->where('actor_id', $actorId);
    }

    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeDateRange($query, $from, $to)
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    public function scopeHighSeverity($query)
    {
        return $query->whereIn('severity', ['high', 'critical']);
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    /**
     * Create audit log with automatic context
     */
    private static function createLog(array $data): self
    {
        // Auto-capture actor information
        $actor = Auth::user();
        $data['actor_id'] = $actor?->id;
        $data['actor_type'] = $actor ? get_class($actor) : 'System';
        $data['actor_name'] = $actor?->name ?? 'System';

        // Auto-capture shop owner
        if (!isset($data['shop_owner_id'])) {
            $data['shop_owner_id'] = $actor?->shop_owner_id ?? 1;
        }

        // Auto-capture request metadata
        $data['ip_address'] = Request::ip();
        $data['user_agent'] = Request::userAgent();
        $data['request_method'] = Request::method();
        $data['request_url'] = Request::fullUrl();

        return self::create($data);
    }

    /**
     * Get compliance summary for a date range
     */
    public static function getComplianceReport(int $shopOwnerId, $from, $to): array
    {
        $logs = self::forShop($shopOwnerId)
            ->dateRange($from, $to)
            ->orderBy('created_at', 'desc')
            ->get();

        return [
            'total_changes' => $logs->count(),
            'high_severity_count' => $logs->where('severity', 'high')->count(),
            'critical_count' => $logs->where('severity', 'critical')->count(),
            'by_action' => $logs->groupBy('action')->map->count(),
            'by_actor' => $logs->groupBy('actor_name')->map->count(),
            'by_subject' => $logs->groupBy('subject_name')->map->count(),
            'recent_critical' => $logs->where('severity', 'critical')->take(10)->values(),
            'period' => [
                'from' => $from,
                'to' => $to,
            ],
        ];
    }

    /**
     * Get user's permission history
     */
    public static function getUserHistory(int $userId, int $limit = 50): array
    {
        return self::forUser($userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($log) {
                return [
                    'date' => $log->created_at->format('Y-m-d H:i:s'),
                    'action' => $log->action,
                    'performed_by' => $log->actor_name ?? 'System',
                    'details' => $log->getChangeDescription(),
                    'reason' => $log->reason,
                    'severity' => $log->severity,
                ];
            })
            ->toArray();
    }

    /**
     * Get human-readable description of change
     */
    public function getChangeDescription(): string
    {
        return match($this->action) {
            'role_assigned' => "Assigned role: {$this->role_name}",
            'role_removed' => "Removed role: {$this->role_name}",
            'permission_granted' => "Granted permission: {$this->permission_name}",
            'permission_revoked' => "Revoked permission: {$this->permission_name}",
            'position_assigned' => "Assigned position: {$this->position_name}",
            'role_changed' => "Changed role from {$this->old_value['role']} to {$this->new_value['role']}",
            'permissions_synced' => "Updated permissions (+" . count($this->new_value['added'] ?? []) . ", -" . count($this->new_value['removed'] ?? []) . ")",
            default => "Unknown action: {$this->action}",
        };
    }

    /**
     * Check if this change requires approval/review
     */
    public function requiresReview(): bool
    {
        return in_array($this->severity, ['high', 'critical']) ||
               in_array($this->action, ['role_removed', 'permissions_synced']);
    }
}
