/**
 * NotificationPreferences Page Component - Phase 7 Enhanced
 * User notification settings with Phase 6 features
 */

import React, { useEffect, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Settings, Bell, Mail, Volume2, Check, Moon, Archive, Smartphone, Clock } from 'lucide-react';
import { useNotificationPreferences, useUpdatePreferences } from '../../hooks/useNotifications';

interface NotificationPreferencesProps {
  basePath?: string;
  title?: string;
  userType?: 'customer' | 'shop_owner' | 'erp';
}

type PreferenceValue = string | number | boolean | null | undefined | Record<string, unknown>;
type PreferenceMap = Record<string, PreferenceValue>;

const coerceBoolean = (value: unknown, defaultValue = true): boolean => {
  if (typeof value === 'boolean') return value;
  if (typeof value === 'number') return value !== 0;

  if (typeof value === 'string') {
    const normalized = value.trim().toLowerCase();
    if (normalized === 'true' || normalized === '1') return true;
    if (normalized === 'false' || normalized === '0' || normalized === '') return false;
  }

  if (value == null) return defaultValue;
  return Boolean(value);
};

const normalizePreferences = (raw: unknown): PreferenceMap => {
  if (!raw || typeof raw !== 'object') return {};

  const record = raw as Record<string, unknown>;
  const nested = record.preferences && typeof record.preferences === 'object'
    ? (record.preferences as PreferenceMap)
    : {};

  return {
    ...nested,
    ...record,
  } as PreferenceMap;
};

