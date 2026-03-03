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
import {
  archiveShopOwnerStaticNotification,
  getShopOwnerStaticNotificationsWithReadState,
  markAllShopOwnerStaticNotificationsAsRead,
  markShopOwnerStaticNotificationAsRead,
  SHOP_OWNER_STATIC_NOTIFICATIONS_EVENT,
} from '../../utils/shopOwnerStaticNotificationState';
import {
  archiveManagerStaticNotification,
  getManagerStaticNotificationsWithReadState,
  MANAGER_STATIC_NOTIFICATIONS_EVENT,
  markAllManagerStaticNotificationsAsRead,
  markManagerStaticNotificationAsRead,
} from '../../utils/managerStaticNotificationState';
import {
  archiveFinanceStaticNotification,
  FINANCE_STATIC_NOTIFICATIONS_EVENT,
  getFinanceStaticNotificationsWithReadState,
  markAllFinanceStaticNotificationsAsRead,
  markFinanceStaticNotificationAsRead,
} from '../../utils/financeStaticNotificationState';
import {
  archiveHrStaticNotification,
  getHrStaticNotificationsWithReadState,
  HR_STATIC_NOTIFICATIONS_EVENT,
  markAllHrStaticNotificationsAsRead,
  markHrStaticNotificationAsRead,
} from '../../utils/hrStaticNotificationState';

interface NotificationDropdownProps {
  basePath: string;
  onClose: () => void;
}

