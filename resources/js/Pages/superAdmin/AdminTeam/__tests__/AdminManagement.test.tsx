import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AdminManagement from '../AdminManagement';

const { routerGetMock, routerPostMock, routerPatchMock } = vi.hoisted(() => ({
  routerGetMock: vi.fn(),
  routerPostMock: vi.fn(),
  routerPatchMock: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
  Link: ({ children, ...props }: { children?: React.ReactNode; href?: string; className?: string }) => (
    <a {...props}>{children}</a>
  ),
  router: {
    get: routerGetMock,
    post: routerPostMock,
    patch: routerPatchMock,
  },
}));

vi.mock('../../../../layout/AppLayout', () => ({
  default: ({ children }: { children?: React.ReactNode }) => <div data-testid="admin-management-layout">{children}</div>,
}));

const admin = (overrides: Record<string, unknown> = {}) => ({
  id: 7,
  firstName: 'Alice',
  lastName: 'Admin',
  email: 'alice@example.test',
  role: 'admin',
  status: 'active',
  mfa_complete: true,
  recovery_code_count: 8,
  ...overrides,
});

const page = {
  data: [admin()],
  meta: {
    current_page: 1,
    from: 1,
    last_page: 2,
    per_page: 1,
    to: 1,
    total: 2,
  },
  links: {},
};

describe('AdminManagement server pagination', () => {
  it('mounts inside the shared app layout for privileged navigation', () => {
    render(<AdminManagement admins={[]} />);

    const layout = screen.getByTestId('admin-management-layout');
    expect(layout.contains(screen.getByRole('heading', { name: 'Admin Management' }))).toBe(true);
  });

  it('renders server metrics and requests filters through Inertia', async () => {
    render(
      <AdminManagement
        admins={page}
        stats={{ total: 3, active: 2, suspended: 1, inactive: 0 }}
      />
    );

    expect(screen.getByText('Total administrators')).toBeInTheDocument();
    expect(screen.getByText('Alice Admin')).toBeInTheDocument();
    expect(screen.getByText('3')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('Search administrators'), {
      target: { value: 'Alice' },
    });

    await waitFor(() => expect(routerGetMock).toHaveBeenCalledWith(
      '/admin/administrators',
      { search: 'Alice', page: 1 },
      { preserveState: true, preserveScroll: true, replace: true },
    ));
  });

  it('uses paginator metadata for next-page navigation', () => {
    render(
      <AdminManagement
        admins={page}
        stats={{ total: 3, active: 2, suspended: 1, inactive: 0 }}
      />
    );

    fireEvent.click(screen.getByRole('button', { name: 'Next page' }));

    expect(routerGetMock).toHaveBeenCalledWith(
      '/admin/administrators',
      { page: 2 },
      { preserveState: true, preserveScroll: true, replace: true },
    );
  });
});