const NotificationPreferences: React.FC<NotificationPreferencesProps> = ({ 
  basePath = '/api/notifications',
  title = 'Notification Preferences',
  userType = 'customer'
}) => {
  const { auth } = usePage().props as any;
  const userRole = auth?.user?.role;
  const { data: preferences, isLoading } = useNotificationPreferences(basePath);
  const updatePreferences = useUpdatePreferences(basePath);
  const [saveSuccess, setSaveSuccess] = useState(false);
  const [archiveDays, setArchiveDays] = useState<number | null>(null);
  const [localPreferences, setLocalPreferences] = useState<PreferenceMap>({});

  useEffect(() => {
    setLocalPreferences(normalizePreferences(preferences));
  }, [preferences]);

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

  // ERP notification categories - Base categories for all ERP staff
  const baseErpCategories = [
    { key: 'repair_assigned_to_me', label: 'Repair Assignments', description: 'When repairs are assigned to you' },
    { key: 'repair_status_update', label: 'Repair Status Updates', description: 'Customer actions on your repairs' },
    { key: 'repair_rejection_review', label: 'Rejection Reviews', description: 'Manager decisions on your rejections' },
    { key: 'task_assigned', label: 'Task Assignments', description: 'New tasks assigned to you' },
    { key: 'new_message', label: 'Messages', description: 'Customer and employee messages' },
    { key: 'system_maintenance', label: 'System Alerts', description: 'System maintenance and updates' },
  ];

  // HR/Manager specific categories
  const hrManagerCategories = [
    { key: 'employee_registered', label: 'New Employees', description: 'New employee registrations' },
    { key: 'leave_request', label: 'Leave Requests', description: 'Employee leave requests' },
    { key: 'overtime_request', label: 'Overtime Requests', description: 'Employee overtime requests' },
    { key: 'attendance_alert', label: 'Attendance Alerts', description: 'Late arrivals and absences' },
    { key: 'approval_required', label: 'Approval Requests', description: 'Items requiring approval' },
  ];

  // Finance/Manager specific categories
  const financeManagerCategories = [
    { key: 'price_change_request', label: 'Price Change Requests', description: 'Product price change requests' },
    { key: 'approval_required', label: 'Approval Requests', description: 'Items requiring approval' },
  ];

  // Determine ERP categories based on user role
  let erpCategories = [...baseErpCategories];
  if (userType === 'erp' && (userRole === 'HR' || userRole === 'Manager')) {
    erpCategories = [...baseErpCategories, ...hrManagerCategories];
  }
  if (userType === 'erp' && (userRole === 'Finance' || userRole === 'Manager')) {
    erpCategories = [...erpCategories, ...financeManagerCategories];
  }

  const categories = userType === 'shop_owner' ? shopOwnerCategories : 
                    userType === 'erp' ? erpCategories : customerCategories;

  const getCategoryPreferencesObject = (): Record<string, unknown> => {
    const rawPreferences = localPreferences.preferences;
    if (rawPreferences && typeof rawPreferences === 'object' && !Array.isArray(rawPreferences)) {
      return rawPreferences as Record<string, unknown>;
    }

    return {};
  };

  // Map category keys to actual database column names
  const getCategoryColumnName = (categoryKey: string): string => {
    // Map common patterns
    const mapping: Record<string, string> = {
      'order_created': 'browser_new_orders',
      'order_status_changed': 'browser_order_updates',
      'payment_received': 'browser_payment_updates',
      'payment_failed': 'browser_payment_updates',
      'repair_status_update': 'browser_repair_updates',
      'repair_quote_ready': 'browser_repair_updates',
      'new_message': 'browser_alerts',
      'review_reminder': 'browser_alerts',
      'new_repair_request': 'browser_repair_updates',
      'repair_approved': 'browser_repair_updates',
      'repair_rejected': 'browser_repair_updates',
      'new_review': 'browser_alerts',
      'low_stock': 'browser_alerts',
      'repair_assigned_to_me': 'browser_order_updates',  // Use order_updates for repair assignments (assignments are like new orders for repairers)
      'repair_rejection_review': 'browser_approvals',
      'task_assigned': 'browser_tasks',
      'system_maintenance': 'browser_alerts',
      'employee_registered': 'browser_hr_updates',
      'leave_request': 'browser_hr_updates',
      'overtime_request': 'browser_hr_updates',
      'attendance_alert': 'browser_hr_updates',
      'approval_required': 'browser_approvals',
      'price_change_request': 'browser_approvals',
    };
    return mapping[categoryKey] || 'browser_alerts';
  };

  const getCategoryEnabled = (categoryKey: string): boolean => {
    const categoryPreferences = getCategoryPreferencesObject();
    const storedCategoryValue = categoryPreferences[categoryKey];

    if (storedCategoryValue !== undefined) {
      return coerceBoolean(storedCategoryValue, true);
    }

    const columnName = getCategoryColumnName(categoryKey);
    return coerceBoolean(localPreferences[columnName], true);
  };

  const getPreferenceFlag = (key: string, defaultValue = false): boolean => {
    return coerceBoolean(localPreferences[key], defaultValue);
  };

  const getPreferenceString = (key: string, defaultValue: string): string => {
    const value = localPreferences[key];
    if (typeof value === 'string' && value.trim() !== '') return value;
    return defaultValue;
  };

  const getPreferenceNumber = (key: string, defaultValue: number): number => {
    const value = localPreferences[key];
    if (typeof value === 'number' && Number.isFinite(value)) return value;
    if (typeof value === 'string') {
      const parsed = Number(value);
      if (Number.isFinite(parsed)) return parsed;
    }
    return defaultValue;
  };

  const showSaved = () => {
    setSaveSuccess(true);
    setTimeout(() => setSaveSuccess(false), 3000);
  };

  const mutatePreference = (payload: PreferenceMap) => {
    const previousState = localPreferences;

    setLocalPreferences((prev) => ({
      ...prev,
      ...payload,
    }));

    updatePreferences.mutate(payload, {
      onSuccess: () => {
        showSaved();
      },
      onError: () => {
        setLocalPreferences(previousState);
      },
    });
  };

  const handleToggle = (category: string, enabled: boolean) => {
    const categoryPreferences = {
      ...getCategoryPreferencesObject(),
    };

    // Ensure every category has an explicit value to avoid shared-column side effects.
    categories.forEach((categoryItem) => {
      if (categoryPreferences[categoryItem.key] === undefined) {
        categoryPreferences[categoryItem.key] = getCategoryEnabled(categoryItem.key);
      }
    });

    categoryPreferences[category] = enabled;

    mutatePreference({ preferences: categoryPreferences });
  };

  const handleEmailDigestChange = (frequency: 'none' | 'daily' | 'weekly') => {
    mutatePreference({ email_digest_frequency: frequency });
  };

  const handleSoundToggle = (enabled: boolean) => {
    mutatePreference({ sound_enabled: enabled });
  };

  const browserNotificationPermission = typeof Notification !== 'undefined' ? Notification.permission : 'default';

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen bg-gray-50 dark:bg-gray-950">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  return (
    <>
      <Head title={title} />

      <div className="min-h-screen px-4 sm:px-6 lg:px-8 py-8 bg-gray-50 dark:bg-gray-950">
        {userType === 'shop_owner' && (
          <div className="mb-2">
            <button
              type="button"
              onClick={() => window.history.back()}
              className="inline-flex items-center px-1 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 dark:text-gray-200 dark:hover:text-white"
            >
              ← Back
            </button>
          </div>
        )}

        {userType === 'customer' && (
          <div className="mb-2">
            <button
              type="button"
              onClick={() => window.history.back()}
              className="inline-flex items-center px-1 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 dark:text-gray-200 dark:hover:text-white"
            >
              ← Back
            </button>
          </div>
        )}

        <div className="max-w-4xl mx-auto">
          {/* Header */}
          <div className="mb-8">
          <div className="flex items-center gap-3 mb-2">
            <Settings size={32} className="text-gray-700 dark:text-gray-200" />
            <h1 className="text-3xl font-bold text-gray-900 dark:text-white">{title}</h1>
          </div>
          <p className="text-gray-600 dark:text-gray-400">
            Manage how and when you receive notifications
          </p>
        </div>

        {/* Success Message */}
        {saveSuccess && (
          <div className="mb-6 bg-green-50 border border-green-200 rounded-lg p-4 flex items-center gap-3 dark:bg-green-900/20 dark:border-green-800">
            <Check size={20} className="text-green-600" />
            <span className="text-green-800 font-medium dark:text-green-300">Preferences saved successfully!</span>
          </div>
        )}

        {/* In-App Notifications */}
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 dark:bg-gray-900 dark:border-gray-700">
          <div className="p-6 border-b border-gray-200 dark:border-gray-700">
            <div className="flex items-center gap-3">
              <Bell size={24} className="text-blue-600" />
              <div>
                <h2 className="text-xl font-semibold text-gray-900 dark:text-white">In-App Notifications</h2>
                <p className="text-sm text-gray-600 dark:text-gray-400">Choose which notifications you want to see</p>
              </div>
            </div>
          </div>

          <div className="p-6">
            <div className="space-y-4">
              {categories.map((category) => {
                const isEnabled = getCategoryEnabled(category.key);
                
                return (
                  <div key={category.key} className="flex items-start justify-between py-3 border-b border-gray-100 dark:border-gray-700 last:border-0">
                    <div className="flex-1">
                      <h3 className="text-base font-medium text-gray-900 dark:text-white">{category.label}</h3>
                      <p className="text-sm text-gray-600 mt-1 dark:text-gray-400">{category.description}</p>
                    </div>
                    <button
                      onClick={() => handleToggle(category.key, !isEnabled)}
                      disabled={updatePreferences.isPending}
                      className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 ${
                        isEnabled ? 'bg-blue-600' : 'bg-gray-200 dark:bg-gray-600'
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
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 mb-6 dark:bg-gray-900 dark:border-gray-700">
          <div className="p-6 border-b border-gray-200 dark:border-gray-700">
            <div className="flex items-center gap-3">
              <Mail size={24} className="text-green-600" />
              <div>
                <h2 className="text-xl font-semibold text-gray-900 dark:text-white">Email Digest</h2>
                <p className="text-sm text-gray-600 dark:text-gray-400">Receive notification summaries via email</p>
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
                const isSelected = getPreferenceString('email_digest_frequency', 'none') === option.value;
                
                return (
                  <button
                    key={option.value}
                    onClick={() => handleEmailDigestChange(option.value as 'none' | 'daily' | 'weekly')}
                    disabled={updatePreferences.isPending}
                    className={`w-full text-left p-4 rounded-lg border-2 transition-colors ${
                      isSelected 
                        ? 'border-blue-600 bg-blue-50 dark:bg-blue-900/20' 
                        : 'border-gray-200 hover:border-gray-300 dark:border-gray-700 dark:hover:border-gray-600'
                    } ${updatePreferences.isPending ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'}`}
                  >
                    <div className="flex items-center justify-between">
                      <div>
                        <h3 className="text-base font-medium text-gray-900 dark:text-white">{option.label}</h3>
                        <p className="text-sm text-gray-600 mt-1 dark:text-gray-400">{option.description}</p>
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
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 dark:bg-gray-900 dark:border-gray-700">
          <div className="p-6 border-b border-gray-200 dark:border-gray-700">
            <div className="flex items-center gap-3">
              <Volume2 size={24} className="text-purple-600" />
              <div>
                <h2 className="text-xl font-semibold text-gray-900 dark:text-white">Sound & Alerts</h2>
                <p className="text-sm text-gray-600 dark:text-gray-400">Notification sound preferences</p>
              </div>
            </div>
          </div>

          <div className="p-6">
            <div className="flex items-start justify-between">
              <div className="flex-1">
                <h3 className="text-base font-medium text-gray-900 dark:text-white">Notification Sound</h3>
                <p className="text-sm text-gray-600 mt-1 dark:text-gray-400">Play a sound when new notifications arrive</p>
              </div>
              <button
                onClick={() => handleSoundToggle(!getPreferenceFlag('sound_enabled', false))}
                disabled={updatePreferences.isPending}
                className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 ${
                  getPreferenceFlag('sound_enabled', false) ? 'bg-blue-600' : 'bg-gray-200 dark:bg-gray-600'
                } ${updatePreferences.isPending ? 'opacity-50 cursor-not-allowed' : ''}`}
              >
                <span
                  className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                    getPreferenceFlag('sound_enabled', false) ? 'translate-x-5' : 'translate-x-0'
                  }`}
                />
              </button>
            </div>
          </div>
        </div>

        {/* Quiet Hours Section */}
        <div className="bg-white shadow rounded-lg overflow-hidden dark:bg-gray-900 dark:border dark:border-gray-700">
          <div className="border-b border-gray-200 dark:border-gray-700">
            <div className="p-6">
              <div className="flex items-center gap-3">
                <Moon className="w-5 h-5 text-blue-600" />
                <div>
                  <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Quiet Hours</h2>
                  <p className="text-sm text-gray-600 dark:text-gray-400">Suppress notifications during specific hours (e.g., sleep time)</p>
                </div>
              </div>
            </div>
          </div>

          <div className="p-6 space-y-4">
            {/* Enable Toggle */}
            <div className="flex items-center justify-between p-4 bg-gray-50 rounded-lg dark:bg-gray-800">
              <div className="flex items-center gap-3">
                <Clock className="w-5 h-5 text-gray-500 dark:text-gray-400" />
                <div>
                  <h3 className="font-medium text-gray-900 dark:text-white">Enable Quiet Hours</h3>
                  <p className="text-sm text-gray-600 dark:text-gray-400">Mute notifications during specified time range</p>
                </div>
              </div>
              <button
                onClick={() => {
                  mutatePreference({ quiet_hours_enabled: !getPreferenceFlag('quiet_hours_enabled', false) });
                }}
                disabled={updatePreferences.isPending}
                className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 ${
                  getPreferenceFlag('quiet_hours_enabled', false) ? 'bg-blue-600' : 'bg-gray-200 dark:bg-gray-600'
                } ${updatePreferences.isPending ? 'opacity-50 cursor-not-allowed' : ''}`}
              >
                <span className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                  getPreferenceFlag('quiet_hours_enabled', false) ? 'translate-x-5' : 'translate-x-0'
                }`} />
              </button>
            </div>

            {/* Time Range Pickers */}
            {getPreferenceFlag('quiet_hours_enabled', false) && (
              <div className="grid grid-cols-2 gap-4 p-4 bg-blue-50 rounded-lg border border-blue-200 dark:bg-blue-900/20 dark:border-blue-800">
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2 dark:text-gray-300">Start Time</label>
                  <input
                    type="time"
                    value={getPreferenceString('quiet_hours_start', '22:00')}
                    onChange={(e) => mutatePreference({ quiet_hours_start: e.target.value })}
                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:text-white"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 mb-2 dark:text-gray-300">End Time</label>
                  <input
                    type="time"
                    value={getPreferenceString('quiet_hours_end', '08:00')}
                    onChange={(e) => mutatePreference({ quiet_hours_end: e.target.value })}
                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:text-white"
                  />
                </div>
              </div>
            )}
          </div>
        </div>

        {/* Auto-Archive Section */}
        <div className="bg-white shadow rounded-lg overflow-hidden dark:bg-gray-900 dark:border dark:border-gray-700">
          <div className="border-b border-gray-200 dark:border-gray-700">
            <div className="p-6">
              <div className="flex items-center gap-3">
                <Archive className="w-5 h-5 text-blue-600" />
                <div>
                  <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Auto-Archive</h2>
                  <p className="text-sm text-gray-600 dark:text-gray-400">Automatically archive read notifications after a specified number of days</p>
                </div>
              </div>
            </div>
          </div>

          <div className="p-6 space-y-4">
            {/* Enable Toggle */}
            <div className="flex items-center justify-between p-4 bg-gray-50 rounded-lg dark:bg-gray-800">
              <div>
                <h3 className="font-medium text-gray-900 dark:text-white">Enable Auto-Archive</h3>
                <p className="text-sm text-gray-600 dark:text-gray-400">Cleanup old read notifications automatically</p>
              </div>
              <button
                onClick={() => {
                  mutatePreference({ auto_archive_enabled: !getPreferenceFlag('auto_archive_enabled', false) });
                }}
                disabled={updatePreferences.isPending}
                className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 ${
                  getPreferenceFlag('auto_archive_enabled', false) ? 'bg-blue-600' : 'bg-gray-200 dark:bg-gray-600'
                } ${updatePreferences.isPending ? 'opacity-50 cursor-not-allowed' : ''}`}
              >
                <span className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                  getPreferenceFlag('auto_archive_enabled', false) ? 'translate-x-5' : 'translate-x-0'
                }`} />
              </button>
            </div>

            {/* Days Slider */}
            {getPreferenceFlag('auto_archive_enabled', false) && (
              <div className="p-4 bg-blue-50 rounded-lg border border-blue-200 dark:bg-blue-900/20 dark:border-blue-800">
                <label className="block text-sm font-medium text-gray-700 mb-3 dark:text-gray-300">
                  Archive after {archiveDays ?? getPreferenceNumber('auto_archive_days', 30)} days
                </label>
                <input
                  type="range"
                  min="1"
                  max="365"
                  value={archiveDays ?? getPreferenceNumber('auto_archive_days', 30)}
                  onChange={(e) => setArchiveDays(parseInt(e.target.value))}
                  onMouseUp={(e) => {
                    if (archiveDays !== null) {
                      mutatePreference({ auto_archive_days: archiveDays });
                      setArchiveDays(null);
                    }
                  }}
                  onTouchEnd={(e) => {
                    if (archiveDays !== null) {
                      mutatePreference({ auto_archive_days: archiveDays });
                      setArchiveDays(null);
                    }
                  }}
                  className="w-full h-2 bg-blue-200 rounded-lg appearance-none cursor-pointer"
                  style={{
                    background: `linear-gradient(to right, #3B82F6 0%, #3B82F6 ${((archiveDays ?? getPreferenceNumber('auto_archive_days', 30)) / 365) * 100}%, #DBEAFE ${((archiveDays ?? getPreferenceNumber('auto_archive_days', 30)) / 365) * 100}%, #DBEAFE 100%)`
                  }}
                />
                <div className="flex justify-between text-xs text-gray-500 mt-2 dark:text-gray-400">
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
        <div className="bg-white shadow rounded-lg overflow-hidden dark:bg-gray-900 dark:border dark:border-gray-700">
          <div className="border-b border-gray-200 dark:border-gray-700">
            <div className="p-6">
              <div className="flex items-center gap-3">
                <Smartphone className="w-5 h-5 text-blue-600" />
                <div>
                  <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Browser Push Notifications</h2>
                  <p className="text-sm text-gray-600 dark:text-gray-400">Receive notifications even when browser tab is inactive</p>
                </div>
              </div>
            </div>
          </div>

          <div className="p-6 space-y-4">
            {/* Enable Toggle */}
            <div className="flex items-center justify-between p-4 bg-gray-50 rounded-lg dark:bg-gray-800">
              <div>
                <h3 className="font-medium text-gray-900 dark:text-white">Enable Push Notifications</h3>
                <p className="text-sm text-gray-600 dark:text-gray-400">
                  {browserNotificationPermission === 'granted' 
                    ? '✓ Permission granted' 
                    : browserNotificationPermission === 'denied'
                    ? '✗ Permission denied'
                    : 'Browser permission required'}
                </p>
              </div>
              <button
                onClick={async () => {
                  if (typeof Notification !== 'undefined' && browserNotificationPermission === 'default') {
                    const permission = await Notification.requestPermission();
                    if (permission === 'granted') {
                      mutatePreference({ browser_push_enabled: true });
                    }
                  } else {
                    mutatePreference({ browser_push_enabled: !getPreferenceFlag('browser_push_enabled', false) });
                  }
                }}
                disabled={updatePreferences.isPending || browserNotificationPermission === 'denied'}
                className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 ${
                  getPreferenceFlag('browser_push_enabled', false) ? 'bg-blue-600' : 'bg-gray-200 dark:bg-gray-600'
                } ${updatePreferences.isPending || browserNotificationPermission === 'denied' ? 'opacity-50 cursor-not-allowed' : ''}`}
              >
                <span className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
                  getPreferenceFlag('browser_push_enabled', false) ? 'translate-x-5' : 'translate-x-0'
                }`} />
              </button>
            </div>

            {/* Permission Status */}
            {browserNotificationPermission === 'denied' && (
              <div className="p-4 bg-red-50 border border-red-200 rounded-lg dark:bg-red-900/20 dark:border-red-800">
                <p className="text-sm text-red-800 dark:text-red-300">
                  ⚠️ Browser notifications are blocked. Please enable them in your browser settings.
                </p>
              </div>
            )}
          </div>
        </div>

        {/* Info Box */}
        <div className="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4 dark:bg-blue-900/20 dark:border-blue-800">
          <p className="text-sm text-blue-800 dark:text-blue-300">
            <strong>Note:</strong> Changes are saved automatically. You can update your preferences anytime.
          </p>
        </div>
        </div>
      </div>
    </>
  );
};

export default NotificationPreferences;
