/**
 * Shop Owner Layout Component
 * 
 * Main layout wrapper for all shop owner pages with:
 * - Conditional navigation based on business type and registration type
 * - Header with user info and logout
 * - Collapsible sidebar
 * - Responsive design
 */

import { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { ShopOwnerPageProps } from '@/types/shopOwner';
import { getShopOwnerNavigation } from '@/config/shopOwnerNavigation';

interface ShopOwnerLayoutProps {
    children: React.ReactNode;
    title?: string;
}

export default function ShopOwnerLayout({ children, title }: ShopOwnerLayoutProps) {
    const { auth } = usePage<ShopOwnerPageProps>().props;
    const shopOwner = auth.shop_owner;
    const [sidebarOpen, setSidebarOpen] = useState(true);

    // If not logged in as shop owner, don't render layout
    if (!shopOwner) {
        return <div>{children}</div>;
    }

    // Get filtered navigation based on access
    const navigationItems = getShopOwnerNavigation({
        businessType: shopOwner.business_type,
        registrationType: shopOwner.registration_type,
    });

    // Get business type label
    const businessTypeLabel = {
        'retail': '📦 Retail',
        'repair': '🔧 Repair',
        'both': '📦🔧 Both',
        'both (retail & repair)': '📦🔧 Both',
    }[shopOwner.business_type] || '📦 Retail';

    // Get registration type label
    const registrationTypeLabel = shopOwner.is_individual ? '👤 Individual' : '🏢 Company';

    return (
        <div className="min-h-screen bg-gray-50 flex">
            {/* Sidebar */}
            <aside 
                className={`${
                    sidebarOpen ? 'w-64' : 'w-20'
                } bg-white shadow-lg transition-all duration-300 ease-in-out fixed h-full overflow-y-auto z-20`}
            >
                {/* Logo/Brand Section */}
                <div className="p-4 border-b bg-gradient-to-r from-blue-600 to-blue-700">
                    {sidebarOpen ? (
                        <div>
                            <h2 className="text-lg font-bold text-white truncate">
                                {shopOwner.business_name}
                            </h2>
                            <p className="text-xs text-blue-100 mt-1">
                                {registrationTypeLabel} • {businessTypeLabel}
                            </p>
                        </div>
                    ) : (
                        <div className="text-center">
                            <span className="text-2xl">🏪</span>
                        </div>
                    )}
                </div>

                {/* Toggle Button */}
                <button
                    onClick={() => setSidebarOpen(!sidebarOpen)}
                    className="w-full py-2 text-gray-600 hover:bg-gray-100 transition-colors border-b"
                    title={sidebarOpen ? 'Collapse sidebar' : 'Expand sidebar'}
                >
                    <span className="text-lg">{sidebarOpen ? '◀' : '▶'}</span>
                </button>

                {/* Navigation */}
                <nav className="py-4">
                    {navigationItems.map((item, index) => (
                        <div key={index}>
                            <Link
                                href={item.path}
                                className={`flex items-center px-4 py-3 text-gray-700 hover:bg-blue-50 hover:text-blue-600 hover:border-l-4 hover:border-blue-600 transition-all ${
                                    item.className || ''
                                }`}
                            >
                                <span className="text-xl flex-shrink-0">{item.icon}</span>
                                {sidebarOpen && (
                                    <div className="ml-3 flex-1 flex items-center justify-between">
                                        <span className="font-medium">{item.label}</span>
                                        {item.badge && (
                                            <span className="px-2 py-0.5 bg-blue-100 text-blue-800 rounded text-xs">
                                                {item.badge}
                                            </span>
                                        )}
                                    </div>
                                )}
                            </Link>

                            {/* Sub-items */}
                            {sidebarOpen && item.subItems && (
                                <div className="bg-gray-50 border-l-2 border-gray-200 ml-8">
                                    {item.subItems.map((subItem, subIndex) => (
                                        <Link
                                            key={subIndex}
                                            href={subItem.path}
                                            className="flex items-center px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 hover:text-blue-600 transition-colors"
                                        >
                                            <span className="mr-2">•</span>
                                            {subItem.label}
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </div>
                    ))}
                </nav>
            </aside>

            {/* Main Content Area */}
            <div className={`flex-1 flex flex-col ${sidebarOpen ? 'ml-64' : 'ml-20'} transition-all duration-300`}>
                {/* Header */}
                <header className="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-10">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-800">{title || 'Dashboard'}</h1>
                        <p className="text-sm text-gray-500 mt-0.5">
                            {shopOwner.business_name}
                        </p>
                    </div>
                    <div className="flex items-center gap-4">
                        {/* Notifications Bell (placeholder) */}
                        <button className="relative p-2 text-gray-600 hover:text-gray-800 hover:bg-gray-100 rounded-full transition-colors">
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                            </svg>
                            {/* Notification badge */}
                            <span className="absolute top-1 right-1 w-2 h-2 bg-red-500 rounded-full"></span>
                        </button>

                        {/* User Menu */}
                        <div className="flex items-center gap-3">
                            <div className="text-right">
                                <p className="text-sm font-medium text-gray-800">
                                    {shopOwner.first_name} {shopOwner.last_name}
                                </p>
                                <p className="text-xs text-gray-500">{shopOwner.email}</p>
                            </div>
                            <div className="w-10 h-10 bg-blue-600 rounded-full flex items-center justify-center text-white font-semibold">
                                {shopOwner.first_name.charAt(0)}{shopOwner.last_name.charAt(0)}
                            </div>
                        </div>

                        {/* Logout Button */}
                        <Link
                            href="/shop-owner/logout"
                            method="post"
                            as="button"
                            className="px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-lg hover:bg-red-700 transition-colors"
                        >
                            Logout
                        </Link>
                    </div>
                </header>

                {/* Page Content */}
                <main className="flex-1 p-6 overflow-y-auto">
                    {children}
                </main>

                {/* Footer */}
                <footer className="bg-white border-t px-6 py-4">
                    <div className="flex justify-between items-center text-sm text-gray-600">
                        <p>© 2026 SoleSpace. All rights reserved.</p>
                        <div className="flex gap-4">
                            <Link href="/help" className="hover:text-blue-600">Help</Link>
                            <Link href="/privacy" className="hover:text-blue-600">Privacy</Link>
                            <Link href="/terms" className="hover:text-blue-600">Terms</Link>
                        </div>
                    </div>
                </footer>
            </div>
        </div>
    );
}
