import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';

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
}

export interface NotificationPreferences {
    email_expense_approval: boolean;
    email_leave_approval: boolean;
    email_invoice_created: boolean;
    email_delegation_assigned: boolean;
    browser_expense_approval: boolean;
    browser_leave_approval: boolean;
    browser_invoice_created: boolean;
    browser_delegation_assigned: boolean;
}

/**
 * Fetch notifications with pagination
 */
export function useNotifications(unreadOnly: boolean = false, page: number = 1) {
    return useQuery({
        queryKey: ['notifications', { unreadOnly, page }],
        queryFn: async () => {
            const params = new URLSearchParams({
                page: page.toString(),
                unread_only: unreadOnly.toString(),
            });

            const response = await fetch(`/api/notifications?${params}`, {
                credentials: 'include',
            });

            if (!response.ok) {
                throw new Error('Failed to fetch notifications');
            }

            return response.json();
        },
        refetchInterval: 30000, // Refresh every 30 seconds
    });
}

/**
 * Fetch unread notification count
 */
export function useUnreadCount() {
    return useQuery({
        queryKey: ['notifications', 'unread-count'],
        queryFn: async () => {
            const response = await fetch('/api/notifications/unread-count', {
                credentials: 'include',
            });

            if (!response.ok) {
                throw new Error('Failed to fetch unread count');
            }

            const data = await response.json();
            return data.count;
        },
        refetchInterval: 15000, // Refresh every 15 seconds
    });
}

/**
 * Mark notification as read
 */
export function useMarkAsRead() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (notificationId: number) => {
            const response = await fetch(`/api/notifications/${notificationId}/read`, {
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
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['notifications'] });
        },
    });
}

/**
 * Mark all notifications as read
 */
export function useMarkAllAsRead() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async () => {
            const response = await fetch('/api/notifications/read-all', {
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
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['notifications'] });
        },
    });
}

/**
 * Delete notification
 */
export function useDeleteNotification() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (notificationId: number) => {
            const response = await fetch(`/api/notifications/${notificationId}`, {
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
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['notifications'] });
        },
    });
}

/**
 * Fetch notification preferences
 */
export function useNotificationPreferences() {
    return useQuery({
        queryKey: ['notification-preferences'],
        queryFn: async () => {
            const response = await fetch('/api/notifications/preferences', {
                credentials: 'include',
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
export function useUpdatePreferences() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (preferences: Partial<NotificationPreferences>) => {
            const response = await fetch('/api/notifications/preferences', {
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
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['notification-preferences'] });
        },
    });
}
