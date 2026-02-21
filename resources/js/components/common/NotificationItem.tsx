/**
 * NotificationItem Component
 * Individual notification display with actions
 */

import React from 'react';
import { Link } from '@inertiajs/react';
import { Check, Clock, AlertCircle, ShoppingCart, Wrench, CreditCard, MessageSquare, Star } from 'lucide-react';
import type { Notification } from '../../hooks/useNotifications';

interface NotificationItemProps {
  notification: Notification;
  onMarkAsRead?: (id: number) => void;
  onClick?: () => void;
  showActions?: boolean;
  linkHref?: string;
}

const NotificationItem: React.FC<NotificationItemProps> = ({ 
  notification, 
  onMarkAsRead,
  onClick,
  showActions = false,
  linkHref
}) => {
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

  const handleMarkAsRead = (e: React.MouseEvent) => {
    e.preventDefault();
    e.stopPropagation();
    if (onMarkAsRead && !notification.is_read) {
      onMarkAsRead(notification.id);
    }
  };

  const handleNotificationClick = () => {
    if (onMarkAsRead && !notification.is_read) {
      onMarkAsRead(notification.id);
    }

    onClick?.();
  };

  const content = (
    <div 
      className={`flex gap-3 p-4 transition-colors ${
        !notification.is_read ? 'bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 dark:hover:bg-blue-900/30' : 'hover:bg-gray-50 dark:hover:bg-gray-800/70'
      }`}
    >
      {/* Icon */}
      <div className={`flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center ${getCategoryColor(notification.type)}`}>
        {getCategoryIcon(notification.type)}
      </div>

      {/* Content */}
      <div className="flex-1 min-w-0">
        <div className="flex items-start justify-between gap-2">
          <h4 className={`text-sm font-medium ${!notification.is_read ? 'text-gray-900 dark:text-white' : 'text-gray-700 dark:text-gray-300'}`}>
            {notification.title}
          </h4>
          {!notification.is_read && (
            <span className="flex-shrink-0 w-2 h-2 bg-blue-500 rounded-full mt-1"></span>
          )}
        </div>
        
        <p className="text-sm text-gray-600 mt-1 line-clamp-2 dark:text-gray-400">
          {notification.message}
        </p>
        
        <div className="flex items-center gap-3 mt-2">
          <span className="text-xs text-gray-500 flex items-center gap-1 dark:text-gray-400">
            <Clock size={12} />
            {formatTimeAgo(notification.created_at)}
          </span>
          
          {showActions && !notification.is_read && onMarkAsRead && (
            <button
              onClick={handleMarkAsRead}
              className="text-xs text-blue-600 hover:text-blue-700 flex items-center gap-1 font-medium"
            >
              <Check size={12} />
              Mark as read
            </button>
          )}
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
    <div onClick={handleNotificationClick}>
      {content}
    </div>
  );
};

export default NotificationItem;
