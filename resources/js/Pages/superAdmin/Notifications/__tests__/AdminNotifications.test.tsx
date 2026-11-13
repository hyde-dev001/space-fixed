import type { ReactNode } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
  useNotifications: vi.fn(),
  useMarkAsRead: vi.fn(),
  useMarkAllAsRead: vi.fn(),
  useDeleteNotification: vi.fn(),
  markAsRead: vi.fn(),
  markAllAsRead: vi.fn(),
  dismiss: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href, ...props }: { children?: ReactNode; href?: string; [key: string]: unknown }) => (
    <a href={href} {...props}>{children}</a>
  ),
}));

vi.mock('../../../../layout/AppLayout', () => ({
  default: ({ children }: { children?: ReactNode }) => <div>{children}</div>,
}));

vi.mock('../../../../hooks/useNotifications', () => ({
  useNotifications: mocks.useNotifications,
  useMarkAsRead: mocks.useMarkAsRead,
  useMarkAllAsRead: mocks.useMarkAllAsRead,
  useDeleteNotification: mocks.useDeleteNotification,
}));

import AdminNotifications from '../AdminNotifications';

beforeEach(() => {
  mocks.useNotifications.mockReturnValue({
    data: {
      notifications: [
        {
          id: 7,
          type: 'business_upgrade_request_pending',
          title: 'Business upgrade request',
          message: 'A shop owner submitted an upgrade request.',
          action_url: '/admin/business-upgrade-requests?status=pending',
          is_read: false,
          read_at: null,
          created_at: '2026-08-12T10:00:00.000Z',
        },
        {
          id: 8,
          type: 'shop_report_filed',
          title: 'Shop report filed',
          message: 'A shop report is ready for review.',
          action_url: null,
          is_read: true,
          read_at: '2026-08-12T09:00:00.000Z',
          created_at: '2026-08-12T09:00:00.000Z',
        },
      ],
      pagination: {
        current_page: 1,
        last_page: 2,
        total: 3,
      },
      unread_count: 1,
    },
    isLoading: false,
    error: null,
  });
  mocks.useMarkAsRead.mockReturnValue({ mutate: mocks.markAsRead, isPending: false });
  mocks.useMarkAllAsRead.mockReturnValue({ mutate: mocks.markAllAsRead, isPending: false });
  mocks.useDeleteNotification.mockReturnValue({ mutate: mocks.dismiss, isPending: false });
  mocks.markAsRead.mockReset();
  mocks.markAllAsRead.mockReset();
  mocks.dismiss.mockReset();
});

describe('AdminNotifications', () => {
  it('renders real operational rows, unread state, safe action links, and pagination', () => {
    render(<AdminNotifications />);

    expect(screen.getByRole('heading', { name: 'Administrative Notifications' })).toBeInTheDocument();
    expect(screen.getByText('Business upgrade request')).toBeInTheDocument();
    expect(screen.getByText('Shop report filed')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /open notification/i })).toHaveAttribute(
      'href',
      '/admin/business-upgrade-requests?status=pending',
    );
    expect(screen.getByRole('button', { name: /mark all as read/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /next page/i })).toBeInTheDocument();
    expect(screen.queryByText(/announcement|support ticket|communication settings|export/i)).not.toBeInTheDocument();
  });

  it('uses server-confirmed mutations for read, dismiss, and mark-all actions', () => {
    render(<AdminNotifications />);

    fireEvent.click(screen.getByRole('button', { name: /mark notification 7 as read/i }));
    fireEvent.click(screen.getByRole('button', { name: /dismiss notification 7/i }));
    fireEvent.click(screen.getByRole('button', { name: /mark all as read/i }));

    expect(mocks.markAsRead).toHaveBeenCalledWith(7);
    expect(mocks.dismiss).toHaveBeenCalledWith(7);
    expect(mocks.markAllAsRead).toHaveBeenCalledTimes(1);
  });

  it('does not make an unsafe action URL clickable', () => {
    mocks.useNotifications.mockReturnValue({
      data: {
        notifications: [{
          id: 9,
          type: 'review_reported',
          title: 'Unsafe action',
          message: 'This must remain non-linkable.',
          action_url: 'https://external.example/escape',
          is_read: false,
          read_at: null,
          created_at: '2026-08-12T08:00:00.000Z',
        }],
        pagination: { current_page: 1, last_page: 1, total: 1 },
        unread_count: 1,
      },
      isLoading: false,
      error: null,
    });

    render(<AdminNotifications />);

    expect(screen.queryByRole('link', { name: /open notification/i })).not.toBeInTheDocument();
    expect(screen.getByText('Unsafe action')).toBeInTheDocument();
  });

  it('renders loading, empty, and error states without fabricating success', () => {
    mocks.useNotifications.mockReturnValueOnce({ data: undefined, isLoading: true, error: null });
    const loading = render(<AdminNotifications />);
    expect(screen.getByText(/loading notifications/i)).toBeInTheDocument();
    loading.unmount();

    mocks.useNotifications.mockReturnValueOnce({
      data: { notifications: [], pagination: { current_page: 1, last_page: 1, total: 0 }, unread_count: 0 },
      isLoading: false,
      error: null,
    });
    const empty = render(<AdminNotifications />);
    expect(screen.getByText(/no operational notifications/i)).toBeInTheDocument();
    empty.unmount();

    mocks.useNotifications.mockReturnValueOnce({ data: undefined, isLoading: false, error: new Error('offline') });
    render(<AdminNotifications />);
    expect(screen.getByText(/could not load notifications/i)).toBeInTheDocument();
    expect(screen.queryByText(/marked as read|dismissed successfully/i)).not.toBeInTheDocument();
  });
});
