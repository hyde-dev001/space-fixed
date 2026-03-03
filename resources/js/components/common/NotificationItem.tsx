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
  const isShopOwnerView = Boolean(auth?.shop_owner);
  const isShopOwnerStaticNotification = notification.type?.startsWith('shop_owner_');
  const isManagerStaticNotification = notification.type?.startsWith('manager_');
  const isFinanceStaticNotification = notification.type?.startsWith('finance_');
  const isHrStaticNotification = notification.type?.startsWith('hr_');
  const isRoleStaticNotification = isShopOwnerStaticNotification || isManagerStaticNotification || isFinanceStaticNotification || isHrStaticNotification;
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

    if (iconKey.includes('inventory')) {
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

    if (iconKey.includes('payslip')) {
      return (
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M21 8V7a2 2 0 0 0-2-2h-4V3H9v2H5a2 2 0 0 0-2 2v1"></path>
          <rect x="3" y="8" width="18" height="13" rx="2"></rect>
          <path d="M16 3v4"></path>
          <path d="M8 3v4"></path>
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
    if (type.includes('order')) return <ShoppingCart size={20} />;
    if (type.includes('repair')) return <Wrench size={20} />;
    if (type.includes('payment')) return <CreditCard size={20} />;
    if (type.includes('message')) return <MessageSquare size={20} />;
    if (type.includes('review')) return <Star size={20} />;
    return <AlertCircle size={20} />;
  };

  const getCategoryColor = (type: string) => {
    if (type.includes('order')) return 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-300';
    if (type.includes('repair')) return 'bg-orange-100 text-orange-600 dark:bg-orange-900/30 dark:text-orange-300';
    if (type.includes('payment')) return 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-300';
    if (type.includes('message')) return 'bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-300';
    if (type.includes('review')) return 'bg-yellow-100 text-yellow-600 dark:bg-yellow-900/30 dark:text-yellow-300';
    return 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300';
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
        <div className="shrink-0 w-10 h-10 rounded-full bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200 flex items-center justify-center border border-gray-200 dark:border-gray-700">
          {getShopOwnerProfileIcon()}
        </div>
      ) : isShopOwnerView ? (
        imageErrored ? (
          <div className="shrink-0 w-10 h-10 rounded-full bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200 flex items-center justify-center border border-gray-200 dark:border-gray-700">
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
          {notification.message}
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
