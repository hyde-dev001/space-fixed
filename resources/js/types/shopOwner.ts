/**
 * Shop Owner Types
 * 
 * Type definitions for shop owner data and access control
 */

export type BusinessType = 'retail' | 'repair' | 'both' | 'both (retail & repair)';
export type RegistrationType = 'individual' | 'company';
export type ShopOwnerStatusType = 'pending' | 'approved' | 'rejected' | 'suspended';

export type ShopModuleKey =
    | 'retail_operations'
    | 'repair_operations'
    | 'hr_employees'
    | 'finance'
    | 'crm'
    | 'inventory'
    | 'procurement'
    | 'logistics';

export interface ShopModuleState {
    eligible: boolean;
    enabled: boolean;
    accessible: boolean;
    code: string | null;
    reason: string | null;
}

export type ShopModuleStates = Record<ShopModuleKey, ShopModuleState>;

/**
 * Shop Owner model interface
 * Matches the data shared from backend via Inertia middleware
 */
export interface ShopOwner {
    id: number;
    first_name: string;
    last_name: string;
    name: string;
    business_name: string;
    email: string;
    business_type: BusinessType;
    registration_type: RegistrationType;
    status: ShopOwnerStatusType;
    is_individual: boolean;
    is_company: boolean;
    can_manage_staff: boolean;
    max_locations: number | null; // null means unlimited
}

/**
 * Access control object
 * Used to determine feature availability
 */
export interface ShopOwnerAccess {
    businessType: BusinessType;
    registrationType: RegistrationType;
}

/**
 * Navigation item structure
 */
export interface NavigationItem {
    label: string;
    path: string;
    icon: string;
    visible?: boolean;
    className?: string;
    badge?: string;
    subItems?: NavigationSubItem[];
}

/**
 * Navigation sub-item structure
 */
export interface NavigationSubItem {
    label: string;
    path: string;
}

/**
 * Page props that include shop owner auth data
 */
export interface ShopOwnerPageProps {
    auth: {
        shop_owner: ShopOwner | null;
        shopModules?: ShopModuleStates;
        user?: any;
        super_admin?: any;
    };
}
