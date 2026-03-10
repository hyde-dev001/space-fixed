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
import {
  CRM_STATIC_NOTIFICATIONS_EVENT,
  getCrmStaticNotificationsWithReadState,
} from '../../utils/crmStaticNotificationState';
import {
  getRepairerStaticNotificationsWithReadState,
  REPAIRER_STATIC_NOTIFICATIONS_EVENT,
} from '../../utils/repairerStaticNotificationState';
import {
  getStaffStaticNotificationsWithReadState,
  STAFF_STATIC_NOTIFICATIONS_EVENT,
} from '../../utils/staffStaticNotificationState';
import {
  getInventoryStaticNotificationsWithReadState,
  INVENTORY_STATIC_NOTIFICATIONS_EVENT,
} from '../../utils/inventoryStaticNotificationState';
import {
  getProcurementStaticNotificationsWithReadState,
  PROCUREMENT_STATIC_NOTIFICATIONS_EVENT,
} from '../../utils/procurementStaticNotificationState';

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
  const userRoles = Array.isArray(auth?.user?.roles) ? auth.user.roles.map((role: string) => String(role).toUpperCase()) : [];
  const hasStaffRole = normalizedUserRole.includes('STAFF') || userRoles.includes('STAFF');
  const hasRepairerRole = normalizedUserRole === 'REPAIRER' || userRoles.includes('REPAIRER');
  const [isOpen, setIsOpen] = useState(false);
  const isShopOwnerStatic = basePath.includes('shop-owner');
  const isManagerStatic = normalizedUserRole === 'MANAGER' && basePath.includes('/api/hr/notifications');
  const isFinanceStatic = normalizedUserRole.includes('FINANCE') && basePath.includes('/api/hr/notifications');
  const isHrStatic = normalizedUserRole.includes('HR') && !isManagerStatic && !isFinanceStatic && basePath.includes('/api/hr/notifications');
  const isCrmStatic = normalizedUserRole.includes('CRM') && !isManagerStatic && !isFinanceStatic && !isHrStatic && basePath.includes('/api/hr/notifications');
  const isStaffStatic = hasStaffRole && !hasRepairerRole && basePath.includes('/api/staff/notifications');
  const isInventoryStatic = normalizedUserRole.includes('INVENTORY') && !isManagerStatic && !isFinanceStatic && !isHrStatic && !isCrmStatic && basePath.includes('/api/hr/notifications');
  const isProcurementStatic = normalizedUserRole.includes('PROCUREMENT') && !isManagerStatic && !isFinanceStatic && !isHrStatic && !isCrmStatic && !isInventoryStatic && basePath.includes('/api/hr/notifications');
  const isRepairerStatic = hasRepairerRole && basePath.includes('/api/staff/notifications');
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
  const [crmUnreadCount, setCrmUnreadCount] = useState<number>(() => {
    if (!isCrmStatic) return 0;
    return getCrmStaticNotificationsWithReadState().filter((notification) => !notification.is_read).length;
  });
  const [staffUnreadCount, setStaffUnreadCount] = useState<number>(() => {
    if (!isStaffStatic) return 0;
    return getStaffStaticNotificationsWithReadState().filter((notification) => !notification.is_read).length;
  });
  const [repairerUnreadCount, setRepairerUnreadCount] = useState<number>(() => {
    if (!isRepairerStatic) return 0;
    return getRepairerStaticNotificationsWithReadState().filter((notification) => !notification.is_read).length;
  });
  const [inventoryUnreadCount, setInventoryUnreadCount] = useState<number>(() => {
    if (!isInventoryStatic) return 0;
    return getInventoryStaticNotificationsWithReadState().filter((notification) => !notification.is_read).length;
  });
  const [procurementUnreadCount, setProcurementUnreadCount] = useState<number>(() => {
    if (!isProcurementStatic) return 0;
    return getProcurementStaticNotificationsWithReadState().filter((notification) => !notification.is_read).length;
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
          : isCrmStatic
            ? crmUnreadCount
            : isStaffStatic
              ? staffUnreadCount
            : isInventoryStatic
              ? inventoryUnreadCount
              : isProcurementStatic
                ? procurementUnreadCount
                : isRepairerStatic
                  ? apiUnreadCount + repairerUnreadCount
                  : apiUnreadCount;
  const isLoading = isShopOwnerStatic || isManagerStatic || isFinanceStatic || isHrStatic || isCrmStatic || isStaffStatic || isInventoryStatic || isProcurementStatic ? false : apiUnreadLoading;

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

  React.useEffect(() => {
    if (!isCrmStatic) return;

    const sync = () => {
      const unread = getCrmStaticNotificationsWithReadState().filter((notification) => !notification.is_read).length;
      setCrmUnreadCount(unread);
    };

    sync();
    window.addEventListener(CRM_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
    return () => window.removeEventListener(CRM_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
  }, [isCrmStatic]);

  React.useEffect(() => {
    if (!isStaffStatic) return;

    const sync = () => {
      const unread = getStaffStaticNotificationsWithReadState().filter((notification) => !notification.is_read).length;
      setStaffUnreadCount(unread);
    };

    sync();
    window.addEventListener(STAFF_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
    return () => window.removeEventListener(STAFF_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
  }, [isStaffStatic]);

  React.useEffect(() => {
    if (!isRepairerStatic) return;

    const sync = () => {
      const unread = getRepairerStaticNotificationsWithReadState().filter((notification) => !notification.is_read).length;
      setRepairerUnreadCount(unread);
    };

    sync();
    window.addEventListener(REPAIRER_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
    return () => window.removeEventListener(REPAIRER_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
  }, [isRepairerStatic]);

  React.useEffect(() => {
    if (!isInventoryStatic) return;

    const sync = () => {
      const unread = getInventoryStaticNotificationsWithReadState().filter((notification) => !notification.is_read).length;
      setInventoryUnreadCount(unread);
    };

    sync();
    window.addEventListener(INVENTORY_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
    return () => window.removeEventListener(INVENTORY_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
  }, [isInventoryStatic]);

  React.useEffect(() => {
    if (!isProcurementStatic) return;

    const sync = () => {
      const unread = getProcurementStaticNotificationsWithReadState().filter((notification) => !notification.is_read).length;
      setProcurementUnreadCount(unread);
    };

    sync();
    window.addEventListener(PROCUREMENT_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
    return () => window.removeEventListener(PROCUREMENT_STATIC_NOTIFICATIONS_EVENT, sync as EventListener);
  }, [isProcurementStatic]);

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
