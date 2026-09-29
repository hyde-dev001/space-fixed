import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SuspensionAppeals from '../SuspensionAppeals';

const { postMock, getMock, swalFireMock } = vi.hoisted(() => ({
  postMock: vi.fn(),
  getMock: vi.fn(),
  swalFireMock: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
  router: { post: postMock, get: getMock },
}));

vi.mock('sweetalert2', () => ({
  default: { fire: swalFireMock },
}));

vi.mock('../../../../layout/AppLayout', () => ({
  default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

const appeal = (overrides: Record<string, unknown> = {}) => ({
  id: 10,
  account_type: 'customer' as const,
  account_id: 22,
  account_name: 'Customer One',
  recipient_email: 'customer@example.test',
  suspension_reason: 'Repeated policy violations.',
  status: 'submitted' as const,
  persisted_status: 'submitted',
  state: 'submitted',
  current: true,
  actionable: true,
  suspension_id: 44,
  appeal_message: 'I understand the policy concerns and have completed corrective actions.',
  reviewer_notes: null,
  submitted_at: '2026-08-12 12:00:00',
  reviewed_at: null,
  expires_at: '2026-08-19 12:00:00',
  created_at: '2026-08-12 11:00:00',
  ...overrides,
});

const stats = {
  total: 4,
  eligible: 0,
  submitted: 1,
  approved: 0,
  rejected: 0,
  expired: 1,
  superseded: 1,
  stale: 1,
};

beforeEach(() => {
  postMock.mockReset();
  getMock.mockReset();
  swalFireMock.mockReset();
  swalFireMock.mockResolvedValue({ isConfirmed: true });
  postMock.mockImplementation((_url: string, _data: unknown, options: { onSuccess?: () => void }) => {
    options.onSuccess?.();
  });
});

describe('Suspension appeal queue state UI', () => {
  it('shows current, stale, expired, and superseded states distinctly', () => {
    render(
      <SuspensionAppeals
        appeals={[
          appeal(),
          appeal({ id: 11, status: 'submitted', state: 'stale', current: false, actionable: false }),
          appeal({ id: 12, status: 'expired', persisted_status: 'eligible', state: 'expired', current: false, actionable: false }),
          appeal({ id: 13, status: 'superseded', persisted_status: 'superseded', state: 'superseded', current: false, actionable: false }),
        ]}
        stats={stats}
      />
    );

    expect(screen.getByText('Current / submitted')).toBeInTheDocument();
    expect(screen.getByText('Stale')).toBeInTheDocument();
    expect(screen.getAllByText('Expired').length).toBeGreaterThan(1);
    expect(screen.getAllByText('Superseded').length).toBeGreaterThan(1);

    const viewButtons = screen.getAllByRole('button', { name: 'View Action' });
    fireEvent.click(viewButtons[1]);
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument();
  });

  it('posts decisions only for a server-actionable current submission', async () => {
    render(<SuspensionAppeals appeals={[appeal()]} stats={stats} />);

    fireEvent.click(screen.getByRole('button', { name: 'View Action' }));
    fireEvent.click(screen.getByRole('button', { name: 'Approve' }));

    await waitFor(() => expect(postMock).toHaveBeenCalledWith(
      '/admin/appeals/10/approve',
      { reviewer_notes: null },
      expect.any(Object),
    ));
  });

  it('sends search and pagination through a server paginator', async () => {
    render(
      <SuspensionAppeals
        appeals={{
          data: [appeal()],
          current_page: 1,
          last_page: 2,
          per_page: 1,
          total: 2,
          from: 1,
          to: 1,
          links: [],
        } as never}
        stats={{ ...stats, total: 2 }}
        filters={{ search: '', status: 'all' }}
      />
    );

    fireEvent.change(screen.getByPlaceholderText(/Search account name/), {
      target: { value: 'Customer' },
    });

    await waitFor(() => expect(getMock).toHaveBeenCalledWith(
      '/admin/appeals',
      { search: 'Customer', status: 'all', page: 1 },
      expect.any(Object),
    ));

    fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
    expect(getMock).toHaveBeenCalledWith(
      '/admin/appeals',
      { search: 'Customer', status: 'all', page: 2 },
      expect.any(Object),
    );
  });
});
