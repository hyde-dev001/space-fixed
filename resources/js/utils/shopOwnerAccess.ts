/**
 * Shop Owner Access Control Utilities
 * 
 * Helper functions to determine feature access based on:
 * - Business Type (retail, repair, both)
 * - Registration Type (individual, company)
 */

import { ShopOwnerAccess, BusinessType } from '@/types/shopOwner';

/**
 * Normalize business type to handle both formats
 */
const normalizeBusinessType = (businessType: BusinessType): 'retail' | 'repair' | 'both' => {
    if (businessType === 'both (retail & repair)') {
        return 'both';
    }
    return businessType as 'retail' | 'repair' | 'both';
};

/**
 * Check if shop owner can access product management features
 * Available to: Retail and Both business types
 */
export const canAccessProducts = (access: ShopOwnerAccess): boolean => {
    const normalized = normalizeBusinessType(access.businessType);
    return normalized === 'retail' || normalized === 'both';
};

/**
 * Check if shop owner can access service/repair management features
 * Available to: Repair and Both business types
 */
export const canAccessServices = (access: ShopOwnerAccess): boolean => {
    const normalized = normalizeBusinessType(access.businessType);
    return normalized === 'repair' || normalized === 'both';
};

/**
 * Check if shop owner can access calendar/scheduling features
 * Available to: Repair and Both business types (for appointment booking)
 */
export const canAccessCalendar = (access: ShopOwnerAccess): boolean => {
    const normalized = normalizeBusinessType(access.businessType);
    return normalized === 'repair' || normalized === 'both';
};

/**
 * Check if shop owner can access staff management features
 * Available to: Company registration type only
 */
export const canAccessStaffManagement = (access: ShopOwnerAccess): boolean => {
    return access.registrationType === 'company';
};

/**
 * Check if shop owner can access price approval features
 * Available to: Company registration type only (for approving staff price changes)
 */
export const canAccessPriceApprovals = (access: ShopOwnerAccess): boolean => {
    return access.registrationType === 'company';
};

/**
 * Check if shop owner can access multi-location features
 * Available to: Company registration type only
 */
export const canAccessMultipleLocations = (access: ShopOwnerAccess): boolean => {
    return access.registrationType === 'company';
};

/**
 * Check if shop owner can add more locations
 * Individual: Limited to 1 location
 * Company: Unlimited locations
 */
export const canAddMoreLocations = (access: ShopOwnerAccess, currentLocationCount: number = 0): boolean => {
    if (access.registrationType === 'company') {
        return true; // Unlimited
    }
    // Individual can only have 1 location
    return currentLocationCount < 1;
};

/**
 * Get maximum number of locations allowed
 * Individual: 1
 * Company: null (unlimited)
 */
export const getMaxLocations = (access: ShopOwnerAccess): number | null => {
    return access.registrationType === 'individual' ? 1 : null;
};

/**
 * Get features available to the shop owner
 * Returns an object describing what features are accessible
 */
export const getAvailableFeatures = (access: ShopOwnerAccess) => {
    return {
        products: canAccessProducts(access),
        services: canAccessServices(access),
        calendar: canAccessCalendar(access),
        staffManagement: canAccessStaffManagement(access),
        priceApprovals: canAccessPriceApprovals(access),
        multipleLocations: canAccessMultipleLocations(access),
        orders: true, // Always available
        customers: true, // Always available
        shopProfile: true, // Always available
        refunds: true, // Always available
        auditLogs: true, // Always available
    };
};

/**
 * Get restricted features (features the shop owner CANNOT access)
 */
export const getRestrictedFeatures = (access: ShopOwnerAccess): string[] => {
    const restricted: string[] = [];
    
    if (!canAccessProducts(access)) {
        restricted.push('Product Management');
    }
    if (!canAccessServices(access)) {
        restricted.push('Service Management', 'Repair Requests', 'Calendar');
    }
    if (!canAccessStaffManagement(access)) {
        restricted.push('Staff Management', 'Employee Accounts');
    }
    if (!canAccessPriceApprovals(access)) {
        restricted.push('Price Approvals');
    }
    if (!canAccessMultipleLocations(access)) {
        restricted.push('Multiple Locations');
    }
    
    return restricted;
};

/**
 * Check if upgrade to Company is recommended
 */
export const shouldShowUpgradePrompt = (access: ShopOwnerAccess): boolean => {
    return access.registrationType === 'individual';
};
