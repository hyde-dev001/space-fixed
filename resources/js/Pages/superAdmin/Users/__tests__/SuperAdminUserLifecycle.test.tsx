import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SuperAdminUserManagement from '../SuperAdminUserManagement';

const { fetchMock, swalFireMock } = vi.hoisted(() => ({
  fetchMock: vi.fn(),
  swalFireMock: vi.fn(),
}));

vi.stubGlobal('fetch', fetchMock);

vi.mock('@inertiajs/react', () => ({
  Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
  Link: ({ children }: { children?: React.ReactNode }) => <a href="#">{children}</a>,
  router: { get: vi.fn(), post: vi.fn() },
}));

vi.mock('sweetalert2', () => ({
  default: { fire: swalFireMock },
}));

vi.mock('../../../../layout/AppLayout', () => ({
  default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('../../../../components/ui/button/Button', () => ({
  default: ({ children, ...props }: { children?: React.ReactNode }) => <button {...props}>{children}</button>,
}));

const user = (overrides: Record<string, unknown> = {}) => ({
  id: 22,
  firstName: 'Customer',
  lastName: 'One',
  name: 'Customer One',
  email: 'customer@example.test',
  address: '1 Main Street',
  phone: '555-0100',
  age: 30,
  status: 'active',
  accountStatus: 'active',
  archived: false,
  createdAt: '2026-08-12 12:00:00',
  lastLogin: null,
  ...overrides,
});

beforeEach(() => {
  fetchMock.mockReset();
  swalFireMock.mockReset();
  swalFireMock.mockResolvedValue({ isConfirmed: true, value: 'Verified lifecycle reason.' });
  fetchMock.mockResolvedValue({
    ok: true,
    json: async () => ({ message: 'Lifecycle operation completed.' }),
  });
});

describe('SuperAdminUserManagement lifecycle controls', () => {
  it('hides the separate identity review queue shortcut', () => {
    render(<SuperAdminUserManagement users={[user()]} />);

    expect(screen.queryByRole('link', { name: 'Identity Review Queue' })).not.toBeInTheDocument();
  });

  it('orders account status before submitted documents and identity screening', () => {
    render(
      <SuperAdminUserManagement
        users={[user({
          validIdUrl: '/ids/front.jpg',
          identityVerification: {
            id: 7,
            documentType: 'student_id',
            screeningStatus: 'manual_review_required',
            reviewStatus: 'pending',
          },
        })]}
      />
    );

    fireEvent.click(screen.getByTitle('View Registration Details'));

    expect(screen.getAllByRole('heading', { level: 4 }).map((heading) => heading.textContent)).toEqual([
      'Personal Information',
      'Address',
      'Account Status',
      'Submitted Documents',
      'Identity Screening',
    ]);
    expect(screen.getByRole('dialog')).toHaveClass('overflow-y-auto', 'no-scrollbar');
  });

  it('displays the customer suffix in registration details', () => {
    render(<SuperAdminUserManagement users={[user({ suffix: 'Jr.' })]} />);

    fireEvent.click(screen.getByTitle('View Registration Details'));

    expect(screen.getByText('Suffix')).toBeInTheDocument();
    expect(screen.getByText('Jr.')).toBeInTheDocument();
  });

  it('keeps identity decisions beside the close button in the modal footer', () => {
    render(
      <SuperAdminUserManagement
        users={[user({
          validIdUrl: '/ids/front.jpg',
          identityVerification: {
            id: 7,
            documentType: 'student_id',
            screeningStatus: 'manual_review_required',
            reviewStatus: 'pending',
          },
        })]}
      />
    );

    fireEvent.click(screen.getByTitle('View Registration Details'));

    const closeButton = screen.getByRole('button', { name: 'Close' });
    const footer = closeButton.parentElement;

    expect(footer).toContainElement(screen.getByRole('button', { name: 'Approve Review' }));
    expect(footer).toContainElement(screen.getByRole('button', { name: 'Reject Review' }));
  });

  it('keeps identity decisions disabled until a submitted document is viewed', () => {
    render(
      <SuperAdminUserManagement
        users={[user({
          validIdUrl: '/ids/front.jpg',
          identityVerification: {
            id: 7,
            documentType: 'student_id',
            screeningStatus: 'manual_review_required',
            reviewStatus: 'pending',
          },
        })]}
      />
    );

    fireEvent.click(screen.getByTitle('View Registration Details'));

    const approveButton = screen.getByRole('button', { name: 'Approve Review' });
    const rejectButton = screen.getByRole('button', { name: 'Reject Review' });
    expect(approveButton).toBeDisabled();
    expect(rejectButton).toBeDisabled();

    fireEvent.click(screen.getByRole('button', { name: 'View' }));

    expect(approveButton).toBeEnabled();
    expect(rejectButton).toBeEnabled();
  });

  it('displays disabled identity decisions for an already decided review', () => {
    render(
      <SuperAdminUserManagement
        users={[user({
          validIdUrl: '/ids/front.jpg',
          identityVerification: {
            id: 7,
            documentType: 'student_id',
            screeningStatus: 'manual_review_required',
            reviewStatus: 'rejected',
          },
        })]}
      />
    );

    fireEvent.click(screen.getByTitle('View Registration Details'));

    const approveButton = screen.getByRole('button', { name: 'Approve Review' });
    const rejectButton = screen.getByRole('button', { name: 'Reject Review' });
    expect(approveButton).toBeDisabled();
    expect(rejectButton).toBeDisabled();

    fireEvent.click(screen.getByRole('button', { name: 'View' }));

    expect(approveButton).toBeDisabled();
    expect(rejectButton).toBeDisabled();
  });

  it('renders metric values without decorative period-change badges', () => {
    render(
      <SuperAdminUserManagement
        users={[user()]}
        stats={{
          total: 10,
          pending: 2,
          active: 7,
          archived: 1,
        }}
      />
    );

    expect(screen.getByText('10')).toBeInTheDocument();
    expect(screen.getByText('2')).toBeInTheDocument();
    expect(screen.getByText('7')).toBeInTheDocument();
    expect(screen.queryByText('25%')).not.toBeInTheDocument();
    expect(screen.queryByText('12%')).not.toBeInTheDocument();
  });

  it('keeps legacy registration statuses visible but read-only', () => {
    render(
      <SuperAdminUserManagement
        users={[
          user({ id: 1, name: 'Pending Customer', status: 'pending', accountStatus: 'pending' }),
          user({ id: 2, name: 'Rejected Customer', status: 'rejected', accountStatus: 'rejected' }),
          user({ id: 3, name: 'Deactivated Customer', status: 'deactivated', accountStatus: 'deactivated' }),
        ]}
      />
    );

    for (const status of ['pending', 'rejected', 'deactivated']) {
      fireEvent.change(screen.getByLabelText('Filter by Status'), { target: { value: status } });
      expect(screen.getByTitle('View Registration Details')).toBeInTheDocument();
      expect(screen.queryByTitle('Approve User')).not.toBeInTheDocument();
      expect(screen.queryByTitle('Reject User')).not.toBeInTheDocument();
      expect(screen.queryByTitle('Deactivate Account')).not.toBeInTheDocument();
      expect(screen.queryByText('Reset Password')).not.toBeInTheDocument();
    }

    expect(fetchMock.mock.calls.some(([url]) => String(url).match(/\/superAdmin\/users\/\d+\/(approve|reject)/))).toBe(false);
  });

  it('does not expose fake controls inside the pending-user details view', () => {
    render(
      <SuperAdminUserManagement
        users={[user({ status: 'pending', accountStatus: 'pending' })]}
      />
    );

    fireEvent.change(screen.getByLabelText('Filter by Status'), { target: { value: 'pending' } });
    fireEvent.click(screen.getByTitle('View Registration Details'));

    expect(screen.queryByRole('button', { name: /^approve$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^reject$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /reset password/i })).not.toBeInTheDocument();
  });

  it('keeps rejected users free of general suspend/activate controls and exposes archived state', () => {
    render(
      <SuperAdminUserManagement
        users={[
          user({ id: 1, status: 'rejected', accountStatus: 'rejected' }),
          user({ id: 2, status: 'archived', accountStatus: 'suspended', archived: true }),
        ]}
      />
    );

    fireEvent.change(screen.getByLabelText('Filter by Status'), { target: { value: 'rejected' } });
    expect(screen.queryByTitle('Suspend Account')).not.toBeInTheDocument();
    expect(screen.queryByTitle('Reactivate Account')).not.toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Filter by Status'), { target: { value: 'archived' } });
    expect(screen.getByText('archived')).toBeInTheDocument();
    expect(screen.getByTitle('Restore Account')).toBeInTheDocument();
  });

  it('reactivates only suspended users with a reason and the canonical route', async () => {
    render(
      <SuperAdminUserManagement users={[user({ status: 'suspended', accountStatus: 'suspended' })]} />
    );

    fireEvent.change(screen.getByLabelText('Filter by Status'), { target: { value: 'suspended' } });
    fireEvent.click(screen.getByTitle('Reactivate Account'));

    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith(
      '/admin/users/22/reactivate',
      expect.objectContaining({
        method: 'POST',
        body: JSON.stringify({ reactivation_reason: 'Verified lifecycle reason.' }),
      }),
    ));

    expect(swalFireMock.mock.calls[0][0]).toMatchObject({
      input: 'textarea',
      title: 'Reactivate Account',
    });
  });

  it('does not claim reactivation when the server denies the transition', async () => {
    fetchMock.mockImplementation((url: string) => Promise.resolve(
      url.includes('/reactivate')
        ? { ok: false, status: 403, json: async () => ({ message: 'Recent reauthentication required.' }) }
        : { ok: true, json: async () => ({ data: [user({ status: 'suspended', accountStatus: 'suspended' })] }) },
    ));

    render(
      <SuperAdminUserManagement users={[user({ status: 'suspended', accountStatus: 'suspended' })]} />
    );

    fireEvent.change(screen.getByLabelText('Filter by Status'), { target: { value: 'suspended' } });
    fireEvent.click(screen.getByTitle('Reactivate Account'));

    await waitFor(() => expect(fetchMock).toHaveBeenCalledWith(
      '/admin/users/22/reactivate',
      expect.objectContaining({ method: 'POST' }),
    ));
    expect(screen.getByTitle('Reactivate Account')).toBeInTheDocument();
  });
});
