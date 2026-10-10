import { useMemo, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '../../../layout/AppLayout';
import {
  useDeleteNotification,
  useMarkAllAsRead,
  useMarkAsRead,
  useNotifications,
} from '../../../hooks/useNotifications';

const ADMIN_NOTIFICATION_API = '/api/admin/notifications';

interface AdminNotification {
  id: number;
  type: string;
  title: string;
  message: string;
  action_url: string | null;
  is_read: boolean;
  read_at: string | null;
  created_at: string;
}

interface AdminNotificationPayload {
  notifications: AdminNotification[];
  pagination: {
    current_page: number;
    last_page: number;
    total: number;
  };
  unread_count: number;
}

const isSafeActionUrl = (value: string | null | undefined): value is string => (
  typeof value === 'string'
  && value.length > 0
  && value.startsWith('/')
  && !value.startsWith('//')
  && !value.includes('\\')
  && !/[\u0000-\u001F\u007F]/.test(value)
  && !/%(?![0-9a-fA-F]{2})/.test(value)
);

const formatDate = (value: string): string => {
  const date = new Date(value);

  return Number.isNaN(date.getTime()) ? 'Unknown time' : date.toLocaleString();
};

export default function AdminNotifications() {
  const [unreadOnly, setUnreadOnly] = useState(false);
  const [page, setPage] = useState(1);
  const notificationQuery = useNotifications(unreadOnly, page, ADMIN_NOTIFICATION_API);
  const markAsRead = useMarkAsRead(ADMIN_NOTIFICATION_API);
  const markAllAsRead = useMarkAllAsRead(ADMIN_NOTIFICATION_API, 'read-all');
  const dismiss = useDeleteNotification(ADMIN_NOTIFICATION_API);
  const payload = notificationQuery.data as AdminNotificationPayload | undefined;
  const notifications = useMemo(
    () => (Array.isArray(payload?.notifications) ? payload.notifications : []),
    [payload?.notifications],
  );
  const pagination = payload?.pagination;
  const unreadCount = payload?.unread_count ?? 0;

  const handleUnreadOnlyChange = (checked: boolean) => {
    setUnreadOnly(checked);
    setPage(1);
  };

  return (
    <AppLayout>
      <Head title="Administrative Notifications" />

      <div className="space-y-6">
        <header>
          <h1 className="text-3xl font-bold text-gray-900 dark:text-white">Administrative Notifications</h1>
          <p className="mt-2 text-gray-600 dark:text-gray-400">
            Operational notifications for your privileged account.
          </p>
        </header>

        <section className="flex flex-col gap-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-white/[0.03] sm:flex-row sm:items-center sm:justify-between">
          <label className="inline-flex items-center gap-3 text-sm text-gray-700 dark:text-gray-300">
            <input
              type="checkbox"
              checked={unreadOnly}
              onChange={(event) => handleUnreadOnlyChange(event.target.checked)}
            />
            Unread only
            <span className="text-gray-500 dark:text-gray-400">({unreadCount})</span>
          </label>
          <button
            type="button"
            onClick={() => markAllAsRead.mutate()}
            disabled={markAllAsRead.isPending || unreadCount === 0}
            className="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {markAllAsRead.isPending ? 'Marking as read…' : 'Mark all as read'}
          </button>
        </section>

        {notificationQuery.isLoading && (
          <p className="rounded-xl border border-gray-200 bg-white p-6 text-gray-600 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-400">
            Loading notifications…
          </p>
        )}

        {!notificationQuery.isLoading && notificationQuery.error && (
          <p role="alert" className="rounded-xl border border-red-200 bg-red-50 p-6 text-red-700 dark:border-red-900/50 dark:bg-red-950/20 dark:text-red-300">
            Could not load notifications. Please try again.
          </p>
        )}

        {!notificationQuery.isLoading && !notificationQuery.error && notifications.length === 0 && (
          <p className="rounded-xl border border-gray-200 bg-white p-6 text-gray-600 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-400">
            No operational notifications.
          </p>
        )}

        {!notificationQuery.isLoading && !notificationQuery.error && notifications.length > 0 && (
          <div className="space-y-3">
            {notifications.map((notification) => (
              <article
                key={notification.id}
                className={`rounded-xl border bg-white p-5 shadow-sm dark:bg-white/[0.03] ${
                  notification.is_read
                    ? 'border-gray-200 dark:border-gray-800'
                    : 'border-brand-200 dark:border-brand-900/50'
                }`}
              >
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <h2 className="text-lg font-semibold text-gray-900 dark:text-white">{notification.title}</h2>
                      {!notification.is_read && (
                        <span className="rounded-full bg-brand-100 px-2 py-1 text-xs font-medium text-brand-700 dark:bg-brand-900/40 dark:text-brand-200">
                          Unread
                        </span>
                      )}
                    </div>
                    <p className="mt-2 text-sm text-gray-700 dark:text-gray-300">{notification.message}</p>
                    <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">
                      {formatDate(notification.created_at)}
                    </p>
                  </div>

                  <div className="flex shrink-0 flex-wrap gap-2">
                    {!notification.is_read && (
                      <button
                        type="button"
                        onClick={() => markAsRead.mutate(notification.id)}
                        disabled={markAsRead.isPending}
                        className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
                        aria-label={`Mark notification ${notification.id} as read`}
                      >
                        Mark as read
                      </button>
                    )}
                    {isSafeActionUrl(notification.action_url) && (
                      <Link
                        href={notification.action_url}
                        className="rounded-lg border border-brand-300 px-3 py-2 text-sm font-medium text-brand-700 hover:bg-brand-50 dark:border-brand-700 dark:text-brand-200 dark:hover:bg-brand-900/20"
                      >
                        Open notification
                      </Link>
                    )}
                    <button
                      type="button"
                      onClick={() => dismiss.mutate(notification.id)}
                      disabled={dismiss.isPending}
                      className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
                      aria-label={`Dismiss notification ${notification.id}`}
                    >
                      Dismiss
                    </button>
                  </div>
                </div>
              </article>
            ))}
          </div>
        )}

        {pagination && pagination.last_page > 1 && (
          <nav className="flex items-center justify-between" aria-label="Notification pagination">
            <button
              type="button"
              onClick={() => setPage((currentPage) => Math.max(1, currentPage - 1))}
              disabled={pagination.current_page <= 1}
              className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
              aria-label="Previous page"
            >
              Previous
            </button>
            <span className="text-sm text-gray-600 dark:text-gray-400">
              Page {pagination.current_page} of {pagination.last_page}
            </span>
            <button
              type="button"
              onClick={() => setPage((currentPage) => Math.min(pagination.last_page, currentPage + 1))}
              disabled={pagination.current_page >= pagination.last_page}
              className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
              aria-label="Next page"
            >
              Next
            </button>
          </nav>
        )}
      </div>
    </AppLayout>
  );
}
