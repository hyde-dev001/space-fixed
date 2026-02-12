<?php

namespace App\Models\HR;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Models\ShopOwner;
use App\Models\Employee;

class AuditLog extends Model
{
    protected $table = 'hr_audit_logs';

    protected $fillable = [
        'shop_owner_id',
        'user_id',
        'employee_id',
        'module',
        'action',
        'entity_type',
        'entity_id',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'request_method',
        'request_url',
        'severity',
        'tags',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'tags' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Module constants for consistency
     */
    public const MODULE_EMPLOYEE = 'employee';
    public const MODULE_LEAVE = 'leave';
    public const MODULE_PAYROLL = 'payroll';
    public const MODULE_ATTENDANCE = 'attendance';
    public const MODULE_PERFORMANCE = 'performance';
    public const MODULE_DEPARTMENT = 'department';
    public const MODULE_DOCUMENT = 'document';

    /**
     * Action constants
     */
    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_DELETED = 'deleted';
    public const ACTION_VIEWED = 'viewed';
    public const ACTION_APPROVED = 'approved';
    public const ACTION_REJECTED = 'rejected';
    public const ACTION_SUSPENDED = 'suspended';
    public const ACTION_ACTIVATED = 'activated';
    public const ACTION_GENERATED = 'generated';
    public const ACTION_EXPORTED = 'exported';
    public const ACTION_DOWNLOADED = 'downloaded';
    public const ACTION_VERIFIED = 'verified';
    public const ACTION_CHECKED_IN = 'checked_in';
    public const ACTION_CHECKED_OUT = 'checked_out';

    /**
     * Severity constants
     */
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    // ==================== RELATIONSHIPS ====================

    /**
     * User who performed the action
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Shop owner (multi-tenant isolation)
     */
    public function shopOwner(): BelongsTo
    {
        return $this->belongsTo(ShopOwner::class);
    }

    /**
     * Employee affected by the action
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    // ==================== QUERY SCOPES ====================

    /**
     * Filter by shop owner (multi-tenant)
     */
    public function scopeForShopOwner($query, $shopOwnerId)
    {
        return $query->where('shop_owner_id', $shopOwnerId);
    }

    /**
     * Filter by user
     */
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Filter by employee
     */
    public function scopeForEmployee($query, $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    /**
     * Filter by module
     */
    public function scopeInModule($query, $module)
    {
        return $query->where('module', $module);
    }

    /**
     * Filter by action
     */
    public function scopeWithAction($query, $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Filter by severity
     */
    public function scopeBySeverity($query, $severity)
    {
        return $query->where('severity', $severity);
    }

    /**
     * Get critical logs only
     */
    public function scopeCritical($query)
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    /**
     * Get logs within date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Get recent logs (last N days)
     */
    public function scopeRecent($query, $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Filter by entity type and ID
     */
    public function scopeForEntity($query, $entityType, $entityId)
    {
        return $query->where('entity_type', $entityType)
            ->where('entity_id', $entityId);
    }

    /**
     * Search in description
     */
    public function scopeSearch($query, $searchTerm)
    {
        return $query->where('description', 'like', "%{$searchTerm}%");
    }

    /**
     * Filter by tag
     */
    public function scopeWithTag($query, $tag)
    {
        return $query->whereJsonContains('tags', $tag);
    }

    /**
     * Filter by IP address
     */
    public function scopeFromIp($query, $ipAddress)
    {
        return $query->where('ip_address', $ipAddress);
    }

    // ==================== STATIC HELPER METHODS ====================

    /**
     * Create audit log entry with automatic context capture
     */
    public static function createLog(array $data): self
    {
        $request = request();
        
        // Safely get auth data - avoid calling during bootstrap
        $user = auth()->check() ? auth()->user() : null;

        return self::create(array_merge([
            'shop_owner_id' => $user?->shop_owner_id ?? null,
            'user_id' => $user?->id ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_method' => $request->method(),
            'request_url' => $request->fullUrl(),
        ], $data));
    }

    /**
     * Log entity creation
     */
    public static function logCreated(string $module, Model $entity, string $description, array $tags = []): self
    {
        return self::createLog([
            'module' => $module,
            'action' => self::ACTION_CREATED,
            'entity_type' => get_class($entity),
            'entity_id' => $entity->id,
            'employee_id' => $entity->employee_id ?? null,
            'description' => $description,
            'new_values' => $entity->toArray(),
            'severity' => self::SEVERITY_WARNING,
            'tags' => array_merge(['create'], $tags),
        ]);
    }

    /**
     * Log entity update
     */
    public static function logUpdated(string $module, Model $entity, array $oldValues, string $description, array $tags = []): self
    {
        return self::createLog([
            'module' => $module,
            'action' => self::ACTION_UPDATED,
            'entity_type' => get_class($entity),
            'entity_id' => $entity->id,
            'employee_id' => $entity->employee_id ?? null,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $entity->toArray(),
            'severity' => self::SEVERITY_WARNING,
            'tags' => array_merge(['update'], $tags),
        ]);
    }

    /**
     * Log entity deletion
     */
    public static function logDeleted(string $module, Model $entity, string $description, array $tags = []): self
    {
        return self::createLog([
            'module' => $module,
            'action' => self::ACTION_DELETED,
            'entity_type' => get_class($entity),
            'entity_id' => $entity->id,
            'employee_id' => $entity->employee_id ?? null,
            'description' => $description,
            'old_values' => $entity->toArray(),
            'severity' => self::SEVERITY_CRITICAL,
            'tags' => array_merge(['delete', 'critical'], $tags),
        ]);
    }

    /**
     * Log approval action
     */
    public static function logApproved(string $module, Model $entity, string $description, array $tags = []): self
    {
        return self::createLog([
            'module' => $module,
            'action' => self::ACTION_APPROVED,
            'entity_type' => get_class($entity),
            'entity_id' => $entity->id,
            'employee_id' => $entity->employee_id ?? null,
            'description' => $description,
            'new_values' => ['status' => 'approved'],
            'severity' => self::SEVERITY_WARNING,
            'tags' => array_merge(['approval', 'workflow'], $tags),
        ]);
    }

    /**
     * Log rejection action
     */
    public static function logRejected(string $module, Model $entity, string $reason, array $tags = []): self
    {
        return self::createLog([
            'module' => $module,
            'action' => self::ACTION_REJECTED,
            'entity_type' => get_class($entity),
            'entity_id' => $entity->id,
            'employee_id' => $entity->employee_id ?? null,
            'description' => "Rejected: {$reason}",
            'new_values' => ['status' => 'rejected', 'reason' => $reason],
            'severity' => self::SEVERITY_WARNING,
            'tags' => array_merge(['rejection', 'workflow'], $tags),
        ]);
    }

    /**
     * Log sensitive data access (viewing salary, documents, etc.)
     */
    public static function logSensitiveAccess(string $module, string $entityType, int $entityId, string $description): self
    {
        return self::createLog([
            'module' => $module,
            'action' => self::ACTION_VIEWED,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'description' => $description,
            'severity' => self::SEVERITY_INFO,
            'tags' => ['sensitive', 'access', 'view'],
        ]);
    }

    /**
     * Get audit statistics for dashboard
     */
    public static function getStatistics($shopOwnerId, $days = 30)
    {
        $query = self::forShopOwner($shopOwnerId)->recent($days);

        return [
            'total_logs' => $query->count(),
            'critical_logs' => (clone $query)->critical()->count(),
            'by_module' => (clone $query)->selectRaw('module, COUNT(*) as count')
                ->groupBy('module')
                ->pluck('count', 'module'),
            'by_action' => (clone $query)->selectRaw('action, COUNT(*) as count')
                ->groupBy('action')
                ->pluck('count', 'action'),
            'by_user' => (clone $query)->with('user:id,name')
                ->selectRaw('user_id, COUNT(*) as count')
                ->groupBy('user_id')
                ->limit(10)
                ->get(),
            'recent_critical' => self::forShopOwner($shopOwnerId)
                ->critical()
                ->recent(7)
                ->with(['user:id,name', 'employee:id,first_name,last_name'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get(),
        ];
    }

    /**
     * Get change history for specific entity
     */
    public static function getEntityHistory($entityType, $entityId)
    {
        return self::forEntity($entityType, $entityId)
            ->with(['user:id,name'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    // ==================== ACCESSORS ====================

    /**
     * Get formatted timestamp
     */
    public function getFormattedDateAttribute(): string
    {
        return $this->created_at->format('M d, Y H:i:s');
    }

    /**
     * Get user name (handle deleted users)
     */
    public function getUserNameAttribute(): string
    {
        return $this->user ? $this->user->name : 'Unknown User';
    }

    /**
     * Get employee name (handle deleted employees)
     */
    public function getEmployeeNameAttribute(): ?string
    {
        return $this->employee 
            ? "{$this->employee->first_name} {$this->employee->last_name}" 
            : null;
    }

    /**
     * Get severity badge color for UI
     */
    public function getSeverityColorAttribute(): string
    {
        return match($this->severity) {
            self::SEVERITY_CRITICAL => 'red',
            self::SEVERITY_WARNING => 'yellow',
            self::SEVERITY_INFO => 'blue',
            default => 'gray',
        };
    }
}
