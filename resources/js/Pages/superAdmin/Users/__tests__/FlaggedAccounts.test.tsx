import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import FlaggedAccounts from '../FlaggedAccounts';

const { postMock, swalFireMock, usePageMock } = vi.hoisted(() => ({
  postMock: vi.fn(),
  swalFireMock: vi.fn(),
  usePageMock: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
  usePage: () => usePageMock(),
}));

vi.mock('axios', () => ({
  default: { post: postMock },
}));

vi.mock('sweetalert2', () => ({
  default: { fire: swalFireMock },
}));

vi.mock('../../../../layout/AppLayout', () => ({
  default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

const account = (status: string) => ({
  id: '12',
  username: 'Customer One',
  email: 'customer@example.test',
  flaggedReason: 'Fake Review',
  flaggedDate: '2026-08-12T12:00:00.000Z',
  status,
  reportedBy: 'Sole Space',
});

beforeEach(() => {
  postMock.mockReset();
  swalFireMock.mockReset();
  usePageMock.mockReset();
  swalFireMock.mockResolvedValue({ isConfirmed: true, value: 'Confirmed suspension reason.' });
  postMock.mockResolvedValue({ data: { status: 'account_suspended', changed: true } });
  usePageMock.mockReturnValue({ props: { flaggedAccounts: [account('pending_review')] } });
});

describe('Flagged account state UI', () => {
  it('shows domain status labels and only valid pending controls', () => {
    usePageMock.mockReturnValue({ props: { flaggedAccounts: [account('account_suspended')] } });
    render(<FlaggedAccounts />);

    expect(screen.getAllByText('Account suspended').length).toBeGreaterThan(0);
    expect(screen.queryByRole('button', { name: 'Dismiss' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Ban' })).not.toBeInTheDocument();
  });

  it('requires a suspension reason and posts the domain action', async () => {
    render(<FlaggedAccounts />);

    fireEvent.click(screen.getByRole('button', { name: 'Review' }));
    fireEvent.click(screen.getByRole('button', { name: 'Mark under investigation' }));

    await waitFor(() => expect(postMock).toHaveBeenCalledWith(
      '/admin/flagged-accounts/12/mark-reviewed',
      {},
    ));

    postMock.mockClear();
    cleanup();
    usePageMock.mockReturnValue({ props: { flaggedAccounts: [account('under_investigation')] } });
    render(<FlaggedAccounts />);
    fireEvent.click(screen.getAllByRole('button', { name: 'Review' })[0]);
    fireEvent.click(screen.getByRole('button', { name: 'Suspend account' }));

    await waitFor(() => expect(postMock).toHaveBeenCalledWith(
      '/admin/flagged-accounts/12/ban',
      { admin_notes: 'Confirmed suspension reason.' },
    ));
  });
});
