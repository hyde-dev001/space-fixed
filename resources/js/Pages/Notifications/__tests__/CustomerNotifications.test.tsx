import React from 'react';
import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import CustomerNotifications from '../CustomerNotifications';
import NotificationList from '../NotificationList';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, ...props }: React.PropsWithChildren<Record<string, unknown>>) => <a {...props}>{children}</a>,
  usePage: () => ({ url: '/notifications', props: {} }),
}));

vi.mock('sweetalert2', () => ({
  default: {
    fire: vi.fn(),
    mixin: vi.fn(() => ({ fire: vi.fn() })),
  },
}));

vi.mock('../../../contexts/CartContext', () => ({
  useCart: () => ({ cartCount: 0, isLoading: false }),
}));

vi.mock('ziggy-js', () => ({
  route: (name: string) => `/${name}`,
}));

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
      }, {
        id: 2,
        type: 'logistics_exception',
        title: 'Customer Delivery Report',
        message: 'A customer reported a delivery issue for order #ORD-123.',
        data: { order_id: 42, order_number: 'ORD-123' },
        action_url: '/erp/logistics/shipments?status=customer_disputes',
        is_read: false,
        created_at: '2026-08-11T09:00:00.000Z',
      }],
      total: 2,
      unread_count: 2,
      last_page: 1,
      from: 1,
      to: 2,
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
  it('keeps the customer notification page dark-aware for the shared user theme', () => {
    const { container } = render(<CustomerNotifications />);
    const page = container.querySelector('.min-h-screen');

    expect(page).toHaveClass('bg-gray-50');
    expect(page).toHaveClass('dark:bg-gray-950');
    expect(page?.querySelectorAll('[class*="dark:"]')).not.toHaveLength(0);
  });

  it('keeps dark-aware styling for shop-owner notification pages', () => {
    const { container } = render(<NotificationList basePath="/api/shop-owner/notifications" />);
    const page = container.querySelector('.min-h-screen');

    expect(page).toHaveClass('dark:bg-gray-950');
  });

  it('keeps customer delivery reports on the dispatcher shipment page', () => {
    const originalLocation = Object.getOwnPropertyDescriptor(window, 'location');
    const location = { href: '/erp/notifications' };
    Object.defineProperty(window, 'location', { configurable: true, value: location });

    try {
      render(<NotificationList basePath="/api/staff/notifications" />);
      fireEvent.click(screen.getByRole('heading', { name: 'Customer Delivery Report' }));

      expect(location.href).toBe('/erp/logistics/shipments?status=customer_disputes');
    } finally {
      if (originalLocation) {
        Object.defineProperty(window, 'location', originalLocation);
      }
    }
  });
});
