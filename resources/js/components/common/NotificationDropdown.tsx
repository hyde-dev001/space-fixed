/**
 * NotificationDropdown Component
 * Dropdown panel showing recent notifications
 */

import React, { useEffect, useRef } from 'react';
import { Link } from '@inertiajs/react';
import { X, Bell, CheckCheck } from 'lucide-react';
import { useRecentNotifications, useMarkAsRead, useMarkAllAsRead } from '../../hooks/useNotifications';
import NotificationItem from './NotificationItem';

interface NotificationDropdownProps {
  basePath: string;
  onClose: () => void;
}

const NotificationDropdown: React.FC<NotificationDropdownProps> = ({ basePath, onClose }) => {
  const dropdownRef = useRef<HTMLDivElement>(null);
  const { data: notifications = [], isLoading } = useRecentNotifications(10, basePath);
  const markAsRead = useMarkAsRead(basePath);
  const markAllPath = basePath === '/api/notifications' ? 'read-all' : 'mark-all-read';
  const markAllAsRead = useMarkAllAsRead(basePath, markAllPath);

  // Close dropdown when clicking outside
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
        onClose();
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [onClose]);

  const handleMarkAsRead = (id: number) => {
    markAsRead.mutate(id);
  };

  const handleMarkAllAsRead = () => {
    if (unreadCount === 0 || markAllAsRead.isPending) return;
    markAllAsRead.mutate();
  };

  const unreadCount = notifications.filter(n => !n.is_read).length;
  const notificationsListHref = basePath.includes('shop-owner')
    ? '/shop-owner/notifications'
    : basePath.includes('staff') || basePath.includes('hr')
      ? '/erp/notifications'
      : '/notifications';

  const getNotificationHref = (notification: { id: number; type?: string; data?: any; action_url?: string | null }) => {
    // Repairer: repair_assigned notifications go to Job Orders Repair page
    if (basePath.includes('staff') && notification.type?.includes('repair')) {
      const repairId = notification.data?.repair_id || notification.id;
      return `/erp/staff/job-orders-repair?highlightRepair=${repairId}`;
    }

    // Shop owner: repair notifications go to Job Orders Repair page
    if (basePath.includes('shop-owner') && notification.type?.includes('repair')) {
      const repairId = notification.data?.repair_id || notification.data?.repair_request_id || notification.id;
      return `/shop-owner/job-orders-repair?highlightRepair=${repairId}`;
    }

    // Customer: repair notifications go to my-repairs
    if (!basePath.includes('shop-owner') && !basePath.includes('staff') && notification.type?.includes('repair')) {
      const repairId = notification.data?.repair_id || notification.id;
      return `/my-repairs?highlightRepair=${repairId}`;
    }

    // Customer: order notifications go to my-orders
    if (!basePath.includes('shop-owner') && !basePath.includes('staff') && notification.type?.includes('order')) {
      const orderId = notification.data?.order_id || notification.id;
      return `/my-orders?highlightOrder=${orderId}`;
    }

    // Default fallback: go to notifications page with highlight string; data?: Record<string, any>; action_url?: string | null }) => {
    const type = notification.type?.toLowerCase() || '';
    const data = notification.data || {};

    const isRepairNotification = type.includes('repair') || data.repair_id || data.repair_request_id;
    if (isRepairNotification) {
      const repairId = data.repair_id ?? data.repair_request_id;
      return repairId ? `/my-repairs?highlightRepair=${repairId}` : '/my-repairs';
    }

    const isOrderNotification = type.includes('order') || data.order_id;
    if (isOrderNotification) {
      const orderId = data.order_id;
      return orderId ? `/my-orders?highlightOrder=${orderId}` : '/my-orders';
    }

    return notification.action_url || notificationsListHref;
  };

  return (
    <div
      ref={dropdownRef}
      className="absolute right-0 mt-2 w-96 bg-white rounded-lg shadow-xl border border-gray-200 z-50 max-h-[600px] flex flex-col dark:bg-gray-900 dark:border-gray-700"
    >
      {/* Header */}
      <div className="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700">
        <div className="flex items-center gap-2">
          <Bell size={20} className="text-gray-600 dark:text-gray-300" />
          <h3 className="text-lg font-semibold text-gray-900 dark:text-white">
            Notifications
          </h3>
          {unreadCount > 0 && (
            <span className="px-2 py-1 text-xs font-medium text-white bg-red-500 rounded-full">
              {unreadCount}
            </span>
          )}
        </div>
        
        <div className="flex items-center gap-2">
          <button
            onClick={handleMarkAllAsRead}
            disabled={unreadCount === 0 || markAllAsRead.isPending}
            className="p-1.5 text-gray-500 hover:text-blue-600 hover:bg-gray-100 rounded transition-colors disabled:opacity-40 disabled:cursor-not-allowed dark:text-gray-400 dark:hover:text-blue-400 dark:hover:bg-gray-800"
            title="Mark all as read"
          >
            <CheckCheck size={18} />
          </button>
          <button
            onClick={onClose}
            className="p-1.5 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded transition-colors dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-gray-800"
            title="Close notifications"
          >
            <X size={18} />
          </button>
        </div>
      </div>

      {/* Notifications List */}
      <div className="flex-1 overflow-y-auto">
        {isLoading ? (
          <div className="flex items-center justify-center py-12">
            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
          </div>
        ) : notifications.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-12 text-gray-500 dark:text-gray-400">
            <Bell size={48} className="mb-4 text-gray-300 dark:text-gray-600" />
            <p className="text-sm">No notifications yet</p>
          </div>
        ) : (
          <div className="divide-y divide-gray-100 dark:divide-gray-700">
            {notifications.map((notification) => (
              <NotificationItem
                key={notification.id}
                notification={notification}
                linkHref={getNotificationHref(notification)}
                onMarkAsRead={handleMarkAsRead}
                onClick={onClose}
              />
            ))}
          </div>
        )}
      </div>

      {/* Footer */}
      {notifications.length > 0 && (
        <div className="p-3 border-t border-gray-200 dark:border-gray-700">
          <div className="flex items-center justify-between gap-2">
            <Link
              href={basePath.includes('shop-owner') ? '/shop-owner/notifications/settings' : (basePath.includes('staff') || basePath.includes('hr')) ? '/erp/notifications/settings' : '/notifications/settings'}
              className="flex-1 text-center text-sm font-medium text-gray-600 hover:text-gray-800 transition-colors dark:text-gray-300 dark:hover:text-white"
              onClick={onClose}
            >
              Settings
            </Link>
            <span className="text-gray-300 dark:text-gray-600">|</span>
            <Link
              href={notificationsListHref}
              className="flex-1 text-center text-sm font-medium text-blue-600 hover:text-blue-700 transition-colors"
              onClick={onClose}
            >
              View All
            </Link>
          </div>
        </div>
      )}
    </div>
  );
};

export default NotificationDropdown;
