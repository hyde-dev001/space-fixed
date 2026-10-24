import type { ReactNode } from 'react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
  page: { props: { auth: { super_admin: { role: 'admin', name: 'Ada Admin', email: 'ada@example.test' } } } },
  routerPost: vi.fn(),
  routerVisit: vi.fn(),
  swalFire: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  usePage: () => mocks.page,
  router: {
    post: mocks.routerPost,
    visit: mocks.routerVisit,
  },
  Link: ({ children, href, ...props }: { children?: ReactNode; href?: string; [key: string]: unknown }) => (
    <a href={href} {...props}>{children}</a>
  ),
}));

vi.mock('sweetalert2', () => ({
  default: { fire: mocks.swalFire },
}));

vi.mock('../../ui/dropdown/Dropdown', () => ({
  Dropdown: ({ isOpen, children }: { isOpen: boolean; children?: ReactNode }) => isOpen ? <div>{children}</div> : null,
}));

import SuperAdminDropdown from '../SuperAdminDropdown';

beforeEach(() => {
  mocks.page.props.auth.super_admin = {
    role: 'admin',
    name: 'Ada Admin',
    email: 'ada@example.test',
  };
  mocks.routerPost.mockReset();
  mocks.routerVisit.mockReset();
  mocks.swalFire.mockReset();
});

describe('SuperAdminDropdown', () => {
  it('shows the truthful Admin role and canonical profile/security links', () => {
    render(<SuperAdminDropdown />);
    fireEvent.click(screen.getByRole('button', { name: /ada admin/i }));

    expect(screen.getByText('Admin', { exact: true })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /profile/i })).toHaveAttribute('href', '/admin/profile');
    expect(screen.getByRole('link', { name: /security/i })).toHaveAttribute('href', '/admin/security');
    expect(screen.queryByText('Super Administrator')).not.toBeInTheDocument();
  });

  it('shows the truthful Super Administrator role', () => {
    mocks.page.props.auth.super_admin.role = 'super_admin';

    render(<SuperAdminDropdown />);
    fireEvent.click(screen.getByRole('button', { name: /ada admin/i }));

    expect(screen.getByText('Super Administrator')).toBeInTheDocument();
    expect(screen.queryByText('Admin', { exact: true })).not.toBeInTheDocument();
  });

  it('does not navigate to login when the server reports a logout error', async () => {
    mocks.swalFire.mockResolvedValue({ isConfirmed: true });
    mocks.routerPost.mockImplementation((_url: string, _data: unknown, options: { onError?: () => void }) => {
      options.onError?.();
    });

    render(<SuperAdminDropdown />);
    fireEvent.click(screen.getByRole('button', { name: /ada admin/i }));
    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: /sign out/i }));
    });

    await vi.waitFor(() => expect(mocks.routerPost).toHaveBeenCalled());
    expect(await screen.findByRole('alert')).toHaveTextContent('Sign out failed. Please try again.');
    expect(mocks.routerVisit).not.toHaveBeenCalled();
  });
});
