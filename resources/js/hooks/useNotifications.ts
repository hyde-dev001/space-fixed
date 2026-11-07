import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNotificationIdentity } from './useNotificationIdentity';

const DEFAULT_NOTIFICATION_API_BASE = '/api/notifications';

export interface Notification {
    id: number;
    type: string;
    title: string;
    message: string;
    data?: any;
    action_url?: string;
    is_read: boolean;
    read_at?: string;
    created_at: string;
    priority?: string;
    group_key?: string;
    requires_action?: boolean;
    is_archived?: boolean;
}

export interface NotificationFilters {
    unread_only?: boolean;
    category?: string;
    action_required?: boolean;
    priority?: string;
    start_date?: string;
    end_date?: string;
    archived?: boolean;
    search?: string;
}

export interface NotificationPreferences {
    preferences?: Record<string, boolean>;
    email_digest_frequency?: 'none' | 'daily' | 'weekly';
    sound_enabled?: boolean;
    quiet_hours_enabled?: boolean;
    quiet_hours_start?: string | null;
    quiet_hours_end?: string | null;
    browser_push_enabled?: boolean;
    auto_archive_enabled?: boolean;
    auto_archive_days?: number | null;
    email_expense_approval?: boolean;
    email_leave_approval?: boolean;
    email_invoice_created?: boolean;
    email_delegation_assigned?: boolean;
    browser_expense_approval?: boolean;
    browser_leave_approval?: boolean;
    browser_invoice_created?: boolean;
    browser_delegation_assigned?: boolean;
}

const normalizeBasePath = (basePath: string) => basePath.replace(/\/$/, '');

const normalizeNotificationListPayload = (payload: any): Notification[] => {
    if (Array.isArray(payload)) {
        return payload as Notification[];
    }

    if (Array.isArray(payload?.data)) {
        return payload.data as Notification[];
    }

    if (Array.isArray(payload?.notifications)) {
        return payload.notifications as Notification[];
    }

    return [];
};

/**
 * Fetch notifications with pagination and filters
 */
export function useNotifications(
    unreadOnly: boolean = false,
    page: number = 1,
    basePath: string = DEFAULT_NOTIFICATION_API_BASE,
    category?: string,
    actionRequired?: boolean,
    priority?: string,
    archived?: boolean,
    startDate?: string,
    endDate?: string,
    search?: string
) {
    const normalizedBasePath = normalizeBasePath(basePath);
    const { identity } = useNotificationIdentity();

    return useQuery({
        enabled: identity !== null,
        queryKey: ['notifications', identity, normalizedBasePath, {
            unreadOnly, page, category, actionRequired, priority, archived, startDate, endDate, search 
        }],
        queryFn: async ({ signal }) => {
            const params = new URLSearchParams({
                page: page.toString(),
                unread_only: unreadOnly.toString(),
            });

            if (category) params.append('category', category);
            if (actionRequired) params.append('requires_action', 'true');
            if (priority) params.append('priority', priority);
            if (archived !== undefined) params.append('archived', archived.toString());
            if (startDate) params.append('start_date', startDate);
            if (endDate) params.append('end_date', endDate);
            if (search) params.append('search', search);

            const response = await fetch(`${normalizedBasePath}?${params}`, {
                credentials: 'include',
                signal,
            });

            if (!response.ok) {
                throw new Error('Failed to fetch notifications');
            }

            const payload = await response.json();

            if (Array.isArray(payload)) {
                return {
                    data: payload,
                    total: payload.length,
                    unread_count: payload.filter((notification: Notification) => !notification?.is_read).length,
                    last_page: 1,
                    from: payload.length > 0 ? 1 : 0,
                    to: payload.length,
                };
            }

            return payload;
        },
        refetchInterval: 10000, // Refresh every 10 seconds for real-time updates
    });
}

/**
 * Fetch unread notification count
 */
