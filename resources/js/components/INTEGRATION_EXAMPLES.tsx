/**
 * INTEGRATION EXAMPLE
 * How to add NotificationBell to existing navigation components
 */

// ============================================================
// EXAMPLE 1: Customer Navigation (E-commerce)
// ============================================================

// File: resources/js/Components/header/CustomerNavigation.tsx
import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import { ShoppingCart, User, Menu } from 'lucide-react';
import NotificationBell from '../common/NotificationBell';

const CustomerNavigation: React.FC = () => {
  const { auth, cartCount } = usePage().props as any;
  
  return (
    <nav className="bg-white shadow-sm border-b border-gray-200">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between h-16">
          
          {/* Logo */}
          <Link href="/" className="flex items-center">
            <img src="/images/logo.png" alt="Logo" className="h-8" />
          </Link>

          {/* Desktop Navigation */}
          <div className="hidden md:flex items-center gap-6">
            <Link href="/products" className="text-gray-700 hover:text-gray-900">
              Products
            </Link>
            <Link href="/repairs" className="text-gray-700 hover:text-gray-900">
              Repairs
            </Link>
            <Link href="/orders" className="text-gray-700 hover:text-gray-900">
              My Orders
            </Link>
          </div>

          {/* Right Side Actions */}
          <div className="flex items-center gap-4">
            
            {/* Notification Bell - NEW */}
            {auth.user && (
              <NotificationBell 
                basePath="/api/notifications"
                iconSize={24}
              />
            )}

            {/* Shopping Cart */}
            <Link href="/cart" className="relative p-2 text-gray-600 hover:text-gray-900">
              <ShoppingCart size={24} />
              {cartCount > 0 && (
                <span className="absolute top-0 right-0 inline-flex items-center justify-center px-2 py-1 text-xs font-bold text-white bg-red-500 rounded-full">
                  {cartCount}
                </span>
              )}
            </Link>

            {/* User Menu */}
            {auth.user ? (
              <Link href="/profile" className="flex items-center gap-2">
                <User size={24} />
                <span className="hidden md:block">{auth.user.name}</span>
              </Link>
            ) : (
              <Link href="/login" className="btn btn-primary">
                Sign In
              </Link>
            )}
          </div>
        </div>
      </div>
    </nav>
  );
};

export default CustomerNavigation;


// ============================================================
// EXAMPLE 2: Shop Owner Dashboard Header
// ============================================================

// File: resources/js/Components/header/ShopOwnerHeader.tsx
import React, { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Menu, X, Settings, LogOut } from 'lucide-react';
import NotificationBell from '../common/NotificationBell';

const ShopOwnerHeader: React.FC = () => {
  const { auth } = usePage().props as any;
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  return (
    <header className="bg-white shadow-md">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between h-16">
          
          {/* Logo & Title */}
          <div className="flex items-center gap-4">
            <Link href="/shop-owner/dashboard" className="text-xl font-bold text-gray-900">
              {auth.shop_owner?.shop_name || 'Shop Dashboard'}
            </Link>
          </div>

          {/* Desktop Actions */}
          <div className="hidden md:flex items-center gap-4">
            
            {/* Notification Bell - NEW */}
            <NotificationBell 
              basePath="/api/shop-owner/notifications"
              iconSize={26}
              className="mr-2"
            />

            {/* Settings */}
            <Link 
              href="/shop-owner/settings" 
              className="p-2 text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-lg transition-colors"
            >
              <Settings size={24} />
            </Link>

            {/* Profile Dropdown */}
            <div className="flex items-center gap-3 pl-4 border-l border-gray-300">
              <div className="text-right">
                <p className="text-sm font-medium text-gray-900">
                  {auth.shop_owner?.owner_name}
                </p>
                <p className="text-xs text-gray-500">Shop Owner</p>
              </div>
              <img 
                src={auth.shop_owner?.avatar || '/images/default-avatar.png'} 
                alt="Profile"
                className="w-10 h-10 rounded-full"
              />
            </div>
          </div>

          {/* Mobile Menu Button */}
          <button
            onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
            className="md:hidden p-2 text-gray-600 hover:text-gray-900"
          >
            {mobileMenuOpen ? <X size={24} /> : <Menu size={24} />}
          </button>
        </div>

        {/* Mobile Menu */}
        {mobileMenuOpen && (
          <div className="md:hidden py-4 border-t border-gray-200">
            <div className="flex flex-col gap-2">
              <Link href="/shop-owner/repairs" className="p-2 hover:bg-gray-100 rounded">
                Repair Requests
              </Link>
              <Link href="/shop-owner/notifications" className="p-2 hover:bg-gray-100 rounded">
                Notifications
              </Link>
              <Link href="/shop-owner/settings" className="p-2 hover:bg-gray-100 rounded">
                Settings
              </Link>
            </div>
          </div>
        )}
      </div>
    </header>
  );
};

