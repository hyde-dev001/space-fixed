<?php

namespace App\Services\Manager;

use App\Models\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

final class ManagerAuthorizationService
{
    public const DASHBOARD_READ = 'dashboard-read';
    public const JOB_ORDERS_READ = 'job-orders-read';
    public const REPAIR_JOBS_READ = 'repair-jobs-read';
    public const INVENTORY_READ = 'inventory-read';
    public const STAFF_WORKLOAD_READ = 'staff-workload-read';
    public const LEAVE_APPROVALS_READ = 'leave-approvals-read';
    public const SUSPENSION_APPROVALS_READ = 'suspension-approvals-read';
    public const REPORTS_READ = 'reports-read';
    public const REPORTS_GENERATE = 'reports-generate';
    public const REPORTS_REVIEW = 'reports-review';
    public const AUDIT_READ = 'audit-read';

    public const ORDER_REASSIGN = 'order-reassign';
    public const REPAIR_REVIEW = 'repair-review';
    public const LEAVE_DECISION = 'leave-decision';
    public const SUSPENSION_DECISION = 'suspension-decision';

    /**
     * The capabilities understood by the Manager middleware.
     *
     * Keeping this list closed prevents a typo in a route middleware argument
     * from accidentally becoming an authorization grant.
     *
     * @var list<string>
     */
    private const CAPABILITIES = [
        self::DASHBOARD_READ,
        self::JOB_ORDERS_READ,
        self::REPAIR_JOBS_READ,
        self::INVENTORY_READ,
        self::STAFF_WORKLOAD_READ,
        self::LEAVE_APPROVALS_READ,
        self::SUSPENSION_APPROVALS_READ,
        self::REPORTS_READ,
        self::REPORTS_GENERATE,
        self::REPORTS_REVIEW,
        self::AUDIT_READ,
        self::ORDER_REASSIGN,
        self::REPAIR_REVIEW,
        self::LEAVE_DECISION,
        self::SUSPENSION_DECISION,
    ];

    /**
     * Return whether the authenticated user has an exact Manager capability
     * and a valid tenant relationship.
     */
    public function allows(User $user, string $capability, ?int $shopOwnerId = null): bool
    {
        if (! in_array($capability, self::CAPABILITIES, true)) {
            return false;
        }

        $authorizedShopOwnerId = $this->shopOwnerId($user);

        if ($authorizedShopOwnerId === null) {
            return false;
        }

        if ($shopOwnerId !== null && $authorizedShopOwnerId !== $shopOwnerId) {
            return false;
        }

        return $this->hasCapability($user, $capability);
    }

    /**
     * Return the shop/tenant associated with the authenticated Manager.
     *
     * The relationship is checked instead of trusting a request query/body
     * value or an orphaned foreign-key column.
     */
    public function shopOwnerId(User $user): ?int
    {
        $shopOwnerId = (int) ($user->getAttribute('shop_owner_id') ?? 0);

        if ($shopOwnerId < 1 || ! $user->shopOwner()->whereKey($shopOwnerId)->exists()) {
            return null;
        }

        return $shopOwnerId;
    }

    /**
     * Return whether the user is a Manager under the application's legacy or
     * Spatie role source. This is the only compatibility mapping location.
     */
    public function isManagerActor(User $user): bool
    {
        if (strtoupper(trim((string) $user->getAttribute('role'))) === 'MANAGER') {
            return true;
        }

        try {
            return $user->hasRole('Manager', 'user');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /**
     * Return whether the user has a Manager capability without checking a
     * target record. Target ownership must still be enforced by the service
     * handling the mutation.
     */
    public function hasCapability(User $user, string $capability): bool
    {
        if (! in_array($capability, self::CAPABILITIES, true)) {
            return false;
        }

        // A deliberately assigned Manager role is the full Manager role. A
        // single page permission, however, never grants another capability.
        if ($this->isManagerActor($user)) {
            return true;
        }

        foreach ($this->permissionsFor($capability) as $permission) {
            try {
                if ($user->hasPermissionTo($permission, 'user')) {
                    return true;
                }
            } catch (PermissionDoesNotExist) {
                // A missing legacy/new permission is simply not a grant.
            }
        }

        return false;
    }

    /**
     * Return whether a user can open any Manager page. Used only by the
     * legacy hierarchy middleware while route-specific middleware is adopted.
     */
    public function canAccessManagerSurface(User $user): bool
    {
        if ($this->shopOwnerId($user) === null) {
            return false;
        }

        foreach ([
            self::DASHBOARD_READ,
            self::JOB_ORDERS_READ,
            self::REPAIR_JOBS_READ,
            self::INVENTORY_READ,
            self::STAFF_WORKLOAD_READ,
            self::LEAVE_APPROVALS_READ,
            self::SUSPENSION_APPROVALS_READ,
            self::REPORTS_READ,
            self::AUDIT_READ,
        ] as $capability) {
            if ($this->hasCapability($user, $capability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function permissionsFor(string $capability): array
    {
        return match ($capability) {
            self::DASHBOARD_READ => ['access-manager-dashboard'],
            self::JOB_ORDERS_READ => ['access-manager-job-orders'],
            self::REPAIR_JOBS_READ => [
                'access-manager-repair-jobs',
                // Compatibility read alias for the former rejection page.
                'access-repair-reject-review',
            ],
            self::INVENTORY_READ => ['access-inventory-overview'],
            self::STAFF_WORKLOAD_READ => ['access-manager-staff-workload'],
            self::LEAVE_APPROVALS_READ => [
                'access-manager-leave-approvals',
                // Compatibility read alias for the legacy leave page.
                'access-leave-approvals',
            ],
            self::SUSPENSION_APPROVALS_READ => [
                'access-manager-suspension-approvals',
                // Compatibility read alias for the former suspension page.
                'access-suspend-account',
            ],
            self::REPORTS_READ => ['access-manager-reports'],
            self::REPORTS_GENERATE => ['generate-manager-reports'],
            self::REPORTS_REVIEW => ['review-manager-reports'],
            self::AUDIT_READ => ['access-audit-logs'],
            self::ORDER_REASSIGN => ['reassign-manager-job-orders'],
            self::REPAIR_REVIEW => ['review-manager-repair-jobs'],
            self::LEAVE_DECISION => ['decide-manager-leave-approvals'],
            self::SUSPENSION_DECISION => ['decide-manager-suspension-approvals'],
            default => [],
        };
    }
}
