import React, { useState } from 'react';
import { BellIcon, XMarkIcon, CheckIcon, TrashIcon } from '@heroicons/react/24/outline';
import { BellIcon as BellSolidIcon } from '@heroicons/react/24/solid';
import {
    useNotifications,
    useUnreadCount,
    useMarkAsRead,
    useMarkAllAsRead,
    useDeleteNotification,
    type Notification,
} from '@/hooks/useNotifications';
import { Link } from '@inertiajs/react';

interface NotificationCenterProps {
    apiBasePath?: string;
    viewAllHref?: string;
    markAllPath?: string;
    containerClassName?: string;
    triggerClassName?: string;
    iconClassName?: string;
    unreadIconClassName?: string;
    badgeClassName?: string;
}

export default function NotificationCenter({
    apiBasePath = '/api/notifications',
    viewAllHref = '/erp/notifications',
    markAllPath = 'read-all',
    containerClassName = 'relative',
    triggerClassName = 'relative p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors',
    iconClassName = 'h-6 w-6 text-gray-600 dark:text-gray-300',
    unreadIconClassName = 'h-6 w-6 text-indigo-600 dark:text-indigo-400',
    badgeClassName = 'absolute top-1 right-1 flex items-center justify-center h-5 w-5 text-xs font-bold text-white bg-red-500 rounded-full',
}: NotificationCenterProps) {
    const [isOpen, setIsOpen] = useState(false);
    const [showUnreadOnly, setShowUnreadOnly] = useState(false);

    const { data: unreadCount = 0 } = useUnreadCount(apiBasePath);
    const { data: notificationsData, isLoading } = useNotifications(showUnreadOnly, 1, apiBasePath);
    const markAsRead = useMarkAsRead(apiBasePath);
    const markAllAsRead = useMarkAllAsRead(apiBasePath, markAllPath);
    const deleteNotification = useDeleteNotification(apiBasePath);

    const notifications = notificationsData?.notifications || [];

    const handleNotificationClick = (notification: Notification) => {
        if (!notification.is_read) {
            markAsRead.mutate(notification.id);
        }

        if (notification.action_url) {
            setIsOpen(false);
        }
    };

    const handleDelete = (e: React.MouseEvent, notificationId: number) => {
        e.stopPropagation();
        deleteNotification.mutate(notificationId);
    };

    const handleMarkAllAsRead = () => {
        markAllAsRead.mutate();
    };

    const formatTimeAgo = (dateString: string) => {
        const date = new Date(dateString);
        const now = new Date();
        const seconds = Math.floor((now.getTime() - date.getTime()) / 1000);

        if (seconds < 60) return 'just now';
        if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
        if (seconds < 604800) return `${Math.floor(seconds / 86400)}d ago`;
        return date.toLocaleDateString();
    };

    const getNotificationIcon = (type: string) => {
        switch (type) {
            case 'expense_approval':
                return '💰';
            case 'leave_approval':
                return '🏖️';
            case 'invoice_created':
                return '📄';
            case 'delegation_assigned':
                return '👥';
            default:
                return '🔔';
        }
    };

    return (
        <div className={containerClassName}>
            <button
                type="button"
                onClick={() => setIsOpen(!isOpen)}
                className={triggerClassName}
                aria-label="Notifications"
            >
                {unreadCount > 0 ? (
                    <BellSolidIcon className={unreadIconClassName} />
                ) : (
                    <BellIcon className={iconClassName} />
                )}

                {unreadCount > 0 && (
                    <span className={badgeClassName}>
                        {unreadCount > 99 ? '99+' : unreadCount}
                    </span>
                )}
            </button>

            {isOpen && (
                <>
                    <div className="fixed inset-0 z-40" onClick={() => setIsOpen(false)} />

                    <div className="absolute right-0 top-full mt-2 w-96 bg-white dark:bg-gray-800 rounded-xl shadow-2xl border border-gray-200 dark:border-gray-700 z-50 max-h-[600px] flex flex-col">
                        <div className="flex items-center justify-between p-4 border-b border-gray-200 dark:border-gray-700">
                            <h3 className="text-lg font-semibold text-gray-900 dark:text-white">Notifications</h3>
                            <div className="flex items-center gap-2">
                                {unreadCount > 0 && (
                                    <button
                                        onClick={handleMarkAllAsRead}
                                        className="text-sm text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 flex items-center gap-1"
                                    >
                                        <CheckIcon className="h-4 w-4" />
                                        Mark all read
                                    </button>
                                )}
                                <button
                                    onClick={() => setIsOpen(false)}
                                    className="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded"
                                >
                                    <XMarkIcon className="h-5 w-5 text-gray-500" />
                                </button>
                            </div>
                        </div>

                        <div className="px-4 py-2 border-b border-gray-200 dark:border-gray-700">
                            <button
                                onClick={() => setShowUnreadOnly(!showUnreadOnly)}
                                className={`text-sm px-3 py-1 rounded-full transition-colors ${
                                    showUnreadOnly
                                        ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300'
                                        : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300'
                                }`}
                            >
                                {showUnreadOnly ? 'Show All' : 'Unread Only'}
                            </button>
                        </div>

                        <div className="flex-1 overflow-y-auto">
                            {isLoading ? (
                                <div className="p-8 text-center">
                                    <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
                                    <p className="mt-2 text-sm text-gray-500">Loading...</p>
                                </div>
                            ) : notifications.length === 0 ? (
                                <div className="p-8 text-center">
                                    <BellIcon className="h-12 w-12 text-gray-300 dark:text-gray-600 mx-auto mb-3" />
                                    <p className="text-gray-500 dark:text-gray-400">
                                        {showUnreadOnly ? 'No unread notifications' : 'No notifications yet'}
                                    </p>
                                </div>
                            ) : (
                                <div className="divide-y divide-gray-200 dark:divide-gray-700">
                                    {notifications.map((notification: Notification) => {
                                        const NotificationWrapper = notification.action_url ? Link : 'div';
                                        const wrapperProps = notification.action_url
                                            ? { href: notification.action_url }
                                            : {};

                                        return (
                                            <NotificationWrapper
                                                key={notification.id}
                                                {...wrapperProps}
                                                onClick={() => handleNotificationClick(notification)}
                                                className={`block p-4 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors cursor-pointer ${
                                                    !notification.is_read ? 'bg-indigo-50 dark:bg-indigo-900/20' : ''
                                                }`}
                                            >
                                                <div className="flex items-start gap-3">
                                                    <div className="flex-shrink-0 text-2xl">{getNotificationIcon(notification.type)}</div>

                                                    <div className="flex-1 min-w-0">
                                                        <div className="flex items-start justify-between gap-2">
                                                            <h4
                                                                className={`text-sm font-medium ${
                                                                    !notification.is_read
                                                                        ? 'text-gray-900 dark:text-white'
                                                                        : 'text-gray-700 dark:text-gray-300'
                                                                }`}
                                                            >
                                                                {notification.title}
                                                            </h4>
                                                            <button
                                                                onClick={(e) => handleDelete(e, notification.id)}
                                                                className="flex-shrink-0 p-1 hover:bg-gray-200 dark:hover:bg-gray-600 rounded"
                                                            >
                                                                <TrashIcon className="h-4 w-4 text-gray-400 hover:text-red-500" />
                                                            </button>
                                                        </div>
                                                        <p className="text-sm text-gray-600 dark:text-gray-400 mt-1">{notification.message}</p>
                                                        <div className="flex items-center gap-2 mt-2">
                                                            <span className="text-xs text-gray-500 dark:text-gray-500">
                                                                {formatTimeAgo(notification.created_at)}
                                                            </span>
                                                            {!notification.is_read && (
                                                                <span className="h-2 w-2 bg-indigo-600 rounded-full"></span>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                            </NotificationWrapper>
                                        );
                                    })}
                                </div>
                            )}
                        </div>

                        {notifications.length > 0 && (
                            <div className="p-3 border-t border-gray-200 dark:border-gray-700">
                                <Link
                                    href={viewAllHref}
                                    className="block text-center text-sm text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 font-medium"
                                    onClick={() => setIsOpen(false)}
                                >
                                    View All Notifications
                                </Link>
                            </div>
                        )}
                    </div>
                </>
            )}
        </div>
    );
}
