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
  router: { post: vi.fn() },
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
  role: null,
  status: 'active',
  accountStatus: 'active',
  archived: false,
  createdAt: '2026-08-12 12:00:00',
  lastLogin: null,
  employee: null,
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
