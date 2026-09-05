<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ShopOwner;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureArticleAudienceAccess
{
    /**
     * @var array<string, array{roles: array<int, string>, permissions: array<int, string>}>
     */
    private const EMPLOYEE_ACCESS = [
        'manager' => [
            'roles' => ['MANAGER'],
            'permissions' => [
                'access-manager-dashboard',
                'access-manager-job-orders',
                'access-manager-repair-jobs',
                'access-manager-staff-workload',
                'access-manager-leave-approvals',
                'access-manager-suspension-approvals',
                'access-manager-termination-approvals',
                'access-manager-rehire-approvals',
                'access-manager-reports',
                'access-manager-audit-logs',
                'access-inventory-overview',
            ],
        ],
        'finance' => [
            'roles' => ['FINANCE', 'FINANCE STAFF', 'FINANCE MANAGER'],
            'permissions' => [
                'access-finance-dashboard',
                'access-finance-expenses',
                'access-finance-invoices',
                'access-repair-price-approval',
                'access-shoe-price-approval',
                'access-approval-workflow',
                'access-purchase-request-approval',
                'access-payslip-approval',
                'access-refund-approval',
            ],
        ],
        'hr' => [
            'roles' => ['HR'],
            'permissions' => [
                'access-hr-dashboard',
                'access-employee-directory',
                'access-user-access-control',
                'access-attendance-records',
                'access-leave-approvals',
                'access-overtime-approvals',
                'access-payslip-generation',
                'access-view-payslip',
                'access-salary-changes',
                'access-suspend-accounts',
            ],
        ],
        'crm' => [
            'roles' => ['CRM'],
            'permissions' => [
                'access-crm-dashboard',
                'access-crm-customers',
                'access-customer-support',
                'access-customer-reviews',
                'access-crm-messages',
            ],
        ],
        'cashier' => [
            'roles' => ['CASHIER'],
            'permissions' => ['access-unified-pos'],
        ],
        'repairer' => [
            'roles' => ['REPAIRER'],
            'permissions' => [
                'access-repairer-dashboard',
                'access-repair-job-orders',
                'access-upload-service',
                'access-pricing-services',
                'access-repair-stocks',
                'access-repairer-support',
                'access-unified-pos',
            ],
        ],
        'inventory' => [
            'roles' => ['INVENTORY', 'INVENTORY MANAGER'],
            'permissions' => [
                'view-inventory',
                'access-inventory-dashboard',
                'access-product-inventory',
                'access-stock-movement',
                'access-upload-inventory',
                'access-inventory-overview',
            ],
        ],
        'procurement' => [
            'roles' => ['PROCUREMENT', 'PROCUREMENT MANAGER'],
            'permissions' => [
                'view-procurement',
                'access-procurement-dashboard',
                'access-purchase-requests',
                'access-purchase-orders',
                'access-stock-request-approval',
                'access-suppliers-management',
            ],
        ],
        'logistics-dispatcher' => [
            'roles' => ['LOGISTICS DISPATCHER'],
            'permissions' => [
                'view-logistics-dashboard',
                'view-logistics-shipments',
                'view-logistics-deliveries',
                'view-logistics-batches',
                'manage-logistics-settings',
            ],
        ],
    ];

    /**
     * @param  Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $audience): Response
    {
        if ($audience === 'shop-owner') {
            return $this->allowShopOwner($request, $next);
        }

        $user = Auth::guard('user')->user();

        if (! $user instanceof User || ! isset(self::EMPLOYEE_ACCESS[$audience])) {
            abort(403);
        }

        if ($this->hasExcludedRole($user)) {
            abort(403);
        }

        $rules = self::EMPLOYEE_ACCESS[$audience];
        $roleMatches = array_intersect($rules['roles'], $this->roleNames($user)) !== [];
        $permissionMatches = $user->hasAnyPermission($rules['permissions']);

        if (! $roleMatches && ! $permissionMatches) {
            abort(403);
        }

        if ($audience === 'repairer' && ! $this->repairCapable($user)) {
            abort(403);
        }

        return $next($request);
    }

    /**
     * @param  Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    private function allowShopOwner(Request $request, Closure $next): Response
    {
        $owner = Auth::guard('shop_owner')->user();

        if (! $owner instanceof ShopOwner) {
            abort(403);
        }

        $status = $owner->getRawOriginal('status') ?? $owner->status;
        $status = $status instanceof \BackedEnum ? $status->value : (string) $status;
        $registrationType = strtolower(trim((string) $owner->registration_type));
        $businessType = strtolower(trim((string) $owner->business_type));

        if (strtolower(trim($status)) !== 'approved'
            || ! in_array($registrationType, ['company', 'individual'], true)
            || ! in_array($businessType, ['retail', 'repair', 'both'], true)) {
            abort(403);
        }

        return $next($request);
    }

    /**
     * @return array<int, string>
     */
    private function roleNames(User $user): array
    {
        $names = array_merge(
            [(string) $user->role],
            $user->getRoleNames()->all(),
        );

        return array_values(array_unique(array_filter(array_map($this->normalizeRole(...), $names))));
    }

    private function normalizeRole(mixed $role): string
    {
        return strtoupper(str_replace(['_', '-'], ' ', trim((string) $role)));
    }

    private function hasExcludedRole(User $user): bool
    {
        return array_intersect($this->roleNames($user), [
            'LOGISTICS RIDER',
            'SUPER ADMIN',
        ]) !== [];
    }

    private function repairCapable(User $user): bool
    {
        $owner = $user->shopOwner;
        $businessType = strtolower(trim((string) $owner?->business_type));

        return in_array($businessType, ['repair', 'both'], true);
    }
}
