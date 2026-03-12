<?php

namespace App\Services;

use App\Models\ShopOwner;
use Illuminate\Support\Facades\Auth;

/**
 * Business Access Control Service
 * 
 * Centralized service for enforcing business type and registration type access control.
 * This service ensures that:
 * - Individual accounts cannot access ERP/staff management
 * - Retail-only shops cannot access Repair features
 * - Repair-only shops cannot access Retail features
 * - Both business types get full access
 * 
 * Used by:
 * - Controllers (role creation, feature access)
 * - Middleware (route protection)
 * - Frontend (navigation, UI hiding)
 */
class BusinessAccessControlService
{
    /**
     * Business types
     */
    const BUSINESS_TYPE_RETAIL = 'retail';
    const BUSINESS_TYPE_REPAIR = 'repair';
    const BUSINESS_TYPE_BOTH = 'both';
    const BUSINESS_TYPE_BOTH_ALT = 'both (retail & repair)'; // Alternative format

    /**
     * Registration types
     */
    const REGISTRATION_INDIVIDUAL = 'individual';
    const REGISTRATION_COMPANY = 'company';

    /**
     * Role categories by business type
     */
    const RETAIL_ROLES = ['STAFF', 'Staff', 'MANAGER', 'Manager', 'FINANCE', 'Finance', 'HR', 'CRM'];
    const REPAIR_ROLES = ['REPAIRER', 'Repairer', 'MANAGER', 'Manager', 'FINANCE', 'Finance', 'HR', 'CRM'];
    
    /**
     * Module-to-business-type mapping
     */
    const MODULE_BUSINESS_TYPE_MAP = [
        'products' => [self::BUSINESS_TYPE_RETAIL, self::BUSINESS_TYPE_BOTH],
        'inventory' => [self::BUSINESS_TYPE_RETAIL, self::BUSINESS_TYPE_BOTH],
        'retail-orders' => [self::BUSINESS_TYPE_RETAIL, self::BUSINESS_TYPE_BOTH],
        'services' => [self::BUSINESS_TYPE_REPAIR, self::BUSINESS_TYPE_BOTH],
        'repairs' => [self::BUSINESS_TYPE_REPAIR, self::BUSINESS_TYPE_BOTH],
        'repair-requests' => [self::BUSINESS_TYPE_REPAIR, self::BUSINESS_TYPE_BOTH],
        'upload-services' => [self::BUSINESS_TYPE_REPAIR, self::BUSINESS_TYPE_BOTH],
    ];

    /**
     * Get the authenticated shop owner
     */
    protected function getAuthenticatedShopOwner(): ?ShopOwner
    {
        return Auth::guard('shop_owner')->user();
    }

    /**
     * Normalize business type to handle both formats
     * 
     * @param string $businessType
     * @return string
     */
    public function normalizeBusinessType(string $businessType): string
    {
        if ($businessType === self::BUSINESS_TYPE_BOTH_ALT) {
            return self::BUSINESS_TYPE_BOTH;
        }
        return strtolower($businessType);
    }

    /**
     * Check if shop owner is an individual
     * 
     * @param ShopOwner|null $shopOwner
     * @return bool
     */
    public function isIndividual(?ShopOwner $shopOwner = null): bool
    {
        $shopOwner = $shopOwner ?? $this->getAuthenticatedShopOwner();
        
        if (!$shopOwner) {
            return false;
        }

        return $shopOwner->registration_type === self::REGISTRATION_INDIVIDUAL;
    }

    /**
     * Check if shop owner is a company
     * 
     * @param ShopOwner|null $shopOwner
     * @return bool
     */
    public function isCompany(?ShopOwner $shopOwner = null): bool
    {
        $shopOwner = $shopOwner ?? $this->getAuthenticatedShopOwner();
        
        if (!$shopOwner) {
            return false;
        }

        return $shopOwner->registration_type === self::REGISTRATION_COMPANY;
    }

    /**
     * Check if shop owner can manage staff
     * Only company registrations can manage staff
     * 
     * @param ShopOwner|null $shopOwner
     * @return bool
     */
    public function canManageStaff(?ShopOwner $shopOwner = null): bool
    {
        return $this->isCompany($shopOwner);
    }

    /**
     * Check if shop owner can access ERP modules
     * Only company registrations can access ERP
     * 
     * @param ShopOwner|null $shopOwner
     * @return bool
     */
    public function canAccessERP(?ShopOwner $shopOwner = null): bool
    {
        return $this->isCompany($shopOwner);
    }