export function useUnreadCount(basePath: string = DEFAULT_NOTIFICATION_API_BASE) {
    const normalizedBasePath = normalizeBasePath(basePath);
    const { identity } = useNotificationIdentity();

    return useQuery({
        enabled: identity !== null,
        queryKey: ['notifications', identity, normalizedBasePath, 'unread-count'],
        queryFn: async ({ signal }) => {
            const response = await fetch(`${normalizedBasePath}/unread-count`, {
                credentials: 'include',
                signal,
            });

            if (response.status === 429) {
                return 0;
            }

            if (!response.ok) {
                throw new Error('Failed to fetch unread count');
            }

            const data = await response.json();
            return data.count;
        },
        refetchInterval: 15000, // Refresh every 15 seconds to reduce API throttling
    });
}

/**
 * Fetch recent notifications (for dropdown)
 */
export function useRecentNotifications(
    limit: number = 5,
    basePath: string = DEFAULT_NOTIFICATION_API_BASE
) {
    const normalizedBasePath = normalizeBasePath(basePath);
    const { identity } = useNotificationIdentity();

    return useQuery({
        enabled: identity !== null,
        queryKey: ['notifications', identity, normalizedBasePath, 'recent', limit],
        queryFn: async ({ signal }) => {
            const response = await fetch(`${normalizedBasePath}/recent?limit=${limit}`, {
                credentials: 'include',
                signal,
            });

            if (response.status === 429) {
                return [] as Notification[];
            }

            if (!response.ok) {
                throw new Error('Failed to fetch recent notifications');
            }

            const payload = await response.json();
            return normalizeNotificationListPayload(payload);
        },
        refetchInterval: 15000, // Refresh every 15 seconds to reduce API throttling
    });
}

/**
 * Fetch notification statistics
 */
export function useNotificationStats(basePath: string = DEFAULT_NOTIFICATION_API_BASE) {
    const normalizedBasePath = normalizeBasePath(basePath);
    const { identity } = useNotificationIdentity();

    return useQuery({
        enabled: identity !== null,
        queryKey: ['notifications', identity, normalizedBasePath, 'stats'],
        queryFn: async ({ signal }) => {
            const response = await fetch(`${normalizedBasePath}/stats`, {
                credentials: 'include',
                signal,
            });

            if (!response.ok) {
                throw new Error('Failed to fetch notification stats');
            }

            return response.json();
        },
    });
}

/**
 * Mark notification as read
 */
export function useMarkAsRead(basePath: string = DEFAULT_NOTIFICATION_API_BASE) {
    const queryClient = useQueryClient();
    const normalizedBasePath = normalizeBasePath(basePath);
    const { identity, isCurrent } = useNotificationIdentity();

    return useMutation({
        onMutate: () => identity,
        mutationFn: async (notificationId: number) => {
            if (!isCurrent(identity)) throw new Error('Notification session changed');
            const response = await fetch(`${normalizedBasePath}/${notificationId}/read`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
            });

            if (!response.ok) {
                throw new Error('Failed to mark notification as read');
            }

            return response.json();
        },
        onSuccess: (_data, _variables, requestIdentity) => {
            if (isCurrent(requestIdentity)) queryClient.invalidateQueries({ queryKey: ['notifications', requestIdentity, normalizedBasePath] });
        },
    });
}

/**
 * Mark all notifications as read
 */
export function useMarkAllAsRead(
    basePath: string = DEFAULT_NOTIFICATION_API_BASE,
    markAllPath: string = 'mark-all-read',
) {
    const queryClient = useQueryClient();
    const normalizedBasePath = normalizeBasePath(basePath);
    const normalizedMarkAllPath = markAllPath.replace(/^\//, '');
    const { identity, isCurrent } = useNotificationIdentity();

    return useMutation({
        onMutate: () => identity,
        mutationFn: async () => {
            if (!isCurrent(identity)) throw new Error('Notification session changed');
            const response = await fetch(`${normalizedBasePath}/${normalizedMarkAllPath}`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
            });

            if (!response.ok) {
                throw new Error('Failed to mark all as read');
            }

            return response.json();
        },
        onSuccess: (_data, _variables, requestIdentity) => {
            if (isCurrent(requestIdentity)) queryClient.invalidateQueries({ queryKey: ['notifications', requestIdentity, normalizedBasePath] });
        },
    });
}

