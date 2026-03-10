/**
 * NotificationItem Component
 * Individual notification display with actions
 */

import React, { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Clock, AlertCircle, ShoppingCart, Wrench, CreditCard, MessageSquare, Star, Trash2 } from 'lucide-react';
import type { Notification } from '../../hooks/useNotifications';

interface NotificationItemProps {
  notification: Notification;
  onMarkAsRead?: (id: number) => void;
  onArchive?: (id: number) => void;
  onClick?: () => void;
  linkHref?: string;
}

const NotificationItem: React.FC<NotificationItemProps> = ({ 
  notification, 
  onMarkAsRead,
  onArchive,
  onClick,
  linkHref
}) => {
  const { auth } = (usePage().props as any) || {};
  const isShopOwnerView = Boolean(auth?.shop_owner && !auth?.user);
  const isShopOwnerStaticNotification = notification.type?.startsWith('shop_owner_');
  const isManagerStaticNotification = notification.type?.startsWith('manager_');
  const isFinanceStaticNotification = notification.type?.startsWith('finance_');
  const isHrStaticNotification = notification.type?.startsWith('hr_');
  const isCrmStaticNotification = notification.type?.startsWith('crm_');
  const isStaffStaticNotification = notification.type?.startsWith('staff_');
  const isRepairerStaticNotification = notification.type?.startsWith('repairer_');
  const isInventoryStaticNotification = notification.type?.startsWith('inventory_');
  const isProcurementStaticNotification = notification.type?.startsWith('procurement_');
  const isRoleStaticNotification =
    isShopOwnerStaticNotification ||
    isManagerStaticNotification ||
    isFinanceStaticNotification ||
    isHrStaticNotification ||
    isCrmStaticNotification ||
    isStaffStaticNotification ||
    isRepairerStaticNotification ||
    isInventoryStaticNotification ||
    isProcurementStaticNotification;
  const [imageErrored, setImageErrored] = useState(false);

  const normalizePhotoPath = (photoPath?: string | null) => {
    if (!photoPath) return null;
    if (photoPath.startsWith('http://') || photoPath.startsWith('https://') || photoPath.startsWith('/')) {
      return photoPath;
    }
    return `/storage/${photoPath}`;
  };

  const shopProfilePhoto =
    normalizePhotoPath(notification?.data?.shop_profile_photo) ||
    normalizePhotoPath(notification?.data?.profile_photo) ||
    normalizePhotoPath(notification?.data?.avatar) ||
    normalizePhotoPath(auth?.shop_owner?.profile_photo) ||
    '/images/user/owner.jpg';

  const getShopOwnerProfileIcon = () => {
    const iconKey = String(notification?.data?.profile_icon || '').toLowerCase();
    const actionUrl = String(notification?.action_url || '').toLowerCase();

    if (iconKey.includes('supplier_order_monitoring') || actionUrl.includes('/erp/procurement/supplier-order-monitoring')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <rect x="3" y="5" width="12" height="14" rx="2"></rect>
          <path d="M7 9h4"></path>
          <path d="M7 13h4"></path>
          <circle cx="18" cy="10" r="3"></circle>
          <path d="M18 8.5v1.8l1.2.7"></path>
          <path d="M16 18h5"></path>
        </svg>
      );
    }

    if (iconKey.includes('purchase_request') || actionUrl.includes('/erp/procurement/purchase-request')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M5 4h10l4 4v12H5z"></path>
          <path d="M15 4v4h4"></path>
          <path d="M8 13l5-5 2 2-5 5-3 1z"></path>
        </svg>
      );
    }

    if (iconKey.includes('purchase_orders') || actionUrl.includes('/erp/procurement/purchase-orders')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <rect x="3" y="4" width="18" height="16" rx="2"></rect>
          <path d="M7 8h10"></path>
          <path d="M7 12h10"></path>
          <path d="M7 16h6"></path>
          <path d="M16 16l1.5 1.5L20 15"></path>
        </svg>
      );
    }

    if (iconKey.includes('stock_request_approval') || actionUrl.includes('/erp/procurement/stock-request-approval')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <rect x="4" y="3" width="16" height="18" rx="2"></rect>
          <path d="M8 9h8"></path>
          <path d="M8 13h5"></path>
          <path d="M14 15l2 2 3-3"></path>
        </svg>
      );
    }

    if (iconKey.includes('job_orders_retail') || actionUrl.includes('/erp/staff/job-orders')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
          <path d="M6 6h15l-1.5 9h-12z" />
          <circle cx="9" cy="19" r="1.5" />
          <circle cx="18" cy="19" r="1.5" />
          <path d="M6 6L4 2" />
        </svg>
      );
    }

    if (iconKey.includes('product_uploader') || actionUrl.includes('/erp/staff/products')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
          <path d="M3 15h4.5l2.5-3.5h3.5l2.2 2.2c.8.8 1.9 1.3 3 1.3H21a1 1 0 0 1 1 1v1a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-1a2 2 0 0 1 1-1z" />
          <path d="M8 15l1.5 1.5" />
          <path d="M11 15l1.5 1.5" />
        </svg>
      );
    }

    if (iconKey.includes('shoe_pricing') || actionUrl.includes('/erp/staff/shoe-pricing')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
          <line x1="7" y1="7" x2="7.01" y2="7"></line>
        </svg>
      );
    }

    if (iconKey.includes('inventory_overview') || actionUrl.includes('/erp/staff/inventory-overview')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M21 16V8a2 2 0 0 0-1-1.73L12 3 4 6.27A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73L12 21l8-4.27A2 2 0 0 0 21 16z"></path>
          <path d="M12 12v9"></path>
        </svg>
      );
    }

    if (iconKey === 'inventory') {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
          <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
          <path d="M3 9h18"></path>
          <path d="M9 21V9"></path>
        </svg>
      );
    }

    if (iconKey.includes('refund')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1.707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z"></path>
        </svg>
      );
    }

    if (iconKey.includes('price')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0 1 18 0z"></path>
        </svg>
      );
    }

    if (iconKey.includes('job_orders_repair')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M12 2a10 10 0 0 0-3.16 19.49"></path>
          <path d="M12 6v6l4 2"></path>
          <path d="M16 19h6"></path>
          <path d="M19 16v6"></path>
        </svg>
      );
    }

    if (iconKey.includes('repair_pricing')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M7 7h10v10H7z"></path>
          <path d="M9 11h6"></path>
          <path d="M9 14h3"></path>
        </svg>
      );
    }

    if (iconKey.includes('stocks_overview')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M3 3v18h18"></path>
          <path d="M7 15v-4"></path>
          <path d="M12 15V8"></path>
          <path d="M17 15v-6"></path>
        </svg>
      );
    }

    if (iconKey.includes('inventory_dashboard')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M3 3v18h18"></path>
          <path d="M7 15v-3"></path>
          <path d="M12 15V8"></path>
          <path d="M17 15v-6"></path>
        </svg>
      );
    }

    if (iconKey.includes('upload_stocks')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path strokeLinecap="round" strokeLinejoin="round" d="M12 16V4m0 0l-4 4m4-4l4 4" />
          <path strokeLinecap="round" strokeLinejoin="round" d="M20 16.58A5 5 0 0018 7h-1.26A8 8 0 104 16.25" />
          <path strokeLinecap="round" strokeLinejoin="round" d="M8 16l4 4 4-4" />
        </svg>
      );
    }

    if (iconKey.includes('stock_movement')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path strokeLinecap="round" strokeLinejoin="round" d="M3 3v18h18" />
          <path strokeLinecap="round" strokeLinejoin="round" d="M7 16l3-3 3 2 4-5" />
          <path strokeLinecap="round" strokeLinejoin="round" d="M16 10h4" />
        </svg>
      );
    }

    if (iconKey.includes('product_inventory')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <rect x="3" y="4" width="18" height="16" rx="2"></rect>
          <path d="M8 8h8"></path>
          <path d="M8 12h8"></path>
          <path d="M8 16h5"></path>
        </svg>
      );
    }

    if (iconKey.includes('stock_request')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M5 4h10l4 4v12H5z"></path>
          <path d="M15 4v4h4"></path>
          <path d="M8 12h6"></path>
          <path d="M11 9v6"></path>
        </svg>
      );
    }

    if (iconKey.includes('chat')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M4 5h16v10H8l-4 4V5z"></path>
          <path d="M8 9h8"></path>
        </svg>
      );
    }

    if (iconKey.includes('purchase')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M9 12h6M9 16h6M9 8h6"></path>
          <path d="M7 4h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"></path>
        </svg>
      );
    }

    if (iconKey.includes('repair')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0 1 18 0z"></path>
        </svg>
      );
    }

    if (iconKey.includes('invoice')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
          <polyline points="14 2 14 8 20 8"></polyline>
          <line x1="16" y1="13" x2="8" y2="13"></line>
          <line x1="16" y1="17" x2="8" y2="17"></line>
        </svg>
      );
    }

    if (iconKey.includes('my_payslips') || iconKey.includes('payslip') || actionUrl.includes('/erp/my-payslips')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
          <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z" />
        </svg>
      );
    }

    if (iconKey.includes('leave')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"></path>
          <path d="M8 14h8"></path>
          <path d="M9 10h.01"></path>
          <path d="M15 10h.01"></path>
        </svg>
      );
    }

    if (iconKey.includes('overtime')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M12 8v4l3 3"></path>
          <circle cx="12" cy="12" r="9"></circle>
        </svg>
      );
    }

    if (iconKey.includes('support')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M4 6h16v10H7l-3 3V6z"></path>
          <path d="M8 10h8"></path>
          <path d="M8 13h5"></path>
        </svg>
      );
    }

    if (iconKey.includes('review')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M12 17l-5 3 1.5-5.5L4 10.5l5.6-.5L12 5l2.4 5 5.6.5-4.5 4 1.5 5.5z"></path>
        </svg>
      );
    }

    if (iconKey.includes('suspend')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M12 15v2m-6 4h12a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zm10-10V7a4 4 0 0 0-8 0v4h8z"></path>
        </svg>
      );
    }

    return <AlertCircle size={18} />;
  };

  const getCategoryIcon = (type: string) => {
    if (type.includes('pricing') || type.includes('price')) return <CreditCard size={20} />;
    if (type.includes('stock') || type.includes('inventory')) return <ShoppingCart size={20} />;
    if (type.includes('payslip') || type.includes('payroll')) return <CreditCard size={20} />;
    if (type.includes('chat')) return <MessageSquare size={20} />;
    if (type.includes('order')) return <ShoppingCart size={20} />;
    if (type.includes('repair')) return <Wrench size={20} />;
    if (type.includes('payment')) return <CreditCard size={20} />;
    if (type.includes('message')) return <MessageSquare size={20} />;
    if (type.includes('review')) return <Star size={20} />;
    return <AlertCircle size={20} />;
  };

  const getCategoryColor = (type: string) => {
    return 'bg-white text-black border border-gray-200 dark:bg-black dark:text-white dark:border-gray-700';
  };

  const formatTimeAgo = (dateString: string) => {
    const date = new Date(dateString);
    const now = new Date();
    const diffInSeconds = Math.floor((now.getTime() - date.getTime()) / 1000);

    if (diffInSeconds < 60) return 'Just now';
    if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)}m ago`;
    if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)}h ago`;
    if (diffInSeconds < 604800) return `${Math.floor(diffInSeconds / 86400)}d ago`;
    
    return date.toLocaleDateString();
  };

  const handleNotificationClick = () => {
    if (onMarkAsRead && !notification.is_read) {
      onMarkAsRead(notification.id);
    }

    onClick?.();
  };

  const handleArchiveClick = (event: React.MouseEvent) => {
    event.preventDefault();
    event.stopPropagation();
    onArchive?.(notification.id);
  };

  const getCustomerName = () => {
    const data = notification.data || {};
    const message = String(notification.message || '');
    const messageCustomerMatch = message.match(/-\s*([A-Za-z][A-Za-z\s.'-]{2,})$/);

    return (
      data.customer_name ||
      data.customer ||
      data.customerName ||
      data.client_name ||
      data.user_name ||
      (messageCustomerMatch ? messageCustomerMatch[1].trim() : null) ||
      null
    );
  };

  const getDisplayMessage = () => {
    const customerName = getCustomerName();
    if (!customerName) return notification.message;

    const messageLower = String(notification.message || '').toLowerCase();
    const customerLower = String(customerName).toLowerCase();
    if (messageLower.includes(customerLower)) {
      return notification.message;
    }

    return `Customer - ${customerName}: ${notification.message}`;
  };

  const content = (
    <div
      onClick={handleNotificationClick}
      className={`group flex gap-3 p-4 transition-colors ${
        !notification.is_read
          ? 'bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 dark:hover:bg-blue-900/30'
          : 'hover:bg-gray-50 dark:hover:bg-gray-800/70'
      }`}
    >
      {isRoleStaticNotification ? (
        <div className="shrink-0 w-10 h-10 rounded-full bg-white text-black dark:bg-black dark:text-white flex items-center justify-center border border-gray-200 dark:border-gray-700">
          {getShopOwnerProfileIcon()}
        </div>
      ) : isShopOwnerView ? (
        imageErrored ? (
          <div className="shrink-0 w-10 h-10 rounded-full bg-white text-black dark:bg-black dark:text-white flex items-center justify-center border border-gray-200 dark:border-gray-700">
            <AlertCircle size={18} />
          </div>
        ) : (
          <img
            src={shopProfilePhoto}
            alt="Shop profile"
            onError={() => setImageErrored(true)}
            className="shrink-0 w-10 h-10 rounded-full object-cover border border-gray-200 dark:border-gray-700"
          />
        )
      ) : (
        <div className={`shrink-0 w-10 h-10 rounded-full flex items-center justify-center ${getCategoryColor(notification.type)}`}>
          {getCategoryIcon(notification.type)}
        </div>
      )}

      <div className="flex-1 min-w-0">
        <div className="flex items-start justify-between gap-2">
          <h4 className={`text-sm font-medium ${!notification.is_read ? 'text-gray-900 dark:text-white' : 'text-gray-700 dark:text-gray-300'}`}>
            {notification.title}
          </h4>
          <div className="flex items-center gap-2">
            {onArchive && (
              <button
                type="button"
                onClick={handleArchiveClick}
                className="opacity-0 group-hover:opacity-100 p-1 rounded-full text-gray-400 hover:text-red-600 hover:bg-red-50 transition"
                title="Archive notification"
                aria-label="Archive notification"
              >
                <Trash2 size={14} />
              </button>
            )}
            {!notification.is_read && (
              <span className="shrink-0 w-2 h-2 rounded-full mt-1 bg-blue-500"></span>
            )}
          </div>
        </div>

        <p className="text-sm text-gray-600 mt-1 line-clamp-2 dark:text-gray-400">
          {getDisplayMessage()}
        </p>

        <div className="flex items-center gap-3 mt-2">
          <span className="text-xs text-gray-500 flex items-center gap-1 dark:text-gray-400">
            <Clock size={12} />
            {formatTimeAgo(notification.created_at)}
          </span>
        </div>
      </div>
    </div>
  );

  const targetHref = linkHref || notification.action_url;

  if (targetHref) {
    return (
      <Link 
        href={targetHref}
      >
        {content}
      </Link>
    );
  }

  return (
    <div onClick={handleNotificationClick}>
      {content}
    </div>
  );
};

export default NotificationItem;