    /**
     * Check if shop owner can access product/retail features
     * 
     * @param ShopOwner|null $shopOwner
     * @return bool
     */
    public function canAccessRetail(?ShopOwner $shopOwner = null): bool
    {
        $shopOwner = $shopOwner ?? $this->getAuthenticatedShopOwner();
        
        if (!$shopOwner) {
            return false;
        }

        $businessType = $this->normalizeBusinessType($shopOwner->business_type);
        
        return in_array($businessType, [self::BUSINESS_TYPE_RETAIL, self::BUSINESS_TYPE_BOTH]);
    }

    /**
     * Check if shop owner can access service/repair features
     * 
     * @param ShopOwner|null $shopOwner
     * @return bool
     */
    public function canAccessRepair(?ShopOwner $shopOwner = null): bool
    {
        $shopOwner = $shopOwner ?? $this->getAuthenticatedShopOwner();
        
        if (!$shopOwner) {
            return false;
        }

        $businessType = $this->normalizeBusinessType($shopOwner->business_type);
        
        return in_array($businessType, [self::BUSINESS_TYPE_REPAIR, self::BUSINESS_TYPE_BOTH]);
    }

    /**
     * Check if shop owner can access a specific module
     * 
     * @param string $module Module name (products, services, repairs, etc.)
     * @param ShopOwner|null $shopOwner
     * @return bool
     */
    public function canAccessModule(string $module, ?ShopOwner $shopOwner = null): bool
    {
        $shopOwner = $shopOwner ?? $this->getAuthenticatedShopOwner();
        
        if (!$shopOwner) {
            return false;
        }

        // Individual accounts cannot access any ERP modules
        if ($this->isIndividual($shopOwner)) {
            return false;
        }

        $businessType = $this->normalizeBusinessType($shopOwner->business_type);
        
        // Check if module exists in mapping
        if (!isset(self::MODULE_BUSINESS_TYPE_MAP[$module])) {
            // If module not in map, allow access (common modules like dashboard, profile)
            return true;
        }

        // Check if shop owner's business type is allowed for this module
        return in_array($businessType, self::MODULE_BUSINESS_TYPE_MAP[$module]);
    }

    /**
     * Validate if a role can be created for this shop owner
     * 
     * @param string $role Role name (STAFF, REPAIRER, MANAGER, etc.)
     * @param ShopOwner|null $shopOwner
     * @return array ['allowed' => bool, 'reason' => string]
     */
    public function validateRoleCreation(string $role, ?ShopOwner $shopOwner = null): array
    {
        $shopOwner = $shopOwner ?? $this->getAuthenticatedShopOwner();
        
        if (!$shopOwner) {
            return [
                'allowed' => false,
                'reason' => 'Not authenticated as shop owner'
            ];
        }

        // Individual accounts cannot create ANY roles
        if ($this->isIndividual($shopOwner)) {
            return [
                'allowed' => false,
                'reason' => 'Individual accounts cannot manage staff or create employee roles. Upgrade to a Business account to access staff management features.'
            ];
        }

        $normalizedRole = strtoupper($role);
        $businessType = $this->normalizeBusinessType($shopOwner->business_type);

        // Check if role is allowed for business type
        if ($normalizedRole === 'REPAIRER' || $normalizedRole === 'Repairer') {
            if (!in_array($businessType, [self::BUSINESS_TYPE_REPAIR, self::BUSINESS_TYPE_BOTH])) {
                return [
                    'allowed' => false,
                    'reason' => "Cannot create 'Repairer' role. Your business type is '{$businessType}'. Only 'repair' or 'both' business types can create repair staff."
                ];
            }
        }

        // Staff roles require retail access
        if (in_array($normalizedRole, ['STAFF', 'Staff'])) {
            if (!in_array($businessType, [self::BUSINESS_TYPE_RETAIL, self::BUSINESS_TYPE_BOTH])) {
                return [
                    'allowed' => false,
                    'reason' => "Cannot create 'Staff' role. Your business type is '{$businessType}'. Only 'retail' or 'both' business types can create retail staff."
                ];
            }
        }

        // All good!
        return [
            'allowed' => true,
            'reason' => ''
        ];
    }

