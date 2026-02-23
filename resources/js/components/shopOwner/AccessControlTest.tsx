/**
 * Test Component for Shop Owner Access Control
 * 
 * This demonstrates how to use the access control utilities
 * You can temporarily add this to a page to test the functionality
 */

import { usePage } from '@inertiajs/react';
import { ShopOwnerPageProps } from '@/types/shopOwner';
import { 
    canAccessProducts, 
    canAccessServices, 
    canAccessStaffManagement,
    canAccessPriceApprovals,
    getAvailableFeatures,
    getRestrictedFeatures 
} from '@/utils/shopOwnerAccess';
import { getShopOwnerNavigation, getQuickActions } from '@/config/shopOwnerNavigation';

export default function AccessControlTest() {
    const { auth } = usePage<ShopOwnerPageProps>().props;
    const shopOwner = auth.shop_owner;

    if (!shopOwner) {
        return <div>Not logged in as shop owner</div>;
    }

    const access = {
        businessType: shopOwner.business_type,
        registrationType: shopOwner.registration_type,
    };

    const navigation = getShopOwnerNavigation(access);
    const quickActions = getQuickActions(access);
    const availableFeatures = getAvailableFeatures(access);
    const restrictedFeatures = getRestrictedFeatures(access);

    return (
        <div className="p-8 max-w-4xl mx-auto">
            <h1 className="text-3xl font-bold mb-8">Access Control Test</h1>

            {/* Shop Owner Info */}
            <div className="bg-white rounded-lg shadow p-6 mb-6">
                <h2 className="text-xl font-semibold mb-4">Shop Owner Information</h2>
                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <p className="text-sm text-gray-600">Business Name</p>
                        <p className="font-semibold">{shopOwner.business_name}</p>
                    </div>
                    <div>
                        <p className="text-sm text-gray-600">Email</p>
                        <p className="font-semibold">{shopOwner.email}</p>
                    </div>
                    <div>
                        <p className="text-sm text-gray-600">Business Type</p>
                        <p className="font-semibold">{shopOwner.business_type}</p>
                    </div>
                    <div>
                        <p className="text-sm text-gray-600">Registration Type</p>
                        <p className="font-semibold">{shopOwner.registration_type}</p>
                    </div>
                    <div>
                        <p className="text-sm text-gray-600">Max Locations</p>
                        <p className="font-semibold">{shopOwner.max_locations ?? 'Unlimited'}</p>
                    </div>
                    <div>
                        <p className="text-sm text-gray-600">Can Manage Staff</p>
                        <p className="font-semibold">{shopOwner.can_manage_staff ? 'Yes' : 'No'}</p>
                    </div>
                </div>
            </div>

            {/* Access Check Results */}
            <div className="bg-white rounded-lg shadow p-6 mb-6">
                <h2 className="text-xl font-semibold mb-4">Access Check Results</h2>
                <div className="space-y-2">
                    <div className="flex items-center gap-2">
                        <span className={`w-4 h-4 rounded-full ${canAccessProducts(access) ? 'bg-green-500' : 'bg-red-500'}`}></span>
                        <span>Can Access Products: {canAccessProducts(access) ? 'Yes' : 'No'}</span>
                    </div>
                    <div className="flex items-center gap-2">
                        <span className={`w-4 h-4 rounded-full ${canAccessServices(access) ? 'bg-green-500' : 'bg-red-500'}`}></span>
                        <span>Can Access Services: {canAccessServices(access) ? 'Yes' : 'No'}</span>
                    </div>
                    <div className="flex items-center gap-2">
                        <span className={`w-4 h-4 rounded-full ${canAccessStaffManagement(access) ? 'bg-green-500' : 'bg-red-500'}`}></span>
                        <span>Can Manage Staff: {canAccessStaffManagement(access) ? 'Yes' : 'No'}</span>
                    </div>
                    <div className="flex items-center gap-2">
                        <span className={`w-4 h-4 rounded-full ${canAccessPriceApprovals(access) ? 'bg-green-500' : 'bg-red-500'}`}></span>
                        <span>Can Access Price Approvals: {canAccessPriceApprovals(access) ? 'Yes' : 'No'}</span>
                    </div>
                </div>
            </div>

            {/* Available Features */}
            <div className="bg-white rounded-lg shadow p-6 mb-6">
                <h2 className="text-xl font-semibold mb-4">Available Features</h2>
                <div className="flex flex-wrap gap-2">
                    {Object.entries(availableFeatures).map(([feature, available]) => (
                        available && (
                            <span key={feature} className="px-3 py-1 bg-green-100 text-green-800 rounded-full text-sm">
                                ✓ {feature}
                            </span>
                        )
                    ))}
                </div>
            </div>

            {/* Restricted Features */}
            {restrictedFeatures.length > 0 && (
                <div className="bg-white rounded-lg shadow p-6 mb-6">
                    <h2 className="text-xl font-semibold mb-4">Restricted Features</h2>
                    <div className="flex flex-wrap gap-2">
                        {restrictedFeatures.map((feature) => (
                            <span key={feature} className="px-3 py-1 bg-red-100 text-red-800 rounded-full text-sm">
                                ✗ {feature}
                            </span>
                        ))}
                    </div>
                </div>
            )}

            {/* Navigation Items */}
            <div className="bg-white rounded-lg shadow p-6 mb-6">
                <h2 className="text-xl font-semibold mb-4">Navigation Items ({navigation.length})</h2>
                <div className="space-y-2">
                    {navigation.map((item, index) => (
                        <div key={index} className="border-l-4 border-blue-500 pl-4 py-2">
                            <div className="flex items-center gap-2">
                                <span>{item.icon}</span>
                                <span className="font-semibold">{item.label}</span>
                                {item.badge && (
                                    <span className="px-2 py-1 bg-blue-100 text-blue-800 rounded text-xs">
                                        {item.badge}
                                    </span>
                                )}
                            </div>
                            {item.subItems && (
                                <div className="ml-8 mt-2 space-y-1">
                                    {item.subItems.map((subItem, subIndex) => (
                                        <div key={subIndex} className="text-sm text-gray-600">
                                            • {subItem.label}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </div>

            {/* Quick Actions */}
            <div className="bg-white rounded-lg shadow p-6">
                <h2 className="text-xl font-semibold mb-4">Quick Actions ({quickActions.length})</h2>
                <div className="grid grid-cols-2 gap-4">
                    {quickActions.map((action, index) => (
                        <div key={index} className={`${action.color} text-white p-4 rounded-lg`}>
                            <div className="flex items-center gap-2">
                                <span className="text-2xl">{action.icon}</span>
                                <span className="font-semibold">{action.label}</span>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}
