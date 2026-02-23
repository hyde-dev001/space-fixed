/**
 * Shop Owner Navigation Configuration
 * 
 * Generates dynamic navigation based on:
 * - Business Type (retail, repair, both)
 * - Registration Type (individual, company)
 */

import { NavigationItem, ShopOwnerAccess } from '@/types/shopOwner';
import {
    canAccessProducts,
    canAccessServices,
    canAccessCalendar,
    canAccessStaffManagement,
    canAccessPriceApprovals,
} from '@/utils/shopOwnerAccess';

/**
 * Get navigation items filtered by shop owner access level
 * 
 * @param access - Shop owner business and registration type
 * @returns Array of navigation items to display
 */
export const getShopOwnerNavigation = (access: ShopOwnerAccess): NavigationItem[] => {
    const allItems: NavigationItem[] = [
        // Dashboard - Always visible
        {
            label: 'Dashboard',
            path: '/shop-owner/dashboard',
            icon: '📊',
            visible: true,
        },

        // Products - Retail or Both only
        {
            label: 'Products',
            path: '/shop-owner/products',
            icon: '📦',
            visible: canAccessProducts(access),
            subItems: [
                { label: 'All Products', path: '/shop-owner/products' },
                { label: 'Add Product', path: '/shop-owner/products/add' },
                { label: 'Categories', path: '/shop-owner/products/categories' },
            ],
        },

        // Services - Repair or Both only
        {
            label: 'Services',
            path: '/shop-owner/services',
            icon: '🔧',
            visible: canAccessServices(access),
            subItems: [
                { label: 'All Services', path: '/shop-owner/services' },
                { label: 'Add Service', path: '/shop-owner/services/add' },
                { label: 'Repair Requests', path: '/shop-owner/repair-requests' },
                { label: 'High Value Repairs', path: '/shop-owner/high-value-repairs' },
            ],
        },

        // Orders - Always visible
        {
            label: 'Orders',
            path: '/shop-owner/orders',
            icon: '📋',
            visible: true,
        },

        // Customers - Always visible
        {
            label: 'Customers',
            path: '/shop-owner/customers',
            icon: '👥',
            visible: true,
        },

        // Calendar - Repair or Both only
        {
            label: 'Calendar',
            path: '/shop-owner/calendar',
            icon: '📅',
            visible: canAccessCalendar(access),
        },

        // Shop Profile - Always visible
        {
            label: 'Shop Profile',
            path: '/shop-owner/shop-profile',
            icon: '🏬',
            visible: true,
        },

        // Price Approvals - Company only
        {
            label: 'Price Approvals',
            path: '/shop-owner/price-approvals',
            icon: '💰',
            visible: canAccessPriceApprovals(access),
            badge: 'Company Only',
        },

        // Refunds - Always visible
        {
            label: 'Refunds',
            path: '/shop-owner/refunds',
            icon: '💸',
            visible: true,
        },

        // Staff Management - Company only
        {
            label: 'Staff Management',
            path: '/shop-owner/staff',
            icon: '👔',
            visible: canAccessStaffManagement(access),
            className: 'border-t mt-2 pt-2',
            badge: 'Company Only',
            subItems: [
                { label: 'All Staff', path: '/shop-owner/staff' },
                { label: 'Add Staff', path: '/shop-owner/staff/add' },
            ],
        },

        // Audit Logs - Always visible
        {
            label: 'Audit Logs',
            path: '/shop-owner/audit-logs',
            icon: '📜',
            visible: true,
        },

        // Upgrade to Company - Individual only
        {
            label: 'Upgrade to Company',
            path: '/shop-owner/upgrade',
            icon: '⬆️',
            visible: access.registrationType === 'individual',
            className: 'bg-blue-500 text-white rounded-lg mt-2 hover:bg-blue-600',
        },
    ];

    // Filter out items where visible is false
    return allItems.filter(item => item.visible !== false);
};

/**
 * Get quick action buttons for dashboard
 * 
 * @param access - Shop owner business and registration type
 * @returns Array of quick action items
 */
export const getQuickActions = (access: ShopOwnerAccess) => {
    const actions = [];

    // Add Product action (Retail or Both)
    if (canAccessProducts(access)) {
        actions.push({
            label: 'Add Product',
            icon: '➕',
            path: '/shop-owner/products/add',
            color: 'bg-blue-500',
        });
    }

    // Add Service action (Repair or Both)
    if (canAccessServices(access)) {
        actions.push({
            label: 'Add Service',
            icon: '➕',
            path: '/shop-owner/services/add',
            color: 'bg-green-500',
        });
    }

    // View Orders (Always)
    actions.push({
        label: 'View Orders',
        icon: '📋',
        path: '/shop-owner/orders',
        color: 'bg-purple-500',
    });

    // Customer Messages (Always)
    actions.push({
        label: 'Messages',
        icon: '💬',
        path: '/shop-owner/customers/messages',
        color: 'bg-orange-500',
    });

    return actions;
};

/**
 * Get breadcrumb items for a given path
 * 
 * @param path - Current route path
 * @returns Array of breadcrumb items
 */
export const getBreadcrumbs = (path: string) => {
    const breadcrumbs = [
        { label: 'Dashboard', path: '/shop-owner/dashboard' }
    ];

    if (path === '/shop-owner/dashboard') {
        return breadcrumbs;
    }

    // Simple path-based breadcrumb generation
    const segments = path.split('/').filter(Boolean);
    
    if (segments.includes('products')) {
        breadcrumbs.push({ label: 'Products', path: '/shop-owner/products' });
    } else if (segments.includes('services')) {
        breadcrumbs.push({ label: 'Services', path: '/shop-owner/services' });
    } else if (segments.includes('orders')) {
        breadcrumbs.push({ label: 'Orders', path: '/shop-owner/orders' });
    } else if (segments.includes('customers')) {
        breadcrumbs.push({ label: 'Customers', path: '/shop-owner/customers' });
    } else if (segments.includes('staff')) {
        breadcrumbs.push({ label: 'Staff Management', path: '/shop-owner/staff' });
    }

    return breadcrumbs;
};
