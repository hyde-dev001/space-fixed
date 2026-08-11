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
});
