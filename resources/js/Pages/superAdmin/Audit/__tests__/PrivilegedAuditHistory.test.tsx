import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PrivilegedAuditHistory from '../PrivilegedAuditHistory';

const { getMock } = vi.hoisted(() => ({
  getMock: vi.fn(),
}));

const pageProps = {
  entries: [{
    id: 1,
    event: 'user_suspended',
    event_label: 'User suspended',
    actor: { id: 4, label: 'Ada Admin', role: 'Admin' },
    target: { id: 7, type: 'User', label: 'Ava Customer' },
    outcome: 'Suspended',
    source: 'http',
    ip_address: '198.51.100.2',
    correlation_id: '11111111-1111-4111-8111-111111111111',
    metadata: { reason: 'Policy violation' },
    occurred_at: '2026-08-12T04:00:00Z',
  }],
  filters: {
    event: '',
    actor_id: '',
    target_type: '',
    target_id: '',
    correlation_id: '',
    date_from: '',
    date_to: '',
    per_page: 25,
  },
  pagination: { current_page: 1, last_page: 2, per_page: 25, total: 26 },
  event_options: [{ value: 'user_suspended', label: 'User suspended' }],
  target_type_options: [{ value: 'user', label: 'User' }],
};

vi.mock('@inertiajs/react', () => ({
  Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
  router: { get: getMock },
  usePage: () => ({ props: pageProps }),
}));

vi.mock('../../../../layout/AppLayout', () => ({
  default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

beforeEach(() => {
  getMock.mockReset();
});

describe('PrivilegedAuditHistory', () => {
  it('renders safe event details without export or raw-property controls', () => {
    render(<PrivilegedAuditHistory />);

    expect(screen.getByRole('heading', { name: /privileged audit history/i })).toBeInTheDocument();
    expect(screen.getByRole('cell', { name: /User suspended/ })).toBeInTheDocument();
    expect(screen.getByText('Ada Admin')).toBeInTheDocument();
    expect(screen.getByText('Ava Customer')).toBeInTheDocument();
    expect(screen.getByText('Policy violation')).toBeInTheDocument();
    expect(screen.queryByText(/do not render|raw properties|json/i)).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /export|download|delete/i })).not.toBeInTheDocument();
  });

  it('submits allowlisted filters and preserves them while paging', () => {
    render(<PrivilegedAuditHistory />);

    fireEvent.change(screen.getByLabelText(/event/i), { target: { value: 'user_suspended' } });
    fireEvent.change(screen.getByLabelText(/actor id/i), { target: { value: '4' } });
    fireEvent.click(screen.getByRole('button', { name: /apply filters/i }));

    expect(getMock).toHaveBeenCalledWith(
      '/admin/audit',
      { event: 'user_suspended', actor_id: '4' },
      expect.objectContaining({ preserveState: true, replace: true }),
    );

    fireEvent.click(screen.getByRole('button', { name: /next page/i }));
    expect(getMock).toHaveBeenLastCalledWith(
      '/admin/audit',
      { event: 'user_suspended', actor_id: '4', page: '2' },
      expect.objectContaining({ preserveState: true, replace: true }),
    );
  });

  it('shows an explicit empty state when no entries are available', () => {
    vi.mocked(getMock);
    pageProps.entries = [];
    pageProps.pagination = { current_page: 1, last_page: 1, per_page: 25, total: 0 };

    render(<PrivilegedAuditHistory />);

    expect(screen.getByText(/no privileged audit activity/i)).toBeInTheDocument();
  });
});
