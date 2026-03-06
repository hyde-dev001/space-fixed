/**
 * Account Type Information Banner
 * 
 * Displays registration type (Individual/Business) and business type (Retail/Repair/Both)
 * Shows upgrade prompt for Individual accounts with limitations
 */

import React from 'react';
import { usePage } from '@inertiajs/react';
import { AlertCircle, Building2, User, Store, Wrench } from 'lucide-react';

interface ShopOwnerAuth {
    id: number;
    business_name: string;
    business_type: string;
    registration_type: string;
    is_individual: boolean;
    is_company: boolean;
    can_manage_staff: boolean;
    max_locations: number | null;
}

interface PageProps {
    auth: {
        shop_owner: ShopOwnerAuth | null;
    };
}

export default function AccountTypeInfo() {
    const { auth } = usePage<PageProps>().props;
    const shopOwner = auth?.shop_owner;

    if (!shopOwner) return null;

    const isIndividual = shopOwner.is_individual;
    const isCompany = shopOwner.is_company;
    const businessType = shopOwner.business_type;

    // Determine business type icon and label
    const getBusinessTypeInfo = () => {
        if (businessType === 'retail') {
            return { icon: <Store className="w-5 h-5" />, label: 'Retail Shop', color: 'blue' };
        } else if (businessType === 'repair') {
            return { icon: <Wrench className="w-5 h-5" />, label: 'Repair Services', color: 'green' };
        } else {
            return { icon: <Store className="w-5 h-5" />, label: 'Retail & Repair', color: 'purple' };
        }
    };

    const businessTypeInfo = getBusinessTypeInfo();

    return (
        <div className="space-y-4 mb-6">
            {/* Account Type Banner */}
            <div className={`rounded-lg border p-4 ${
                isIndividual 
                    ? 'bg-blue-50 border-blue-200 dark:bg-blue-900/20 dark:border-blue-800' 
                    : 'bg-purple-50 border-purple-200 dark:bg-purple-900/20 dark:border-purple-800'
            }`}>
                <div className="flex items-start gap-3">
                    <div className={`p-2 rounded-lg ${
                        isIndividual ? 'bg-blue-100 dark:bg-blue-800' : 'bg-purple-100 dark:bg-purple-800'
                    }`}>
                        {isIndividual ? (
                            <User className="w-5 h-5 text-blue-600 dark:text-blue-300" />
                        ) : (
                            <Building2 className="w-5 h-5 text-purple-600 dark:text-purple-300" />
                        )}
                    </div>
                    <div className="flex-1">
                        <div className="flex items-center gap-2">
                            <h3 className={`font-semibold ${
                                isIndividual ? 'text-blue-900 dark:text-blue-100' : 'text-purple-900 dark:text-purple-100'
                            }`}>
                                {isIndividual ? 'Individual Account' : 'Business Account'}
                            </h3>
                            <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${
                                businessTypeInfo.color === 'blue' 
                                    ? 'bg-blue-100 text-blue-700 dark:bg-blue-800 dark:text-blue-200'
                                    : businessTypeInfo.color === 'green'
                                    ? 'bg-green-100 text-green-700 dark:bg-green-800 dark:text-green-200'
                                    : 'bg-purple-100 text-purple-700 dark:bg-purple-800 dark:text-purple-200'
                            }`}>
                                {businessTypeInfo.icon}
                                {businessTypeInfo.label}
                            </span>
                        </div>
                        <p className="text-sm text-gray-600 dark:text-gray-400 mt-1">
                            {shopOwner.business_name}
                        </p>
                        
                        {/* Account Capabilities */}
                        <div className="mt-3 flex flex-wrap gap-3 text-sm">
                            <div className="flex items-center gap-1.5">
                                <span className={`inline-block w-2 h-2 rounded-full ${
                                    isCompany ? 'bg-green-500' : 'bg-gray-400'
                                }`}></span>
                                <span className={isCompany ? 'text-gray-700 dark:text-gray-300' : 'text-gray-500 dark:text-gray-500'}>
                                    Staff Management
                                </span>
                            </div>
                            <div className="flex items-center gap-1.5">
                                <span className={`inline-block w-2 h-2 rounded-full ${
                                    shopOwner.max_locations === null ? 'bg-green-500' : 'bg-yellow-500'
                                }`}></span>
                                <span className="text-gray-700 dark:text-gray-300">
                                    {shopOwner.max_locations === null ? 'Unlimited Locations' : `${shopOwner.max_locations} Location Max`}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Individual Account Limitation Warning */}
            {isIndividual && (
                <div className="rounded-lg border border-yellow-200 bg-yellow-50 p-4 dark:bg-yellow-900/20 dark:border-yellow-800">
                    <div className="flex items-start gap-3">
                        <AlertCircle className="w-5 h-5 text-yellow-600 dark:text-yellow-400 flex-shrink-0 mt-0.5" />
                        <div className="flex-1">
                            <h4 className="font-medium text-yellow-900 dark:text-yellow-100">
                                Individual Account Limitations
                            </h4>
                            <p className="text-sm text-yellow-800 dark:text-yellow-200 mt-1">
                                Your account is limited to <strong>1 shop location</strong> and <strong>cannot add staff members</strong>.
                            </p>
                            <button
                                type="button"
                                className="mt-3 inline-flex items-center gap-2 px-4 py-2 bg-yellow-600 hover:bg-yellow-700 text-white text-sm font-medium rounded-lg transition-colors"
                                onClick={() => {
                                    // TODO: Implement upgrade flow
                                    alert('Upgrade to Business feature coming soon!');
                                }}
                            >
                                <Building2 className="w-4 h-4" />
                                Upgrade to Business Account
                            </button>
                        </div>
                    </div>
                </div>
            )}

        </div>
    );
}
