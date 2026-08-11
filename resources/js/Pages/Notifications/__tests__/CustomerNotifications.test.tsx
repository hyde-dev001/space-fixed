import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render } from '@testing-library/react';
import CustomerNotifications from '../CustomerNotifications';
import NotificationList from '../NotificationList';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, ...props }: React.PropsWithChildren<Record<string, unknown>>) => <a {...props}>{children}</a>,
  usePage: () => ({ url: '/notifications', props: {} }),
}));

vi.mock('sweetalert2', () => ({ default: { fire: vi.fn() } }));

vi.mock('../../../hooks/useNotifications', () => ({
  useNotifications: () => ({
    data: {
      data: [{
        id: 1,
        type: 'order_updated',
        title: 'Order update',
        message: 'Your order is on the way.',
        data: { order_id: 42 },
        is_read: false,
        created_at: '2026-08-11T08:00:00.000Z',
      }],
      total: 1,
      unread_count: 1,
      last_page: 1,
      from: 1,
      to: 1,
    },
    isLoading: false,
    error: null,
  }),
  useMarkAsRead: () => ({ mutate: vi.fn() }),
  useMarkAllAsRead: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteNotification: () => ({ mutate: vi.fn() }),
  useUnarchiveNotification: () => ({ mutate: vi.fn() }),
}));

vi.mock('../../../Components/common/NotificationItem', () => ({
  default: () => null,
}));

vi.mock('../../../components/Notifications/ExportModal', () => ({
  default: () => null,
}));

describe('CustomerNotifications', () => {
  it('keeps the customer notification page light even when dark mode is active elsewhere', () => {
    const { container } = render(<CustomerNotifications />);
    const page = container.querySelector('.min-h-screen');

    expect(page).toHaveClass('bg-gray-50');
    expect(page).not.toHaveClass('dark:bg-gray-950');
    expect(page?.querySelectorAll('[class*="dark:"]')).toHaveLength(0);
  });

  it('keeps dark-aware styling for shop-owner notification pages', () => {
    const { container } = render(<NotificationList basePath="/api/shop-owner/notifications" />);
    const page = container.querySelector('.min-h-screen');

    expect(page).toHaveClass('dark:bg-gray-950');
  });
});