export default ShopOwnerHeader;


// ============================================================
// EXAMPLE 3: ERP Dashboard Sidebar Navigation
// ============================================================

// File: resources/js/Components/common/ERPSidebar.tsx
import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Users, FileText, Settings, BarChart3 } from 'lucide-react';
import NotificationBell from './NotificationBell';

const ERPSidebar: React.FC = () => {
  const { auth, url } = usePage().props as any;

  const isActive = (path: string) => url.startsWith(path);

  return (
    <aside className="w-64 bg-gray-900 text-white min-h-screen">
      
      {/* Header */}
      <div className="p-6 border-b border-gray-800">
        <h1 className="text-xl font-bold">ERP System</h1>
        <p className="text-sm text-gray-400 mt-1">{auth.user?.name}</p>
      </div>

      {/* Notification Bell - Top of Sidebar - NEW */}
      <div className="p-4 border-b border-gray-800">
        <div className="flex items-center justify-between">
          <span className="text-sm font-medium text-gray-300">Notifications</span>
          <NotificationBell 
            basePath="/api/hr/notifications"
            iconSize={24}
            className="!text-white hover:!bg-gray-800"
          />
        </div>
      </div>

      {/* Navigation Links */}
      <nav className="p-4">
        <ul className="space-y-2">
          <li>
            <Link
              href="/hr/dashboard"
              className={`flex items-center gap-3 p-3 rounded-lg transition-colors ${
                isActive('/hr/dashboard') ? 'bg-gray-800' : 'hover:bg-gray-800'
              }`}
            >
              <BarChart3 size={20} />
              <span>Dashboard</span>
            </Link>
          </li>
          
          <li>
            <Link
              href="/hr/employees"
              className={`flex items-center gap-3 p-3 rounded-lg transition-colors ${
                isActive('/hr/employees') ? 'bg-gray-800' : 'hover:bg-gray-800'
              }`}
            >
              <Users size={20} />
              <span>Employees</span>
            </Link>
          </li>

          <li>
            <Link
              href="/hr/notifications"
              className={`flex items-center gap-3 p-3 rounded-lg transition-colors ${
                isActive('/hr/notifications') ? 'bg-gray-800' : 'hover:bg-gray-800'
              }`}
            >
              <FileText size={20} />
              <span>All Notifications</span>
            </Link>
          </li>

          <li>
            <Link
              href="/hr/settings"
              className={`flex items-center gap-3 p-3 rounded-lg transition-colors ${
                isActive('/hr/settings') ? 'bg-gray-800' : 'hover:bg-gray-800'
              }`}
            >
              <Settings size={20} />
              <span>Settings</span>
            </Link>
          </li>
        </ul>
      </nav>
    </aside>
  );
};

export default ERPSidebar;


// ============================================================
// EXAMPLE 4: Using NotificationBell Standalone
// ============================================================

// Any React component can use NotificationBell
import NotificationBell from '@/Components/common/NotificationBell';

function MyComponent() {
  return (
    <div className="flex items-center gap-4">
      <h2>Welcome Back!</h2>
      
      {/* Simple usage */}
      <NotificationBell />
      
      {/* Custom styling */}
      <NotificationBell 
        basePath="/api/notifications"
        iconSize={28}
        className="ml-auto"
      />
    </div>
  );
}


// ============================================================
// NOTES:
// ============================================================

/**
 * Key Integration Points:
 * 
 * 1. Import the component:
 *    import NotificationBell from '../common/NotificationBell';
 * 
 * 2. Add authentication check (optional but recommended):
 *    {auth.user && <NotificationBell />}
 * 
 * 3. Set correct basePath for user type:
 *    - Customers: "/api/notifications"
 *    - Shop Owners: "/api/shop-owner/notifications"
 *    - ERP Staff: "/api/hr/notifications"
 * 
 * 4. Style to match your navigation:
 *    - Use className prop for additional styling
 *    - Adjust iconSize as needed
 *    - Notification bell uses relative positioning for dropdown
 * 
 * 5. Ensure parent container has proper positioning:
 *    - Parent should have space for dropdown (absolute positioning)
 *    - Dropdown renders below the bell icon
 *    - Z-index of dropdown is 50
 */