    /**
     * Get allowed roles for shop owner
     * 
     * @param ShopOwner|null $shopOwner
     * @return array
     */
    public function getAllowedRoles(?ShopOwner $shopOwner = null): array
    {
        $shopOwner = $shopOwner ?? $this->getAuthenticatedShopOwner();
        
        if (!$shopOwner) {
            return [];
        }

        // Individual accounts get no roles
        if ($this->isIndividual($shopOwner)) {
            return [];
        }

        $businessType = $this->normalizeBusinessType($shopOwner->business_type);
        $allowedRoles = [];

        // Common roles for all business types (company only)
        $commonRoles = ['MANAGER', 'Manager', 'FINANCE', 'Finance', 'HR', 'CRM'];

        if ($businessType === self::BUSINESS_TYPE_RETAIL) {
            $allowedRoles = array_merge($commonRoles, ['STAFF', 'Staff']);
        } elseif ($businessType === self::BUSINESS_TYPE_REPAIR) {
            $allowedRoles = array_merge($commonRoles, ['REPAIRER', 'Repairer']);
        } elseif ($businessType === self::BUSINESS_TYPE_BOTH) {
            $allowedRoles = array_merge($commonRoles, ['STAFF', 'Staff', 'REPAIRER', 'Repairer']);
        }

        return array_unique($allowedRoles);
    }

    /**
     * Get restricted features for shop owner
     * 
     * @param ShopOwner|null $shopOwner
     * @return array
     */
    public function getRestrictedFeatures(?ShopOwner $shopOwner = null): array
    {
        $shopOwner = $shopOwner ?? $this->getAuthenticatedShopOwner();
        
        if (!$shopOwner) {
            return ['All features - not authenticated'];
        }

        $restricted = [];

        // Individual restrictions
        if ($this->isIndividual($shopOwner)) {
            $restricted[] = 'Staff Management';
            $restricted[] = 'Employee Creation';
            $restricted[] = 'Role Management';
            $restricted[] = 'ERP Access';
            $restricted[] = 'Internal Dashboard';
        }

        $businessType = $this->normalizeBusinessType($shopOwner->business_type);

        // Business type restrictions
        if ($businessType === self::BUSINESS_TYPE_RETAIL) {
            $restricted[] = 'Repair Services';
            $restricted[] = 'Service Upload';
            $restricted[] = 'Repair Requests';
            $restricted[] = 'Repairer Role';
        } elseif ($businessType === self::BUSINESS_TYPE_REPAIR) {
            $restricted[] = 'Product Management';
            $restricted[] = 'Inventory';
            $restricted[] = 'Retail Sales';
            $restricted[] = 'Staff Role';
        }

        return $restricted;
    }

    /**
     * Get available features for shop owner
     * 
     * @param ShopOwner|null $shopOwner
     * @return array
     */
    public function getAvailableFeatures(?ShopOwner $shopOwner = null): array
    {
        $shopOwner = $shopOwner ?? $this->getAuthenticatedShopOwner();
        
        if (!$shopOwner) {
            return [];
        }

        $features = [
            'dashboard' => true,
            'orders' => true,
            'customers' => true,
            'shop_profile' => true,
            'refunds' => true,
            'audit_logs' => true,
        ];

        // Company-specific features
        if ($this->isCompany($shopOwner)) {
            $features['staff_management'] = true;
            $features['erp_access'] = true;
            $features['price_approvals'] = true;
        }

        // Business type features
        if ($this->canAccessRetail($shopOwner)) {
            $features['products'] = true;
            $features['inventory'] = true;
            $features['retail_orders'] = true;
        }

        if ($this->canAccessRepair($shopOwner)) {
            $features['services'] = true;
            $features['repairs'] = true;
            $features['repair_requests'] = true;
        }

        return $features;
    }

    /**
     * Throw exception if access is not allowed
     * 
     * @param string $feature Feature name
     * @param ShopOwner|null $shopOwner
     * @throws \Exception
     */
    public function ensureAccess(string $feature, ?ShopOwner $shopOwner = null): void
    {
        $shopOwner = $shopOwner ?? $this->getAuthenticatedShopOwner();
        
        if (!$shopOwner) {
            throw new \Exception('Not authenticated as shop owner');
        }

        $features = $this->getAvailableFeatures($shopOwner);
        
        if (!isset($features[$feature]) || !$features[$feature]) {
            $restricted = $this->getRestrictedFeatures($shopOwner);
            throw new \Exception("Access denied. Available features: " . implode(', ', array_keys(array_filter($features))) . ". Restricted: " . implode(', ', $restricted));
        }
    }
}
