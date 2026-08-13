import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AdminManagement from '../AdminTeam/AdminManagement';
import RegisteredShops from '../Shops/RegisteredShops';

const { deleteMock, getMock, postMock } = vi.hoisted(() => ({
  deleteMock: vi.fn(),
  getMock: vi.fn(),
  postMock: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, ...props }: { children?: React.ReactNode; [key: string]: unknown }) => (
    <a {...props}>{children}</a>
  ),
  router: {
    delete: deleteMock,
    get: getMock,
    post: postMock,
  },
  usePage: () => ({
    props: {
      auth: {
        user: {
          role: 'super_admin',
        },
      },
    },
  }),
}));

vi.mock('sweetalert2', () => ({
  default: {
    fire: vi.fn(),
  },
}));

vi.mock('../../../layout/AppLayout', () => ({
  default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

beforeEach(() => {
  deleteMock.mockReset();
  getMock.mockReset();
  postMock.mockReset();
});

describe('Phase 0 destructive-control containment', () => {
  it('removes permanent admin deletion controls while retaining suspension', () => {
    render(
      <AdminManagement
        admins={[{
          id: 10,
          firstName: 'Ada',
          lastName: 'Lovelace',
          email: 'ada@example.test',
          role: 'admin',
          status: 'active',
          lastLogin: null,
          createdAt: '2026-08-01T00:00:00Z',
        }]}
        stats={{ total: 1, active: 1, suspended: 0, thisMonth: 1 }}
      />,
    );

    expect(screen.getByRole('heading', { name: 'Admin Management' })).toBeInTheDocument();
    expect(screen.getByTitle('Suspend Admin')).toBeInTheDocument();
    expect(screen.queryByTitle(/delete/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/permanently delete/i)).not.toBeInTheDocument();
    expect(deleteMock).not.toHaveBeenCalled();
  });

  it('renders canonical MFA/setup state and only lifecycle actions backed by the server', () => {
    render(
      <AdminManagement
        admins={[
          {
            id: 11,
            firstName: 'Grace',
            lastName: 'Hopper',
            email: 'grace@example.test',
            role: 'admin',
            status: 'active',
            mfa_complete: true,
            recovery_code_count: 0,
          },
          {
            id: 12,
            firstName: 'Linus',
            lastName: 'Torvalds',
            email: 'linus@example.test',
            role: 'admin',
            status: 'pending_setup',
            mfa_complete: false,
            recovery_code_count: 0,
          },
        ]}
      />,
    );

    expect(screen.getByText('MFA enabled')).toBeInTheDocument();
    expect(screen.getByText('MFA setup required')).toBeInTheDocument();
    expect(screen.getAllByText('0 recovery codes remaining')).toHaveLength(2);
    expect(screen.getByRole('button', { name: /resend setup/i })).toBeInTheDocument();
    expect(screen.queryByText(/bypass|disable mfa|hard delete|permanently delete/i)).not.toBeInTheDocument();
  });

  it('removes permanent shop deletion controls while retaining suspension', () => {
    render(
      <RegisteredShops
        shops={[{
          id: 20,
          business_name: 'Sole Space Shoes',
          business_address: '1 Main Street',
          first_name: 'Ava',
          last_name: 'Owner',
          email: 'ava@example.test',
          contact_number: '555-0100',
          business_type: 'retail',
          status: 'approved',
          created_at: '2026-08-01T00:00:00Z',
        }]}
        stats={{ thisMonth: 1 }}
      />,
    );

    expect(screen.getByText('Sole Space Shoes')).toBeInTheDocument();
    expect(screen.getByTitle('Suspend Shop')).toBeInTheDocument();
    expect(screen.queryByTitle(/delete/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/permanently delete/i)).not.toBeInTheDocument();
    expect(deleteMock).not.toHaveBeenCalled();
  });
});
