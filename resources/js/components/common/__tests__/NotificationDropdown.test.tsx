import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import NotificationDropdown from '../NotificationDropdown';

vi.mock('@inertiajs/react', () => ({
  Link: ({ children, ...props }: { children: React.ReactNode; [key: string]: unknown }) => <a {...props}>{children}</a>,
  usePage: () => ({ props: { auth: {} } }),
}));

vi.mock('../../../hooks/useNotifications', () => ({
  useRecentNotifications: () => ({
    data: [{
      id: 1,
      type: 'repair_delivered',
      title: 'Delivered',
      message: 'Shipment leg delivered.',
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
    isLoading: false,
  }),
  useMarkAsRead: () => ({ mutate: vi.fn() }),
  useMarkAllAsRead: () => ({ mutate: vi.fn(), isPending: false }),
}));

vi.mock('../NotificationItem', () => ({
  default: ({ linkHref, notification }: { linkHref?: string; notification?: { title?: string } }) => (
    <a href={linkHref} data-testid="notification-item">{notification?.title}</a>
  ),
}));

vi.mock('../ThemeToggleButton', () => ({
  ThemeToggleButton: () => <button type="button" aria-label="Toggle theme" data-testid="customer-theme-toggle" />,
}));

describe('NotificationDropdown customer palette', () => {
  it('offers customer dark mode beside the mark-all-read action', () => {
    const { container, getByRole, unmount } = render(
      <NotificationDropdown basePath="/api/notifications" onClose={vi.fn()} />,
    );
    const customerPanel = container.firstElementChild as HTMLElement;

    expect(customerPanel).toHaveClass('bg-white');
    expect(customerPanel).toHaveClass('dark:bg-gray-900');
    expect(customerPanel).toHaveClass('dark:border-gray-700');
    expect(getByRole('button', { name: 'Toggle theme' })).toBeInTheDocument();
    expect(getByRole('button', { name: 'Mark all as read' })).toBeInTheDocument();

    unmount();

    const shopOwnerRender = render(
      <NotificationDropdown basePath="/api/shop-owner/notifications" onClose={vi.fn()} />,
    );
    const shopOwnerPanel = shopOwnerRender.container.firstElementChild as HTMLElement;

    expect(shopOwnerPanel).toHaveClass('dark:bg-gray-900');
    expect(shopOwnerPanel).toHaveClass('dark:border-gray-700');
    expect(shopOwnerPanel.querySelector('[aria-label="Toggle theme"]')).toBeNull();
  });

  it('keeps customer delivery reports on the dispatcher shipment page', () => {
    render(<NotificationDropdown basePath="/api/staff/notifications" onClose={vi.fn()} />);

    expect(screen.getByRole('link', { name: 'Delivered' })).toHaveAttribute(
      'href',
      '/erp/staff/job-orders-repair?highlightRepair=1',
    );
    expect(screen.getByRole('link', { name: 'Customer Delivery Report' })).toHaveAttribute(
      'href',
      '/erp/logistics/shipments?status=customer_disputes',
    );
  });
});
