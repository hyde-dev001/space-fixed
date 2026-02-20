/**
 * NotificationPreferences Page Component - Phase 7 Enhanced
 * User notification settings with Phase 6 features
 */

import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Settings, Bell, Mail, Volume2, Check, X, Moon, Archive, Smartphone, Clock } from 'lucide-react';
import { useNotificationPreferences, useUpdatePreferences } from '../../hooks/useNotifications';

interface NotificationPreferencesProps {
  basePath?: string;
  title?: string;
  userType?: 'customer' | 'shop_owner' | 'erp';
}

const NotificationPreferences: React.FC<NotificationPreferencesProps> = ({ 
  basePath = '/api/notifications',
  title = 'Notification Preferences',
  userType = 'customer'
}) => {
  const { data: preferences, isLoading } = useNotificationPreferences(basePath);
  const updatePreferences = useUpdatePreferences(basePath);
  const [saveSuccess, setSaveSuccess] = useState(false);

  // Customer notification categories
  const customerCategories = [
    { key: 'order_created', label: 'Order Confirmations', description: 'When your order is placed' },
    { key: 'order_status_changed', label: 'Order Status Updates', description: 'Shipping, delivery, and status changes' },
    { key: 'payment_received', label: 'Payment Confirmations', description: 'Payment successful notifications' },
    { key: 'payment_failed', label: 'Payment Failures', description: 'Failed payment alerts' },
    { key: 'repair_status_update', label: 'Repair Updates', description: 'Status changes on your repair requests' },
    { key: 'repair_quote_ready', label: 'Repair Quotes', description: 'When repair quotes are ready' },
    { key: 'new_message', label: 'New Messages', description: 'Messages from shop owners' },
    { key: 'review_reminder', label: 'Review Reminders', description: 'Reminders to review completed orders' },
  ];

  // Shop owner notification categories
  const shopOwnerCategories = [
    { key: 'new_repair_request', label: 'New Repair Requests', description: 'When customers submit repair requests' },
    { key: 'repair_approved', label: 'Repair Approvals', description: 'When customers approve repair quotes' },
    { key: 'repair_rejected', label: 'Repair Rejections', description: 'When customers reject repair quotes' },
    { key: 'payment_received', label: 'Payment Received', description: 'Payment notifications' },
    { key: 'new_message', label: 'New Messages', description: 'Messages from customers' },
    { key: 'new_review', label: 'New Reviews', description: 'Customer reviews and ratings' },
    { key: 'low_stock', label: 'Low Stock Alerts', description: 'Inventory low stock warnings' },
  ];

  // ERP notification categories
  const erpCategories = [
    { key: 'employee_registered', label: 'New Employees', description: 'New employee registrations' },
    { key: 'leave_request', label: 'Leave Requests', description: 'Employee leave requests' },
    { key: 'overtime_request', label: 'Overtime Requests', description: 'Employee overtime requests' },
    { key: 'attendance_alert', label: 'Attendance Alerts', description: 'Late arrivals and absences' },
    { key: 'approval_required', label: 'Approval Requests', description: 'Items requiring approval' },
    { key: 'system_maintenance', label: 'System Alerts', description: 'System maintenance and updates' },
    { key: 'price_change_request', label: 'Price Change Requests', description: 'Product price change requests' },
  ];

  const categories = userType === 'shop_owner' ? shopOwnerCategories : 
                    userType === 'erp' ? erpCategories : customerCategories;

  const handleToggle = (category: string, enabled: boolean) => {
    if (!preferences) return;

    const updatedPreferences = {
      ...preferences.preferences,
      [category]: enabled,
    };

    updatePreferences.mutate(
      { preferences: updatedPreferences },
      {
        onSuccess: () => {
          setSaveSuccess(true);
          setTimeout(() => setSaveSuccess(false), 3000);
        },
      }
    );
  };

  const handleEmailDigestChange = (frequency: 'none' | 'daily' | 'weekly') => {
    if (!preferences) return;

    updatePreferences.mutate(
      { email_digest_frequency: frequency },
      {
        onSuccess: () => {
          setSaveSuccess(true);
          setTimeout(() => setSaveSuccess(false), 3000);
        },
      }
    );
  };

  const handleSoundToggle = (enabled: boolean) => {
    if (!preferences) return;

    updatePreferences.mutate(
      { sound_enabled: enabled },
      {
        onSuccess: () => {
          setSaveSuccess(true);
          setTimeout(() => setSaveSuccess(false), 3000);
        },
      }
    );
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  return (
    <>
      <Head title={title} />

      <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        {/* Header */}
        <div className="mb-8">
          <div className="flex items-center gap-3 mb-2">
            <Settings size={32} className="text-gray-700" />
            <h1 className="text-3xl font-bold text-gray-900">{title}</h1>
          </div>
          <p className="text-gray-600">
            Manage how and when you receive notifications
          </p>
        </div>

        {/* Success Message */}
        {saveSuccess && (
          <div className="mb-6 bg-green-50 border border-green-200 rounded-lg p-4 flex items-center gap-3">
            <Check size={20} className="text-green-600" />
            <span className="text-green-800 font-medium">Preferences saved successfully!</span>
          </div>
        )}

        {/* In-App Notifications */}
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
          <div className="p-6 border-b border-gray-200">
            <div className="flex items-center gap-3">
              <Bell size={24} className="text-blue-600" />
              <div>
                <h2 className="text-xl font-semibold text-gray-900">In-App Notifications</h2>
                <p className="text-sm text-gray-600">Choose which notifications you want to see</p>
              </div>
            </div>
          </div>

          <div className="p-6">
            <div className="space-y-4">
              {categories.map((category) => {
                const isEnabled = preferences?.preferences?.[category.key] ?? true;
                
                return (
                  <div key={category.key} className="flex items-start justify-between py-3 border-b border-gray-100 last:border-0">
                    <div className="flex-1">
                      <h3 className="text-base font-medium text-gray-900">{category.label}</h3>
                      <p className="text-sm text-gray-600 mt-1">{category.description}</p>
                    </div>
                    <button
                      onClick={() => handleToggle(category.key, !isEnabled)}
                      disabled={updatePreferences.isPending}
                      className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 ${
                        isEnabled ? 'bg-blue-600' : 'bg-gray-200'
                      } ${updatePreferences.isPending ? 'opacity-50 cursor-not-allowed' : ''}`}
                    >
                      <span
                        className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                          isEnabled ? 'translate-x-5' : 'translate-x-0'
                        }`}
                      />
                    </button>
                  </div>
                );
              })}
            </div>
          </div>
        </div>

        {/* Email Digest */}
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 mb-6">
          <div className="p-6 border-b border-gray-200">
            <div className="flex items-center gap-3">
              <Mail size={24} className="text-green-600" />
              <div>
                <h2 className="text-xl font-semibold text-gray-900">Email Digest</h2>
                <p className="text-sm text-gray-600">Receive notification summaries via email</p>
              </div>
            </div>
          </div>

          <div className="p-6">
            <div className="space-y-3">
              {[
                { value: 'none', label: 'Never', description: 'No email notifications' },
                { value: 'daily', label: 'Daily', description: 'One email per day with all notifications' },
                { value: 'weekly', label: 'Weekly', description: 'One email per week with all notifications' },
              ].map((option) => {
                const isSelected = (preferences?.email_digest_frequency || 'none') === option.value;
                
                return (
                  <button
                    key={option.value}
                    onClick={() => handleEmailDigestChange(option.value as 'none' | 'daily' | 'weekly')}
                    disabled={updatePreferences.isPending}
                    className={`w-full text-left p-4 rounded-lg border-2 transition-colors ${
                      isSelected 
                        ? 'border-blue-600 bg-blue-50' 
                        : 'border-gray-200 hover:border-gray-300'
                    } ${updatePreferences.isPending ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}`}
                  >
                    <div className="flex items-center justify-between">
                      <div>
                        <h3 className="text-base font-medium text-gray-900">{option.label}</h3>
                        <p className="text-sm text-gray-600 mt-1">{option.description}</p>
                      </div>
                      {isSelected && (
                        <Check size={20} className="text-blue-600" />
                      )}
                    </div>
                  </button>
                );
              })}
            </div>
          </div>
        </div>

        {/* Sound Preferences */}
        <div className="bg-white rounded-lg shadow-sm border border-gray-200">
          <div className="p-6 border-b border-gray-200">
            <div className="flex items-center gap-3">
              <Volume2 size={24} className="text-purple-600" />
              <div>
                <h2 className="text-xl font-semibold text-gray-900">Sound & Alerts</h2>
                <p className="text-sm text-gray-600">Notification sound preferences</p>
              </div>
            </div>
          </div>

          <div className="p-6">
            <div className="flex items-start justify-between">
              <div className="flex-1">
                <h3 className="text-base font-medium text-gray-900">Notification Sound</h3>
                <p className="text-sm text-gray-600 mt-1">Play a sound when new notifications arrive</p>
              </div>
              <button
                onClick={() => handleSoundToggle(!preferences?.sound_enabled)}
                disabled={updatePreferences.isPending}
                className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 ${
                  preferences?.sound_enabled ? 'bg-blue-600' : 'bg-gray-200'
                } ${updatePreferences.isPending ? 'opacity-50 cursor-not-allowed' : ''}`}
              >
                <span
                  className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                    preferences?.sound_enabled ? 'translate-x-5' : 'translate-x-0'
                  }`}
                />
              </button>
            </div>
          </div>
        </div>

        {/* Quiet Hours Section */}
        <div className="bg-white shadow rounded-lg overflow-hidden">
          <div className="border-b border-gray-200">
            <div className="p-6">
              <div className="flex items-center gap-3">
                <Moon className="w-5 h-5 text-blue-600" />
                <div>
                  <h2 className="text-lg font-semibold text-gray-900">Quiet Hours</h2>
                  <p className="text-sm text-gray-600">Suppress notifications during specific hours (e.g., sleep time)</p>
                </div>
              </div>
            </div>
          </div>

          <div className="p-6 space-y-4">
            {/* Enable Toggle */}
            <div className="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
              <div className="flex items-center gap-3">
                <Clock className="w-5 h-5 text-gray-500" />
                <div>
                  <h3 className="font-medium text-gray-900">Enable Quiet Hours</h3>
                  <p className="text-sm text-gray-600">Mute notifications during specified time range</p>
                </div>
              </div>
              <button
                onClick={() => {
                  updatePreferences.mutate({ quiet_hours_enabled: !preferences?.quiet_hours_enabled });
                }}
                disabled={updatePreferences.isPending}
                className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 ${
                  preferences?.quiet_hours_enabled ? 'bg-blue-600' : 'bg-gray-200'
                } ${updatePreferences.isPending ? 'opacity-50 cursor-not-allowed' : ''}`}
              >
                <span className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                  preferences?.quiet_hours_enabled ? 'translate-x-5' : 'translate-x-0'
                }`} />
              </button>
            </div>

            {/* Time Range Pickers */}
            {preferences?.quiet_hours_enabled && (
              <div className="grid grid-cols-2 gap-4 p-4 bg-blue-50 rounded-lg border border-blue-200">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2">Start Time</label>
                  <input
                    type="time"
                    value={preferences?.quiet_hours_start || '22:00'}
                    onChange={(e) => updatePreferences.mutate({ quiet_hours_start: e.target.value })}
                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2">End Time</label>
                  <input
                    type="time"
                    value={preferences?.quiet_hours_end || '08:00'}
                    onChange={(e) => updatePreferences.mutate({ quiet_hours_end: e.target.value })}
                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  />
                </div>
              </div>
            )}
          </div>
        </div>

        {/* Auto-Archive Section */}
        <div className="bg-white shadow rounded-lg overflow-hidden">
          <div className="border-b border-gray-200">
            <div className="p-6">
              <div className="flex items-center gap-3">
                <Archive className="w-5 h-5 text-blue-600" />
                <div>
                  <h2 className="text-lg font-semibold text-gray-900">Auto-Archive</h2>
                  <p className="text-sm text-gray-600">Automatically archive read notifications after a specified number of days</p>
                </div>
              </div>
            </div>
          </div>

          <div className="p-6 space-y-4">
            {/* Enable Toggle */}
            <div className="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
              <div>
                <h3 className="font-medium text-gray-900">Enable Auto-Archive</h3>
                <p className="text-sm text-gray-600">Cleanup old read notifications automatically</p>
              </div>
              <button
                onClick={() => {
                  updatePreferences.mutate({ auto_archive_enabled: !preferences?.auto_archive_enabled });
                }}
                disabled={updatePreferences.isPending}
                className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 ${
                  preferences?.auto_archive_enabled ? 'bg-blue-600' : 'bg-gray-200'
                } ${updatePreferences.isPending ? 'opacity-50 cursor-not-allowed' : ''}`}
              >
                <span className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                  preferences?.auto_archive_enabled ? 'translate-x-5' : 'translate-x-0'
                }`} />
              </button>
            </div>

            {/* Days Slider */}
            {preferences?.auto_archive_enabled && (
              <div className="p-4 bg-blue-50 rounded-lg border border-blue-200">
                <label className="block text-sm font-medium text-gray-700 mb-3">
                  Archive after {preferences?.auto_archive_days || 30} days
                </label>
                <input
                  type="range"
                  min="1"
                  max="365"
                  value={preferences?.auto_archive_days || 30}
                  onChange={(e) => updatePreferences.mutate({ auto_archive_days: parseInt(e.target.value) })}
                  className="w-full h-2 bg-blue-200 rounded-lg appearance-none cursor-pointer"
                  style={{
                    background: `linear-gradient(to right, #3B82F6 0%, #3B82F6 ${((preferences?.auto_archive_days || 30) / 365) * 100}%, #DBEAFE ${((preferences?.auto_archive_days || 30) / 365) * 100}%, #DBEAFE 100%)`
                  }}
                />
                <div className="flex justify-between text-xs text-gray-500 mt-2">
                  <span>1 day</span>
                  <span>30 days</span>
                  <span>90 days</span>
                  <span>365 days</span>
                </div>
              </div>
            )}
          </div>
        </div>

        {/* Browser Push Notifications Section */}
        <div className="bg-white shadow rounded-lg overflow-hidden">
          <div className="border-b border-gray-200">
            <div className="p-6">
              <div className="flex items-center gap-3">
                <Smartphone className="w-5 h-5 text-blue-600" />
                <div>
                  <h2 className="text-lg font-semibold text-gray-900">Browser Push Notifications</h2>
                  <p className="text-sm text-gray-600">Receive notifications even when browser tab is inactive</p>
                </div>
              </div>
            </div>
          </div>

          <div className="p-6 space-y-4">
            {/* Enable Toggle */}
            <div className="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
              <div>
                <h3 className="font-medium text-gray-900">Enable Push Notifications</h3>
                <p className="text-sm text-gray-600">
                  {typeof Notification !== 'undefined' && Notification.permission === 'granted' 
                    ? '✓ Permission granted' 
                    : Notification.permission === 'denied'
                    ? '✗ Permission denied'
                    : 'Browser permission required'}
                </p>
              </div>
              <button
                onClick={async () => {
                  if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
                    const permission = await Notification.requestPermission();
                    if (permission === 'granted') {
                      updatePreferences.mutate({ browser_push_enabled: true });
                    }
                  } else {
                    updatePreferences.mutate({ browser_push_enabled: !preferences?.browser_push_enabled });
                  }
                }}
                disabled={updatePreferences.isPending || (typeof Notification !== 'undefined' && Notification.permission === 'denied')}
                className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 ${
                  preferences?.browser_push_enabled ? 'bg-blue-600' : 'bg-gray-200'
                } ${updatePreferences.isPending || (typeof Notification !== 'undefined' && Notification.permission === 'denied') ? 'opacity-50 cursor-not-allowed' : ''}`}
              >
                <span className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                  preferences?.browser_push_enabled ? 'translate-x-5' : 'translate-x-0'
                }`} />
              </button>
            </div>

            {/* Permission Status */}
            {typeof Notification !== 'undefined' && Notification.permission === 'denied' && (
              <div className="p-4 bg-red-50 border border-red-200 rounded-lg">
                <p className="text-sm text-red-800">
                  ⚠️ Browser notifications are blocked. Please enable them in your browser settings.
                </p>
              </div>
            )}
          </div>
        </div>

        {/* Info Box */}
        <div className="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
          <p className="text-sm text-blue-800">
            <strong>Note:</strong> Changes are saved automatically. You can update your preferences anytime.
          </p>
        </div>
      </div>
    </>
  );
};

export default NotificationPreferences;