/**
 * Delete notification
 */
export function useDeleteNotification(basePath: string = DEFAULT_NOTIFICATION_API_BASE) {
    const queryClient = useQueryClient();
    const normalizedBasePath = normalizeBasePath(basePath);
    const { identity, isCurrent } = useNotificationIdentity();

    return useMutation({
        onMutate: () => identity,
        mutationFn: async (notificationId: number) => {
            if (!isCurrent(identity)) throw new Error('Notification session changed');
            const response = await fetch(`${normalizedBasePath}/${notificationId}`, {
                method: 'DELETE',
                credentials: 'include',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
            });

            if (!response.ok) {
                throw new Error('Failed to delete notification');
            }

            return response.json();
        },
        onSuccess: (_data, _variables, requestIdentity) => {
            if (isCurrent(requestIdentity)) queryClient.invalidateQueries({ queryKey: ['notifications', requestIdentity, normalizedBasePath] });
        },
    });
}

/**
 * Unarchive a notification
 */
export function useUnarchiveNotification(basePath: string = DEFAULT_NOTIFICATION_API_BASE) {
    const queryClient = useQueryClient();
    const normalizedBasePath = normalizeBasePath(basePath);
    const { identity, isCurrent } = useNotificationIdentity();

    return useMutation({
        onMutate: () => identity,
        mutationFn: async (notificationId: number) => {
            if (!isCurrent(identity)) throw new Error('Notification session changed');
            const response = await fetch(`${normalizedBasePath}/${notificationId}/unarchive`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
            });

            if (!response.ok) {
                throw new Error('Failed to unarchive notification');
            }

            return response.json();
        },
        onSuccess: (_data, _variables, requestIdentity) => {
            if (isCurrent(requestIdentity)) queryClient.invalidateQueries({ queryKey: ['notifications', requestIdentity, normalizedBasePath] });
        },
    });
}

/**
 * Fetch notification preferences
 */
export function useNotificationPreferences(basePath: string = DEFAULT_NOTIFICATION_API_BASE) {
    const normalizedBasePath = normalizeBasePath(basePath);
    const { identity } = useNotificationIdentity();

    return useQuery({
        enabled: identity !== null,
        queryKey: ['notification-preferences', identity, normalizedBasePath],
        queryFn: async ({ signal }) => {
            const response = await fetch(`${normalizedBasePath}/preferences`, {
                credentials: 'include',
                signal,
            });

            if (!response.ok) {
                throw new Error('Failed to fetch preferences');
            }

            return response.json() as Promise<NotificationPreferences>;
        },
    });
}

/**
 * Update notification preferences
 */
export function useUpdatePreferences(basePath: string = DEFAULT_NOTIFICATION_API_BASE) {
    const queryClient = useQueryClient();
    const normalizedBasePath = normalizeBasePath(basePath);
    const { identity, isCurrent } = useNotificationIdentity();

    return useMutation({
        mutationFn: async (preferences: Record<string, any>) => {
            if (!isCurrent(identity)) throw new Error('Notification session changed');
            const response = await fetch(`${normalizedBasePath}/preferences`, {
                method: 'PUT',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify(preferences),
            });

            if (!response.ok) {
                throw new Error('Failed to update preferences');
            }

            return response.json();
        },
        onMutate: async () => {
            // Cancel any outgoing refetches to avoid overwriting our update
            await queryClient.cancelQueries({ queryKey: ['notification-preferences', identity, normalizedBasePath] });
            return identity;
        },
        onSuccess: (data, variables, requestIdentity) => {
            if (!isCurrent(requestIdentity)) return;
            const serverPreferences = data && data.preferences ? data.preferences : {};

            queryClient.setQueryData(
                ['notification-preferences', requestIdentity, normalizedBasePath],
                (current: any) => ({
                    ...(current || {}),
                    ...(serverPreferences || {}),
                    ...(variables || {}),
                })
            );
        },
        onSettled: () => {
            // Keep UI stable after toggle; background queries will refresh on next page visit/interval.
        },
    });
}
