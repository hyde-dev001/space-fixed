/**
 * NotificationBell Component
 * Bell icon with badge showing unread count
 */

import React, { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { useUnreadCount } from '../../hooks/useNotifications';
import NotificationDropdown from './NotificationDropdown';
import {
  getShopOwnerStaticNotificationsWithReadState,
  SHOP_OWNER_STATIC_NOTIFICATIONS_EVENT,
} from '../../utils/shopOwnerStaticNotificationState';
import {
  getManagerStaticNotificationsWithReadState,
  MANAGER_STATIC_NOTIFICATIONS_EVENT,
} from '../../utils/managerStaticNotificationState';
import {
  FINANCE_STATIC_NOTIFICATIONS_EVENT,
  getFinanceStaticNotificationsWithReadState,
} from '../../utils/financeStaticNotificationState';
import {
  getHrStaticNotificationsWithReadState,
  HR_STATIC_NOTIFICATIONS_EVENT,
} from '../../utils/hrStaticNotificationState';

interface NotificationBellProps {
  basePath?: string;
  className?: string;
  iconSize?: number;
}

const NotificationBell: React.FC<NotificationBellProps> = ({ 
  basePath = '/api/notifications',
  className = '',
  iconSize = 24
}) => {
  const { auth } = (usePage().props as any) || {};
  const userRole = auth?.user?.role;
  const normalizedUserRole = String(userRole || '').toUpperCase();
  const [isOpen, setIsOpen] = useState(false);
  const isShopOwnerStatic = basePath.includes('shop-owner');
  const isManagerStatic = userRole === 'MANAGER' && basePath.includes('/api/hr/notifications');
  const isFinanceStatic = normalizedUserRole.includes('FINANCE') && basePath.includes('/api/hr/notifications');
  const isHrStatic = normalizedUserRole.includes('HR') && !isManagerStatic && !isFinanceStatic && basePath.includes('/api/hr/notifications');
  const [shopOwnerUnreadCount, setShopOwnerUnreadCount] = useState<number>(() => {
    if (!isShopOwnerStatic) return 0;
    return getShopOwnerStaticNotificationsWithReadState().filter((notification) => !notification.is_read).length;
  });
  const [managerUnreadCount, setManagerUnreadCount] = useState<number>(() => {
    if (!isManagerStatic) return 0;
    return getManagerStaticNotificationsWithReadState().filter((notification) => !notification.is_read).length;
  });
  const [financeUnreadCount, setFinanceUnreadCount] = useState<number>(() => {
    if (!isFinanceStatic) return 0;
    return getFinanceStaticNotificationsWithReadState().filter((notification) => !notification.is_read).length;
  });
  const [hrUnreadCount, setHrUnreadCount] = useState<number>(() => {
    if (!isHrStatic) return 0;
    return getHrStaticNotificationsWithReadState().filter((notification) => !notification.is_read).length;
  });
  const { data: apiUnreadCount = 0, isLoading: apiUnreadLoading } = useUnreadCount(basePath);
  const unreadCount = isShopOwnerStatic
    ? shopOwnerUnreadCount
    : isManagerStatic
      ? managerUnreadCount
      : isFinanceStatic
        ? financeUnreadCount
        : isHrStatic
          ? hrUnreadCount
      : apiUnreadCount;
  const isLoading = isShopOwnerStatic || isManagerStatic || isFinanceStatic || isHrStatic ? false : apiUnreadLoading;

  React.useEffect(() => {
    if (!isShopOwnerStatic) return;

    const sync = () => {
      const unread = getShopOwnerStaticNotificationsWithReadState().filter((notification) => !notification.is_read).length;
      setShopOwnerUnreadCount(unread);
    };

    sync();
    window.addEventListener(SHOP_OWNER_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
    return () => window.removeEventListener(SHOP_OWNER_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
  }, [isShopOwnerStatic]);

  React.useEffect(() => {
    if (!isManagerStatic) return;

    const sync = () => {
      const unread = getManagerStaticNotificationsWithReadState().filter((notification) => !notification.is_read).length;
      setManagerUnreadCount(unread);
    };

    sync();
    window.addEventListener(MANAGER_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
    return () => window.removeEventListener(MANAGER_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
  }, [isManagerStatic]);

  React.useEffect(() => {
    if (!isFinanceStatic) return;

    const sync = () => {
      const unread = getFinanceStaticNotificationsWithReadState().filter((notification) => !notification.is_read).length;
      setFinanceUnreadCount(unread);
    };

    sync();
    window.addEventListener(FINANCE_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
    return () => window.removeEventListener(FINANCE_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
  }, [isFinanceStatic]);

  React.useEffect(() => {
    if (!isHrStatic) return;

    const sync = () => {
      const unread = getHrStaticNotificationsWithReadState().filter((notification) => !notification.is_read).length;
      setHrUnreadCount(unread);
    };

    sync();
    window.addEventListener(HR_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
    return () => window.removeEventListener(HR_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
  }, [isHrStatic]);

  const handleToggle = () => {
    setIsOpen(!isOpen);
  };

  return (
    <div className="relative">
      <button
        onClick={handleToggle}
        className={`relative inline-flex h-10 w-10 shrink-0 items-center justify-center p-0 leading-none text-black transition-opacity hover:opacity-70 dark:text-gray-200 ${className}`}
        aria-label="Notifications"
      >
        <Bell size={iconSize} className="block h-5 w-5 shrink-0" />
        
        {!isLoading && unreadCount > 0 && (
          <span className="absolute top-0 right-0 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white transform translate-x-1/2 -translate-y-1/2 bg-red-500 rounded-full">
            {unreadCount > 99 ? '99+' : unreadCount}
          </span>
        )}
      </button>

      {isOpen && (
        <NotificationDropdown 
          basePath={basePath}
          onClose={() => setIsOpen(false)}
        />
      )}
    </div>
  );
};

export default NotificationBell;