const NotificationDropdown: React.FC<NotificationDropdownProps> = ({ basePath, onClose }) => {
  const { auth } = (usePage().props as any) || {};
  const userRole = auth?.user?.role;
  const normalizedUserRole = String(userRole || '').toUpperCase();
  const dropdownRef = useRef<HTMLDivElement>(null);
  const isShopOwnerStatic = basePath.includes('shop-owner');
  const isManagerStatic = userRole === 'MANAGER' && basePath.includes('/api/hr/notifications');
  const isFinanceStatic = normalizedUserRole.includes('FINANCE') && basePath.includes('/api/hr/notifications');
  const isHrStatic = normalizedUserRole.includes('HR') && !isManagerStatic && !isFinanceStatic && basePath.includes('/api/hr/notifications');
  const [shopOwnerNotifications, setShopOwnerNotifications] = React.useState(() =>
    isShopOwnerStatic ? getShopOwnerStaticNotificationsWithReadState() : []
  );
  const [managerNotifications, setManagerNotifications] = React.useState(() =>
    isManagerStatic ? getManagerStaticNotificationsWithReadState() : []
  );
  const [financeNotifications, setFinanceNotifications] = React.useState(() =>
    isFinanceStatic ? getFinanceStaticNotificationsWithReadState() : []
  );
  const [hrNotifications, setHrNotifications] = React.useState(() =>
    isHrStatic ? getHrStaticNotificationsWithReadState() : []
  );
  const { data: recentNotifications = [], isLoading: isLoadingRecent } = useRecentNotifications(10, basePath);
  const markAsRead = useMarkAsRead(basePath);
  const markAllPath = basePath === '/api/notifications' ? 'read-all' : 'mark-all-read';
  const markAllAsRead = useMarkAllAsRead(basePath, markAllPath);
  const notifications = useMemo(
    () => (isShopOwnerStatic
      ? shopOwnerNotifications
      : isManagerStatic
        ? managerNotifications
        : isFinanceStatic
          ? financeNotifications
          : isHrStatic
            ? hrNotifications
          : recentNotifications),
    [isShopOwnerStatic, shopOwnerNotifications, isManagerStatic, managerNotifications, isFinanceStatic, financeNotifications, isHrStatic, hrNotifications, recentNotifications]
  );
  const isLoading = isShopOwnerStatic || isManagerStatic || isFinanceStatic || isHrStatic ? false : isLoadingRecent;

  useEffect(() => {
    if (!isShopOwnerStatic) return;

    const sync = () => setShopOwnerNotifications(getShopOwnerStaticNotificationsWithReadState());
    sync();

    window.addEventListener(SHOP_OWNER_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
    return () => window.removeEventListener(SHOP_OWNER_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
  }, [isShopOwnerStatic]);

  useEffect(() => {
    if (!isManagerStatic) return;

    const sync = () => setManagerNotifications(getManagerStaticNotificationsWithReadState());
    sync();

    window.addEventListener(MANAGER_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
    return () => window.removeEventListener(MANAGER_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
  }, [isManagerStatic]);

  useEffect(() => {
    if (!isFinanceStatic) return;

    const sync = () => setFinanceNotifications(getFinanceStaticNotificationsWithReadState());
    sync();

    window.addEventListener(FINANCE_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
    return () => window.removeEventListener(FINANCE_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
  }, [isFinanceStatic]);

  useEffect(() => {
    if (!isHrStatic) return;

    const sync = () => setHrNotifications(getHrStaticNotificationsWithReadState());
    sync();

    window.addEventListener(HR_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
    return () => window.removeEventListener(HR_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
  }, [isHrStatic]);

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
    if (isShopOwnerStatic) {
      markShopOwnerStaticNotificationAsRead(id);
      return;
    }
    if (isManagerStatic) {
      markManagerStaticNotificationAsRead(id);
      return;
    }
    if (isFinanceStatic) {
      markFinanceStaticNotificationAsRead(id);
      return;
    }
    if (isHrStatic) {
      markHrStaticNotificationAsRead(id);
      return;
    }
    markAsRead.mutate(id);
  };

  const handleMarkAllAsRead = () => {
    if (isShopOwnerStatic) {
      if (unreadCount === 0) return;
      markAllShopOwnerStaticNotificationsAsRead();
      return;
    }
    if (isManagerStatic) {
      if (unreadCount === 0) return;
      markAllManagerStaticNotificationsAsRead();
      return;
    }
    if (isFinanceStatic) {
      if (unreadCount === 0) return;
      markAllFinanceStaticNotificationsAsRead();
      return;
    }
    if (isHrStatic) {
      if (unreadCount === 0) return;
      markAllHrStaticNotificationsAsRead();
      return;
    }
    if (unreadCount === 0 || markAllAsRead.isPending) return;
    markAllAsRead.mutate();
  };

  const unreadCount = notifications.filter(n => !n.is_read).length;
  const isCustomerView = !basePath.includes('shop-owner') && !basePath.includes('staff') && !basePath.includes('hr');
  const notificationsListHref = basePath.includes('shop-owner')
    ? '/shop-owner/notifications'
    : basePath.includes('staff') || basePath.includes('hr')
      ? '/erp/notifications'
      : '/notifications';
  const archivedListHref = `${notificationsListHref}?archived=1`;

  const getNotificationHref = (notification: { id: number; type?: string; data?: any; action_url?: string | null }) => {
    if (isShopOwnerStatic && notification.action_url) {
      return notification.action_url;
    }
    if (isManagerStatic && notification.action_url) {
      return notification.action_url;
    }
    if (isFinanceStatic && notification.action_url) {
      return notification.action_url;
    }
    if (isHrStatic && notification.action_url) {
      return notification.action_url;
    }

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

    // Default fallback
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

  const getCustomerSegment = (notification: { type?: string; title?: string; message?: string; data?: Record<string, any> }) => {
    const type = notification.type?.toLowerCase() || '';
    const title = notification.title?.toLowerCase() || '';
    const message = notification.message?.toLowerCase() || '';
    const data = notification.data || {};

    if (type.includes('order') || data.order_id || title.includes('order')) return 'orders';
    if (type.includes('repair') || data.repair_id || data.repair_request_id || title.includes('repair')) return 'repairs';
    if (type.includes('message') || data.conversation_id || title.includes('message') || message.includes('chat')) return 'messages';

    return null;
  };

  const customerSections = useMemo(() => {
    if (!isCustomerView) return [] as Array<{ key: string; label: string; items: typeof notifications }>;

    return [
      { key: 'orders', label: 'Order Updates', items: notifications.filter((notification) => getCustomerSegment(notification) === 'orders') },
      { key: 'repairs', label: 'Repair Updates', items: notifications.filter((notification) => getCustomerSegment(notification) === 'repairs') },
      { key: 'messages', label: 'Messages from Shop', items: notifications.filter((notification) => getCustomerSegment(notification) === 'messages') },
    ];
  }, [isCustomerView, notifications]);

  const hasCustomerSectionItems = useMemo(
    () => customerSections.some((section) => section.items.length > 0),
    [customerSections]
  );

  const handleArchiveFromDropdown = async (id: number) => {
    if (isShopOwnerStatic) {
      archiveShopOwnerStaticNotification(id);
      return;
    }
    if (isManagerStatic) {
      archiveManagerStaticNotification(id);
      return;
    }
    if (isFinanceStatic) {
      archiveFinanceStaticNotification(id);
      return;
    }
    if (isHrStatic) {
      archiveHrStaticNotification(id);
      return;
    }

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
      className="absolute right-0 mt-2 w-96 bg-white rounded-xl shadow-xl border border-gray-200 z-50 max-h-[600px] flex flex-col dark:bg-gray-900 dark:border-gray-700"
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
            {isCustomerView ? (
              <>
                {customerSections.map((section) => (
                  section.items.length > 0 ? (
                    <div key={section.key}>
                      <div className="px-4 py-2 bg-gray-50 dark:bg-gray-800/60 text-xs font-semibold tracking-wide uppercase text-gray-600 dark:text-gray-300">
                        {section.label}
                      </div>
                      {section.items.map((notification) => (
                        <NotificationItem
                          key={notification.id}
                          notification={notification}
                          isCustomerView
                          onArchive={handleArchiveFromDropdown}
                          linkHref={getNotificationHref(notification)}
                          onMarkAsRead={handleMarkAsRead}
                          onClick={onClose}
                        />
                      ))}
                    </div>
                  ) : null
                ))}
                {!hasCustomerSectionItems && notifications.map((notification) => (
                  <NotificationItem
                    key={notification.id}
                    notification={notification}
                    isCustomerView
                    onArchive={handleArchiveFromDropdown}
                    linkHref={getNotificationHref(notification)}
                    onMarkAsRead={handleMarkAsRead}
                    onClick={onClose}
                  />
                ))}
              </>
            ) : isShopOwnerStatic || isManagerStatic || isFinanceStatic || isHrStatic ? (
              <>
                {notifications.map((notification) => (
                  <NotificationItem
                    key={notification.id}
                    notification={notification}
                    onArchive={handleArchiveFromDropdown}
                    linkHref={getNotificationHref(notification)}
                    onMarkAsRead={handleMarkAsRead}
                    onClick={onClose}
                  />
                ))}
              </>
            ) : (
              notifications.map((notification) => (
                <NotificationItem
                  key={notification.id}
                  notification={notification}
                  onArchive={handleArchiveFromDropdown}
                  linkHref={getNotificationHref(notification)}
                  onMarkAsRead={handleMarkAsRead}
                  onClick={onClose}
                />
              ))
            )}
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
