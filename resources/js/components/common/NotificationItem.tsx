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
}

const NotificationItem: React.FC<NotificationItemProps> = ({ 
  notification, 
  onMarkAsRead,
  onClick,
  showActions = false
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
    if (type.includes('order')) return 'bg-blue-100 text-blue-600';
    if (type.includes('repair')) return 'bg-orange-100 text-orange-600';
    if (type.includes('payment')) return 'bg-green-100 text-green-600';
    if (type.includes('message')) return 'bg-purple-100 text-purple-600';
    if (type.includes('review')) return 'bg-yellow-100 text-yellow-600';
    return 'bg-gray-100 text-gray-600';
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

  const content = (
    <div 
      className={`flex gap-3 p-4 transition-colors ${
        !notification.is_read ? 'bg-blue-50 hover:bg-blue-100' : 'hover:bg-gray-50'
      }`}
    >
      {/* Icon */}
      <div className={`flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center ${getCategoryColor(notification.type)}`}>
        {getCategoryIcon(notification.type)}
      </div>

      {/* Content */}
      <div className="flex-1 min-w-0">
        <div className="flex items-start justify-between gap-2">
          <h4 className={`text-sm font-medium ${!notification.is_read ? 'text-gray-900' : 'text-gray-700'}`}>
            {notification.title}
          </h4>
          {!notification.is_read && (
            <span className="flex-shrink-0 w-2 h-2 bg-blue-500 rounded-full mt-1"></span>
          )}
        </div>
        
        <p className="text-sm text-gray-600 mt-1 line-clamp-2">
          {notification.message}
        </p>
        
        <div className="flex items-center gap-3 mt-2">
          <span className="text-xs text-gray-500 flex items-center gap-1">
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

  if (notification.action_url) {
    return (
      <Link 
        href={notification.action_url}
        onClick={onClick}
      >
        {content}
      </Link>
    );
  }

  return content;
};

export default NotificationItem;
