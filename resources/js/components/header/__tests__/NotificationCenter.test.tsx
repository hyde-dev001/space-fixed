import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import NotificationCenter from '../NotificationCenter';

const markRead = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { super_admin: { id: 1 } } } }),
    Link: ({ children, ...props }: { children: React.ReactNode; [key: string]: unknown }) => (
        <a {...props}>{children}</a>
    ),
}));

vi.mock('@/hooks/useNotifications', () => ({
    useNotifications: () => ({
        data: {
            notifications: [
                {
                    id: 1,
                    type: 'business_upgrade_request',
                    title: 'Business upgrade request',
                    message: 'Alvares Services submitted a request for review.',
                    action_url: '/admin/business-upgrade-requests',
                    is_read: false,
                    created_at: '2026-09-10T08:00:00.000Z',
                },
                {
                    id: 2,
                    type: 'suspension_appeal_submitted',
                    title: 'Suspension appeal submitted',
                    message: 'Customer submitted an appeal.',
                    action_url: '/admin/appeals',
                    is_read: false,
                    created_at: '2026-10-06T00:00:00.000Z',
                },
            ],
        },
        isLoading: false,
    }),
    useUnreadCount: () => ({ data: 1 }),
    useMarkAsRead: () => ({ mutate: markRead }),
    useDeleteNotification: () => ({ mutate: vi.fn() }),
}));

describe('NotificationCenter palette', () => {
    it('links suspension appeals to the authenticated management page and marks them read on click', () => {
        render(<NotificationCenter apiBasePath="/api/admin/notifications" />);
        fireEvent.click(screen.getByRole('button', { name: 'Notifications' }));
        const link = screen.getByText('Suspension appeal submitted').closest('a');
        expect(link).toHaveAttribute('href', '/admin/appeals');
        fireEvent.click(link!);
        expect(markRead).toHaveBeenCalledWith(2);
    });

    it('renders explicit readable light and dark contrast classes', () => {
        render(<NotificationCenter apiBasePath="/api/admin/notifications" />);
        fireEvent.click(screen.getByRole('button', { name: 'Notifications' }));

        const title = screen.getByText('Business upgrade request');
        const row = title.closest('a');
        const panel = title.closest('[class*="absolute"]');

        expect(panel).toHaveClass('bg-white', 'text-gray-900', 'dark:bg-gray-900', 'dark:text-gray-100');
        expect(row).toHaveClass('bg-gray-100', 'dark:bg-gray-800', 'text-gray-900', 'dark:text-gray-100');
        expect(title).toHaveClass('text-gray-900', 'dark:text-white');
        expect(screen.getByText('Alvares Services submitted a request for review.')).toHaveClass(
            'text-gray-600',
            'dark:text-gray-300',
        );
    });
});
