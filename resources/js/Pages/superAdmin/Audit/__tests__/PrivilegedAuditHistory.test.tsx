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
    audit_reference: 'AUD-00000001',
    event: 'user_suspended',
    event_label: 'User suspended',
    actor: { id: 4, label: 'Ada Admin', role: 'Admin' },
    target: { id: 7, type: 'User', label: 'Ava Customer' },
    outcome: 'Suspended',
    result: { key: 'completed', label: 'Suspended' },
    source: 'http',
    source_label: 'Web request',
    ip_address: '198.51.100.2',
    correlation_id: '11111111-1111-4111-8111-111111111111',
    metadata: { reason: 'Policy violation' },
    occurred_at: '2026-08-12T04:00:00Z',
  }],
  filters: {
    search: '',
    event: '',
    actor_search: '',
    target_search: '',
    actor_id: '',
    target_type: '',
    target_id: '',
    result: '',
    source: '',
    ip_address: '',
    correlation_id: '',
    date_from: '',
    date_to: '',
    sort: 'newest',
    per_page: 25,
  },
  pagination: { current_page: 1, last_page: 2, per_page: 25, total: 26 },
  event_options: [{ value: 'user_suspended', label: 'User suspended' }],
  target_type_options: [{ value: 'user', label: 'User' }],
  result_options: [
    { value: 'completed', label: 'Completed' },
    { value: 'failed', label: 'Failed' },
  ],
  source_options: [{ value: 'http', label: 'Web request' }],
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
  it('keeps technical identifiers out of the table and exposes them in an accessible details dialog', () => {
    render(<PrivilegedAuditHistory />);

    expect(screen.getByRole('heading', { name: /audit logs/i })).toBeInTheDocument();
    expect(screen.getByRole('columnheader', { name: /activity/i })).toBeInTheDocument();
    expect(screen.getByRole('columnheader', { name: /performed by/i })).toBeInTheDocument();
    expect(screen.getByText('Ada Admin')).toBeInTheDocument();
    expect(screen.getByText('Ava Customer')).toBeInTheDocument();
    expect(screen.queryByText('#4')).not.toBeInTheDocument();
    expect(screen.queryByText('#7')).not.toBeInTheDocument();
    expect(screen.queryByText('198.51.100.2')).not.toBeInTheDocument();
    expect(screen.queryByText('11111111-1111-4111-8111-111111111111')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /export|download|delete/i })).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /view details for user suspended/i }));

    const dialog = screen.getByRole('dialog', { name: /audit details/i });
    expect(dialog).toHaveTextContent('AUD-00000001');
    expect(dialog).toHaveTextContent('User suspended');
    expect(dialog).toHaveTextContent('Policy violation');
    expect(dialog).toHaveTextContent('Actor ID');
    expect(dialog).toHaveTextContent('4');
    expect(dialog).toHaveTextContent('Target type / ID');
    expect(dialog).toHaveTextContent('7');
    expect(dialog).toHaveTextContent('11111111-1111-4111-8111-111111111111');
    expect(dialog).toHaveTextContent('198.51.100.2');
    expect(dialog).not.toHaveTextContent(/raw properties|password|token/i);
  });

  it('applies user-friendly filters on the server and preserves them while paging', () => {
    render(<PrivilegedAuditHistory />);

    fireEvent.change(screen.getByRole('combobox', { name: 'Activity' }), { target: { value: 'user_suspended' } });
    fireEvent.change(screen.getByLabelText('Performer'), { target: { value: 'Ada' } });
    fireEvent.change(screen.getByLabelText('Target item'), { target: { value: 'Ava' } });
    fireEvent.change(screen.getByRole('combobox', { name: 'Result' }), { target: { value: 'completed' } });
    fireEvent.change(screen.getByRole('combobox', { name: 'Access method' }), { target: { value: 'http' } });
    fireEvent.change(screen.getByRole('combobox', { name: 'Sort' }), { target: { value: 'oldest' } });
    fireEvent.click(screen.getByRole('button', { name: /apply filters/i }));

    expect(getMock).toHaveBeenLastCalledWith(
      '/admin/audit',
      {
        event: 'user_suspended',
        actor_search: 'Ada',
        target_search: 'Ava',
        result: 'completed',
        source: 'http',
        sort: 'oldest',
      },
      expect.objectContaining({ preserveState: true, replace: true }),
    );

    fireEvent.click(screen.getByRole('button', { name: /next page/i }));
    expect(getMock).toHaveBeenLastCalledWith(
      '/admin/audit',
      {
        event: 'user_suspended',
        actor_search: 'Ada',
        target_search: 'Ava',
        result: 'completed',
        source: 'http',
        sort: 'oldest',
        page: '2',
      },
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
