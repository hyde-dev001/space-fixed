/**
 * NotificationBell Component
 * Bell icon with badge showing unread count
 */

import React, { useState } from 'react';
import { Bell } from 'lucide-react';
import { useUnreadCount } from '../../hooks/useNotifications';
import NotificationDropdown from './NotificationDropdown';

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
  const [isOpen, setIsOpen] = useState(false);
  const { data: unreadCount = 0, isLoading } = useUnreadCount(basePath);

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
