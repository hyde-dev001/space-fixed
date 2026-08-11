import React from 'react';
import { render } from '@testing-library/react';
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
    }],
    isLoading: false,
  }),
  useMarkAsRead: () => ({ mutate: vi.fn() }),
  useMarkAllAsRead: () => ({ mutate: vi.fn(), isPending: false }),
}));

vi.mock('../NotificationItem', () => ({
  default: () => <div data-testid="notification-item" />,
}));

describe('NotificationDropdown customer palette', () => {
  it('keeps customer notifications light while preserving dark shop-owner styling', () => {
    const { container, unmount } = render(
      <NotificationDropdown basePath="/api/notifications" onClose={vi.fn()} />,
    );
    const customerPanel = container.firstElementChild as HTMLElement;

    expect(customerPanel).toHaveClass('bg-white');
    expect(customerPanel).not.toHaveClass('dark:bg-gray-900');
    expect(customerPanel).not.toHaveClass('dark:border-gray-700');

    unmount();

    const shopOwnerRender = render(
      <NotificationDropdown basePath="/api/shop-owner/notifications" onClose={vi.fn()} />,
    );
    const shopOwnerPanel = shopOwnerRender.container.firstElementChild as HTMLElement;

    expect(shopOwnerPanel).toHaveClass('dark:bg-gray-900');
    expect(shopOwnerPanel).toHaveClass('dark:border-gray-700');
  });
});
