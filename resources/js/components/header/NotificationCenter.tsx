import React, { useState } from 'react';
import { BellIcon, XMarkIcon, TrashIcon } from '@heroicons/react/24/outline';
import { BellIcon as BellSolidIcon } from '@heroicons/react/24/solid';
import {
    useNotifications,
    useUnreadCount,
    useMarkAsRead,
    useDeleteNotification,
    type Notification,
} from '@/hooks/useNotifications';
import { Link } from '@inertiajs/react';
import { resolveNotificationActionUrl } from '@/utils/resolveNotificationActionUrl';

interface NotificationCenterProps {
    apiBasePath?: string;
    viewAllHref?: string;
    containerClassName?: string;
    triggerClassName?: string;
    iconClassName?: string;
    unreadIconClassName?: string;
    badgeClassName?: string;
}

export default function NotificationCenter({
    apiBasePath = '/api/notifications',
    viewAllHref = '/erp/notifications',
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
    const deleteNotification = useDeleteNotification(apiBasePath);

    const notifications = notificationsData?.notifications || [];

    const handleNotificationClick = (notification: Notification) => {
        if (!notification.is_read) {
            markAsRead.mutate(notification.id);
        }

        if (resolveNotificationActionUrl(notification.action_url, notification.type, notification.data)) {
            setIsOpen(false);
        }
    };

    const handleDelete = (e: React.MouseEvent, notificationId: number) => {
        e.stopPropagation();
        deleteNotification.mutate(notificationId);
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

                    <div className="absolute right-0 top-full mt-2 w-96 overflow-hidden rounded-xl border border-gray-200 bg-white text-gray-900 shadow-2xl dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 z-50 max-h-[600px] flex flex-col">
                        <div className="flex items-center justify-between border-b border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                            <h3 className="text-lg font-semibold text-gray-900 dark:text-white">Notifications</h3>
                            <div className="flex items-center gap-2">
                                <button
                                    onClick={() => setIsOpen(false)}
                                    className="rounded p-1 text-gray-500 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-500 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-white"
                                    aria-label="Close notifications"
                                >
                                    <XMarkIcon className="h-5 w-5" />
                                </button>
                            </div>
                        </div>

                        <div className="border-b border-gray-200 bg-white px-4 py-2 dark:border-gray-700 dark:bg-gray-900">
                            <button
                                onClick={() => setShowUnreadOnly(!showUnreadOnly)}
                                className={`text-sm px-3 py-1 rounded-full transition-colors ${
                                    showUnreadOnly
                                        ? 'bg-indigo-100 text-indigo-700 hover:bg-indigo-200 dark:bg-indigo-900/50 dark:text-indigo-200 dark:hover:bg-indigo-900'
                                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700'
                                }`}
                            >
                                {showUnreadOnly ? 'Show All' : 'Unread Only'}
                            </button>
                        </div>

                        <div className="flex-1 overflow-y-auto">
                            {isLoading ? (
                                <div className="p-8 text-center">
                                    <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
                                    <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">Loading...</p>
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
                                        const actionUrl = resolveNotificationActionUrl(
                                            notification.action_url,
                                            notification.type,
                                            notification.data,
                                        );
                                        const NotificationWrapper = actionUrl ? Link : 'div';
                                        const wrapperProps = actionUrl
                                            ? { href: actionUrl }
                                            : {};

                                        return (
                                            <NotificationWrapper
                                                key={notification.id}
                                                {...wrapperProps}
                                                onClick={() => handleNotificationClick(notification)}
                                                className={`block cursor-pointer p-4 transition-colors hover:bg-gray-50 dark:hover:bg-gray-700 ${
                                                    !notification.is_read
                                                        ? 'bg-gray-100 text-gray-900 dark:bg-gray-800 dark:text-gray-100'
                                                        : 'bg-white text-gray-700 dark:bg-gray-900 dark:text-gray-300'
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
                                                                className="flex-shrink-0 rounded p-1 text-gray-500 hover:bg-gray-200 hover:text-red-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-500 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-red-400"
                                                                aria-label={`Delete ${notification.title}`}
                                                            >
                                                                <TrashIcon className="h-4 w-4" />
                                                            </button>
                                                        </div>
                                                        <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">{notification.message}</p>
                                                        <div className="flex items-center gap-2 mt-2">
                                                            <span className="text-xs text-gray-500 dark:text-gray-400">
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
                            <div className="border-t border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
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
