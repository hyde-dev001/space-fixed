<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShopOwner;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Server-side entitlement checks for article slugs.
 *
 * The React catalogs remain responsible for copy and presentation. This small
 * registry is deliberately limited to access metadata so a direct URL cannot
 * bypass the same role/configuration boundary as the article hub.
 */
final class ArticleAccessService
{
    public function __construct(
        private readonly BusinessAccessControlService $businessAccess,
    ) {}

    public function allows(string $audience, string $slug): bool
    {
        $rule = $this->rules()[$audience][$slug] ?? null;

        if (! is_array($rule)) {
            return false;
        }

        if ($audience === 'shop-owner') {
            return $this->allowsOwner($rule);
        }

        $user = Auth::guard('user')->user();

        if (! $user instanceof User || ! $user->hasAnyPermission($rule['permissions'] ?? [])) {
            return false;
        }

        $businessType = $this->businessAccess->normalizeBusinessType(
            (string) ($user->shopOwner?->business_type ?? ''),
        );

        return $this->matchesList($businessType, $rule['business_types'] ?? null);
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function allowsOwner(array $rule): bool
    {
        $owner = Auth::guard('shop_owner')->user();

        if (! $owner instanceof ShopOwner) {
            return false;
        }

        $status = $owner->getRawOriginal('status') ?? $owner->status;
        $status = $status instanceof \BackedEnum ? $status->value : (string) $status;
        if (strtolower(trim($status)) !== 'approved') {
            return false;
        }

        $registrationType = strtolower(trim((string) $owner->registration_type));
        $businessType = $this->businessAccess->normalizeBusinessType(
            (string) $owner->business_type,
        );

        return $this->matchesList($registrationType, $rule['registration_types'] ?? null)
            && $this->matchesList($businessType, $rule['business_types'] ?? null);
    }

    /**
     * @param list<string>|null $allowed
     */
    private function matchesList(string $value, ?array $allowed): bool
    {
        return ! is_array($allowed) || $allowed === [] || in_array($value, $allowed, true);
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function rules(): array
    {
        $employee = static fn (array $permissions, ?array $businessTypes = null): array => [
            'permissions' => $permissions,
            'business_types' => $businessTypes,
        ];
        $owner = static fn (?array $registrationTypes = null, ?array $businessTypes = null): array => [
            'permissions' => [],
            'registration_types' => $registrationTypes,
            'business_types' => $businessTypes,
        ];

        $staffPermissions = [
            'access-staff-dashboard',
            'access-staff-job-orders',
            'access-product-management',
            'access-product-upload-staff',
            'access-shoe-pricing',
            'access-staff-time-in',
            'access-staff-leave',
            'access-color-variant-manager',
            'access-staff-customers',
        ];
        $retail = ['retail', 'both'];
        $repair = ['repair', 'both'];

        return [
            'staff' => [
                'staff-workspace-permissions' => $employee($staffPermissions, $retail),
                'using-staff-dashboard' => $employee(['access-staff-dashboard'], $retail),
                'viewing-managing-notifications' => $employee(['access-notification-center'], $retail),
                'profile-and-password' => $employee(['access-profile'], $retail),
                'daily-attendance-workflow' => $employee(['access-staff-time-in'], $retail),
                'early-arrival-lateness-departure' => $employee(['access-staff-time-in'], $retail),
                'requesting-leave' => $employee(['access-staff-leave'], $retail),
                'requesting-overtime' => $employee(['access-staff-time-in'], $retail),
                'viewing-printing-payslips' => $employee(['access-staff-time-in'], $retail),
                'understanding-retail-job-orders' => $employee(['access-staff-job-orders'], $retail),
                'processing-pending-retail-orders' => $employee(['access-staff-job-orders'], $retail),
                'shipping-or-activating-pickup' => $employee(['access-staff-job-orders'], $retail),
                'understanding-cancelled-orders' => $employee(['access-staff-job-orders'], $retail),
                'review-customer-refund-request' => $employee(['access-staff-job-orders'], $retail),
                'after-staff-refund-decision' => $employee(['access-staff-job-orders', 'access-refund-approval'], $retail),
                'arranging-a-return' => $employee(['access-staff-job-orders'], $retail),
                'confirming-inspecting-returns' => $employee(['access-staff-job-orders'], $retail),
                'refund-return-statuses' => $employee(['access-staff-job-orders'], $retail),
                'customer-delivery-receipt-disputes' => $employee(['access-staff-job-orders', 'view-proof-of-delivery'], $retail),
                'understanding-product-management' => $employee(['access-product-management'], $retail),
                'creating-product-from-inventory' => $employee(['access-product-upload-staff'], $retail),
                'configuring-product-details' => $employee(['access-product-upload-staff'], $retail),
                'configuring-colors-sizes-quantities' => $employee(['access-product-upload-staff'], $retail),
                'uploading-product-images' => $employee(['access-product-upload-staff'], $retail),
                'using-shoe-spin-viewer' => $employee(['access-product-upload-staff'], $retail),
                'editing-deleting-product' => $employee(['access-product-management', 'access-product-upload-staff'], $retail),
                'fixing-product-creation-errors' => $employee(['access-product-management', 'access-product-upload-staff'], $retail),
                'requesting-shoe-price-change' => $employee(['access-shoe-pricing'], $retail),
                'price-request-outcomes' => $employee(['access-shoe-pricing'], $retail),
                'cancelling-correcting-resubmitting-price-request' => $employee(['access-shoe-pricing'], $retail),
                'viewing-customer-information' => $employee(['access-staff-customers'], $retail),
                'monitoring-product-inventory' => $employee(['access-product-management', 'access-product-upload-staff'], $retail),
            ],
            'manager' => [
                'manager-dashboard' => $employee(['access-manager-dashboard']),
                'manager-retail-job-orders' => $employee(['access-manager-job-orders'], $retail),
                'manager-repair-jobs' => $employee(['access-manager-repair-jobs'], $repair),
                'manager-staff-workload' => $employee(['access-manager-staff-workload']),
                'manager-leave-approvals' => $employee(['access-manager-leave-approvals']),
                'manager-employee-lifecycle-approvals' => $employee([
                    'access-manager-suspension-approvals',
                    'access-manager-termination-approvals',
                    'access-manager-rehire-approvals',
                ]),
                'manager-inventory-overview' => $employee(['access-inventory-overview']),
                'manager-shoe-pricing' => $employee(['access-shoe-pricing'], $retail),
                'manager-reports' => $employee(['access-manager-reports']),
                'manager-audit-logs' => $employee(['access-audit-logs']),
            ],
            'finance' => [
                'finance-dashboard' => $employee(['access-finance-dashboard']),
                'finance-invoices' => $employee(['access-finance-invoices']),
                'finance-create-invoice' => $employee(['access-finance-invoices']),
                'finance-expenses' => $employee(['access-finance-expenses']),
                'finance-approvals' => $employee([
                    'access-approval-workflow',
                    'access-purchase-request-approval',
                    'access-refund-approval',
                    'access-payslip-approval',
                    'access-repair-price-approval',
                    'access-shoe-price-approval',
                ]),
            ],
            'hr' => [
                'hr-dashboard' => $employee(['access-hr-dashboard']),
                'hr-employee-directory' => $employee(['access-employee-directory']),
                'hr-user-access-control' => $employee(['manage-employee-permissions']),
                'hr-attendance' => $employee(['access-attendance-records']),
                'hr-leave-approvals' => $employee(['access-leave-approvals']),
                'hr-overtime-approvals' => $employee(['access-overtime-approvals']),
                'hr-payroll' => $employee(['access-payslip-generation', 'access-view-payslip']),
                'hr-salary-changes' => $employee(['manage-salary-changes']),
                'hr-suspend-accounts' => $employee(['request-employee-suspensions']),
            ],
            'crm' => [
                'crm-dashboard' => $employee(['access-crm-dashboard']),
                'crm-customers' => $employee(['access-crm-customers']),
                'crm-leads-opportunities' => $employee(['access-crm-dashboard']),
                'crm-support' => $employee(['access-customer-support']),
                'crm-reviews' => $employee(['access-customer-reviews']),
            ],
            'cashier' => [
                'cashier-dashboard' => $employee(['access-unified-pos']),
                'cashier-point-of-sale' => $employee(['access-unified-pos']),
                'cashier-refunds' => $employee(['access-unified-pos']),
            ],
            'repairer' => [
                'repairer-dashboard' => $employee(['access-repairer-dashboard'], $repair),
                'repairer-job-orders' => $employee(['access-repair-job-orders'], $repair),
                'repairer-warranty' => $employee(['access-repair-job-orders'], $repair),
                'repairer-services' => $employee(['access-upload-service', 'access-pricing-services'], $repair),
                'repairer-materials' => $employee(['access-repair-stocks'], $repair),
                'repairer-support' => $employee(['access-repairer-support'], $repair),
            ],
            'inventory' => [
                'inventory-overview' => $employee(['access-inventory-dashboard']),
                'inventory-dashboard' => $employee(['access-inventory-dashboard']),
                'inventory-upload-stocks' => $employee(['access-upload-inventory']),
                'inventory-product-inventory' => $employee(['access-product-inventory']),
                'inventory-stock-movement' => $employee(['access-stock-movement']),
                'inventory-stock-request' => $employee(['access-inventory-dashboard']),
                'inventory-material-approval' => $employee(['access-inventory-dashboard'], $repair),
                'inventory-supplier-monitoring' => $employee(['access-supplier-order-monitoring']),
            ],
            'procurement' => [
                'procurement-dashboard' => $employee(['access-procurement-dashboard', 'view-procurement']),
                'procurement-purchase-request' => $employee(['access-purchase-requests', 'view-procurement']),
                'procurement-purchase-orders' => $employee(['access-purchase-orders', 'view-procurement']),
                'procurement-stock-approval' => $employee(['access-stock-request-approval', 'view-procurement']),
                'procurement-suppliers' => $employee(['access-suppliers-management', 'view-procurement']),
            ],
            'logistics-dispatcher' => [
                'logistics-dispatcher-dashboard' => $employee(['access-logistics-dashboard']),
                'logistics-shipments' => $employee(['assign-logistics-deliveries']),
                'logistics-batches' => $employee(['manage-logistics-batches']),
                'logistics-settings' => $employee(['configure-logistics-settings']),
            ],
            'shop-owner' => [
                'shop-owner-home' => $owner(),
                'shop-owner-retail' => $owner(null, $retail),
                'shop-owner-repair' => $owner(['company'], $repair),
                'shop-owner-pos' => $owner(['individual'], ['retail', 'repair', 'both']),
                'shop-owner-customers' => $owner(),
                'shop-owner-finance' => $owner(['company']),
                'shop-owner-team' => $owner(['company']),
                'shop-owner-inventory' => $owner(['company']),
                'shop-owner-procurement' => $owner(['company']),
                'shop-owner-logistics' => $owner(['company']),
                'shop-owner-repair-individual' => $owner(['individual'], $repair),
                'shop-owner-reports' => $owner(['company']),
            ],
        ];
    }
}
