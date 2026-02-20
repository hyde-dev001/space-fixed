/**
 * NotificationDropdown Component
 * Dropdown panel showing recent notifications
 */

import React, { useEffect, useRef } from 'react';
import { Link } from '@inertiajs/react';
import { X, Check, CheckCheck, Bell } from 'lucide-react';
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
  const markAllAsRead = useMarkAllAsRead(basePath, 'mark-all-read');

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
    markAllAsRead.mutate();
  };

  const unreadCount = notifications.filter(n => !n.is_read).length;

  return (
    <div
      ref={dropdownRef}
      className="absolute right-0 mt-2 w-96 bg-white rounded-lg shadow-xl border border-gray-200 z-50 max-h-[600px] flex flex-col"
    >
      {/* Header */}
      <div className="flex items-center justify-between p-4 border-b border-gray-200">
        <div className="flex items-center gap-2">
          <Bell size={20} className="text-gray-600" />
          <h3 className="text-lg font-semibold text-gray-900">
            Notifications
          </h3>
          {unreadCount > 0 && (
            <span className="px-2 py-1 text-xs font-medium text-white bg-red-500 rounded-full">
              {unreadCount}
            </span>
          )}
        </div>
        
        <div className="flex items-center gap-2">
          {unreadCount > 0 && (
            <button
              onClick={handleMarkAllAsRead}
              disabled={markAllAsRead.isPending}
              className="p-1.5 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded transition-colors"
              title="Mark all as read"
            >
              <CheckCheck size={18} />
            </button>
          )}
          <button
            onClick={onClose}
            className="p-1.5 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded transition-colors"
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
          <div className="flex flex-col items-center justify-center py-12 text-gray-500">
            <Bell size={48} className="mb-4 text-gray-300" />
            <p className="text-sm">No notifications yet</p>
          </div>
        ) : (
          <div className="divide-y divide-gray-100">
            {notifications.map((notification) => (
              <NotificationItem
                key={notification.id}
                notification={notification}
                onMarkAsRead={handleMarkAsRead}
                onClick={onClose}
                showActions={true}
              />
            ))}
          </div>
        )}
      </div>

      {/* Footer */}
      {notifications.length > 0 && (
        <div className="p-3 border-t border-gray-200">
          <div className="flex items-center justify-between gap-2">
            <Link
              href={basePath.includes('shop-owner') ? '/shop-owner/notifications/settings' : basePath.includes('hr') ? '/erp/notifications/settings' : '/notifications/settings'}
              className="flex-1 text-center text-sm font-medium text-gray-600 hover:text-gray-800 transition-colors"
              onClick={onClose}
            >
              Settings
            </Link>
            <span className="text-gray-300">|</span>
            <Link
              href={basePath.includes('shop-owner') ? '/shop-owner/notifications' : basePath.includes('hr') ? '/erp/notifications' : '/notifications'}
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
