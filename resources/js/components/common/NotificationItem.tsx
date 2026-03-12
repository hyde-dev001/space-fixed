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
      className={`group flex gap-3 p-4 transition-colors ${
        !notification.is_read
          ? 'bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 dark:hover:bg-blue-900/30'
          : 'hover:bg-gray-50 dark:hover:bg-gray-800/70'
      }`}
    >
      {isShopOwnerView ? (
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
        onClick={handleNotificationClick}
      >
        {content}
      </Link>
    );
  }

  return (
    <div role="button" className="cursor-pointer" onClick={handleNotificationClick}>
      {content}
    </div>
  );
};

export default NotificationItem;
