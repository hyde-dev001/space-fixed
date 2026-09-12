import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import AppSidebar from '../AppSidebar';

type PageState = {
  url: string;
  props: {
    auth?: {
      super_admin?: {
        role?: string;
        capabilities?: string[];
      };
    };
  };
};

const pageState = vi.hoisted<PageState>(() => ({
  url: '/admin/system-monitoring',
  props: {},
}));

vi.mock('@inertiajs/react', () => ({
  usePage: () => pageState,
  Link: ({ href, children, ...props }: React.PropsWithChildren<{ href: string }>) => (
    <a href={href} {...props}>{children}</a>
  ),
}));

vi.mock('../../context/SidebarContext', () => ({
  useSidebar: () => ({
    isExpanded: true,
    isMobileOpen: false,
    isHovered: false,
    setIsHovered: vi.fn(),
    openSubmenu: 'main-1',
    toggleSubmenu: vi.fn(),
  }),
}));

beforeEach(() => {
  pageState.url = '/admin/system-monitoring';
  pageState.props = {
    auth: {
      super_admin: {
        role: 'admin',
        capabilities: [
          'intervene_accounts',
          'review_registrations',
          'moderate_reports',
          'view_appeals',
          'view_privileged_audit',
          'view_monitoring',
        ],
      },
    },
  };

  (globalThis as { route?: (name: string) => string }).route = (name: string) => {
    const routes: Record<string, string> = {
      landing: '/',
      'admin.system-monitoring': '/admin/system-monitoring',
      'admin.audit': '/admin/audit',
      'admin.registrations.index': '/admin/registrations',
      'admin.business-upgrade-requests.index': '/admin/business-upgrade-requests',
      'admin.administrators.index': '/admin/administrators',
      'admin.subscriptions.index': '/admin/subscriptions',
      'admin.shop-reports': '/admin/shop-reports',
      'admin.suspension-appeals': '/admin/appeals',
      'admin.users.index': '/admin/users',
      'admin.shops.index': '/admin/shops',
    };

    if (routes[name]) return routes[name];
    throw new Error(`Missing route: ${name}`);
  };
});

function openAccountManagement(): void {
  fireEvent.click(screen.getByRole('button', { name: /account management/i }));
}

function setRole(role: string, capabilities: string[] = []): void {
  pageState.props = {
    auth: {
      super_admin: { role, capabilities },
    },
  };
}

it('shows truthful canonical operational links to both privileged roles', () => {
  render(<AppSidebar />);

  openAccountManagement();

  expect(screen.getByRole('link', { name: /dashboard/i })).toHaveAttribute('href', '/admin/system-monitoring');
  expect(screen.getByRole('link', { name: /audit history/i })).toHaveAttribute('href', '/admin/audit');
  expect(screen.getByRole('link', { name: /user management/i })).toHaveAttribute('href', '/admin/users');
  expect(screen.getByRole('link', { name: /shop management/i })).toHaveAttribute('href', '/admin/registrations');
  expect(screen.getByRole('link', { name: /registered shops/i })).toHaveAttribute('href', '/admin/shops');
  expect(screen.queryByText(/notification & communication tools/i)).not.toBeInTheDocument();
  expect(screen.queryAllByRole('link').some((link) => link.getAttribute('href')?.includes('/superAdmin/'))).toBe(false);
});

it('hides administrator and plan management from a regular admin', () => {
  render(<AppSidebar />);

  openAccountManagement();

  expect(screen.queryByRole('link', { name: /admin management/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /subscription management/i })).not.toBeInTheDocument();
});

it('shows administrator and plan management only to a capable super admin', () => {
  setRole('super_admin', ['manage_administrators', 'manage_plans']);

  render(<AppSidebar />);

  openAccountManagement();

  expect(screen.getByRole('link', { name: /admin management/i })).toHaveAttribute('href', '/admin/administrators');
  expect(screen.getByRole('link', { name: /subscription management/i })).toHaveAttribute('href', '/admin/subscriptions');
});

it('fails closed when restricted capability data is absent', () => {
  setRole('super_admin');

  render(<AppSidebar />);

  expect(screen.queryByRole('link', { name: /admin management/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /subscription management/i })).not.toBeInTheDocument();
});
