/**
 * NotificationDropdown Component
 * Dropdown panel showing recent notifications
 */

import React, { useEffect, useMemo, useRef } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { X, Bell, CheckCheck } from 'lucide-react';
import Swal from 'sweetalert2';
import { useRecentNotifications, useMarkAsRead, useMarkAllAsRead } from '../../hooks/useNotifications';
import NotificationItem from './NotificationItem';

interface NotificationDropdownProps {
  basePath: string;
  onClose: () => void;
}

const NotificationDropdown: React.FC<NotificationDropdownProps> = ({ basePath, onClose }) => {
  const page = usePage();
  const { auth } = (page.props as any) || {};
  const dropdownRef = useRef<HTMLDivElement>(null);
  const { data: notifications = [], isLoading } = useRecentNotifications(10, basePath);
  const markAsRead = useMarkAsRead(basePath);
  const markAllPath = 'mark-all-read';
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

  const sortedNotifications = useMemo(() => {
    return [...notifications].sort((a, b) => {
      const timeA = Date.parse(a.created_at || '') || 0;
      const timeB = Date.parse(b.created_at || '') || 0;

      if (timeA !== timeB) {
        return timeB - timeA;
      }

      return b.id - a.id;
    });
  }, [notifications]);

  const unreadCount = sortedNotifications.filter((n) => !n.is_read).length;
  const isCustomerView = !basePath.includes('shop-owner') && !basePath.includes('staff') && !basePath.includes('hr');
  const notificationsListHref = basePath.includes('shop-owner')
    ? '/shop-owner/notifications'
    : basePath.includes('staff') || basePath.includes('hr')
      ? '/erp/notifications'
      : '/notifications';

  const hrDashboardPermissions = [
    'access-hr-dashboard',
    'access-employee-directory',
    'access-attendance-records',
    'access-leave-approvals',
    'access-overtime-approvals',
    'access-payslip-generation',
    'access-view-payslip',
  ];
  const rawGrantedPermissions = Array.isArray(auth?.permissions)
    ? auth.permissions
    : Array.isArray(auth?.user?.permissions)
      ? auth.user.permissions
      : [];
  const grantedPermissions = rawGrantedPermissions.map((permission: unknown) => String(permission));
  const canAccessHrDashboard = hrDashboardPermissions.some((permission) => grantedPermissions.includes(permission));
  const archivedListHref = `${notificationsListHref}?archived=1`;

  const appendQueryParam = (href: string, key: string, value: string | number | null | undefined) => {
    if (value === null || value === undefined || value === '') return href;
    const separator = href.includes('?') ? '&' : '?';
    return `${href}${separator}${key}=${encodeURIComponent(String(value))}`;
  };

  const getStaffRepairHighlightValue = (notification: { id: number; data?: any }) => {
    const data = notification.data || {};
    return data.repair_id || data.repair_request_id || data.request_id || data.order_number || notification.id;
  };

  const getStaffNotificationRoute = (notification: { id: number; type?: string; data?: any; action_url?: string | null }) => {
    const type = String(notification.type || '').toLowerCase();
    const data = notification.data || {};

    const isRepairNotification =
      type.includes('repair') ||
      data.repair_id ||
      data.repair_request_id ||
      data.request_id ||
      data.order_number;

    if (isRepairNotification) {
      const repairHighlight = getStaffRepairHighlightValue(notification);
      return appendQueryParam('/erp/staff/job-orders-repair', 'highlightRepair', repairHighlight);
    }

    if (type.includes('price') || type.includes('pricing')) {
      return '/erp/repairer/pricing-and-services';
    }

    if (type.includes('stock') || type.includes('inventory') || type.includes('low_stock') || type.includes('out_of_stock')) {
      return '/erp/staff/stocks-overview';
    }

    if (type.includes('message') || type.includes('chat') || data.conversation_id) {
      return appendQueryParam('/erp/staff/repairer-support', 'conversation', data.conversation_id);
    }

    if (type.includes('payslip') || type.includes('payroll')) {
      return '/erp/my-payslips';
    }

    return null;
  };

  const getNotificationHref = (notification: { id: number; type?: string; data?: any; action_url?: string | null }) => {
    const notificationType = String(notification.type || '').toLowerCase();
    const notificationData = notification.data || {};
    const isShopOwnerBase = basePath.includes('shop-owner');
    const isRepairNotification =
      notificationType.includes('repair')
      || Boolean(notificationData.repair_id)
      || Boolean(notificationData.repair_request_id);
    const isOrderNotification =
      notificationType.includes('order')
      || Boolean(notificationData.order_id)
      || Boolean(notificationData.order_number);

    if (isShopOwnerBase && notification.action_url && /^\/shop-owner\/orders(?:$|[/?#])/.test(notification.action_url)) {
      if (isRepairNotification) {
        const repairId = notificationData?.repair_id || notificationData?.repair_request_id || notification.id;
        return `/shop-owner/job-orders-repair?highlightRepair=${repairId}`;
      }

      return '/shop-owner/job-orders-retail';
    }

    if (basePath.includes('staff')) {
      const staffRoute = getStaffNotificationRoute(notification);
      if (staffRoute) return staffRoute;

      if (notification.action_url) {
        if (!canAccessHrDashboard && /^\/erp\/hr(?:$|[/?#])/.test(notification.action_url)) {
          return notificationsListHref;
        }

        const repairHighlight = getStaffRepairHighlightValue(notification);
        if (String(notification.type || '').toLowerCase().includes('repair')) {
          return appendQueryParam(notification.action_url, 'highlightRepair', repairHighlight);
        }
        return notification.action_url;
      }
    }

    // Shop owner: repair notifications go to Job Orders Repair page
    if (isShopOwnerBase && isRepairNotification) {
      const repairId = notificationData?.repair_id || notificationData?.repair_request_id || notification.id;
      return `/shop-owner/job-orders-repair?highlightRepair=${repairId}`;
    }

    // Shop owner: retail order notifications go to Job Orders Retail page
    if (isShopOwnerBase && isOrderNotification) {
      return '/shop-owner/job-orders-retail';
    }

    // Use explicit action_url when set (all live DB notifications have this)
    if (notification.action_url) return notification.action_url;

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

    // Default fallback
    const type = notification.type?.toLowerCase() || '';
    const data = notification.data || {};

    const isRepairFallback = type.includes('repair') || data.repair_id || data.repair_request_id;
    if (isRepairFallback) {
      const repairId = data.repair_id ?? data.repair_request_id;
      return repairId ? `/my-repairs?highlightRepair=${repairId}` : '/my-repairs';
    }

    const isOrderFallback = type.includes('order') || data.order_id;
    if (isOrderFallback) {
      const orderId = data.order_id;
      return orderId ? `/my-orders?highlightOrder=${orderId}` : '/my-orders';
    }

    return notificationsListHref;
  };

  const handleArchiveFromDropdown = async (id: number) => {
    const result = await Swal.fire({
      title: 'Archive notification?',
      text: 'This notification will be moved to archives.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Yes, archive it',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#DC2626',
    });

    if (!result.isConfirmed) return;

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const isCustomerApi = basePath.replace(/\/$/, '') === '/api/notifications';

      const response = await fetch(
        isCustomerApi ? `${basePath}/${id}/archive` : `${basePath}/${id}`,
        {
          method: isCustomerApi ? 'POST' : 'DELETE',
          credentials: 'include',
          headers: {
            'X-CSRF-TOKEN': csrfToken,
            'Content-Type': 'application/json',
          },
        }
      );

      if (!response.ok) {
        throw new Error('Failed to archive notification');
      }

      await Swal.fire({
        title: 'Archived!',
        text: 'Notification moved to archives.',
        icon: 'success',
        timer: 1200,
        showConfirmButton: false,
      });

      window.location.href = archivedListHref;
    } catch (error) {
      await Swal.fire({
        title: 'Archive failed',
        text: 'Please try again.',
        icon: 'error',
      });
    }
  };

  return (
    <div
      ref={dropdownRef}
      className="fixed left-2 right-2 top-16 z-70 flex max-h-[calc(100vh-5rem)] flex-col rounded-xl border border-gray-200 bg-white shadow-xl sm:absolute sm:left-auto sm:right-0 sm:top-auto sm:mt-2 sm:w-96 sm:max-h-150 dark:bg-gray-900 dark:border-gray-700"
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
        ) : sortedNotifications.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-12 text-gray-500 dark:text-gray-400">
            <Bell size={48} className="mb-4 text-gray-300 dark:text-gray-600" />
            <p className="text-sm">No notifications yet</p>
          </div>
        ) : (
          <div className="divide-y divide-gray-100 dark:divide-gray-700">
            {sortedNotifications.map((notification) => (
              <NotificationItem
                key={notification.id}
                notification={notification}
                isCustomerView={isCustomerView}
                onArchive={handleArchiveFromDropdown}
                linkHref={getNotificationHref(notification)}
                onMarkAsRead={handleMarkAsRead}
                onClick={onClose}
              />
            ))}
          </div>
        )}
      </div>

      {/* Footer */}
      {sortedNotifications.length > 0 && (
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
