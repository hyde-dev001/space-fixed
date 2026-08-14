import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import AppHeaderERP from '../AppHeader_ERP';

const state = vi.hoisted(() => ({
  url: '/shop-owner/erp/workspace',
  props: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', () => ({
  usePage: () => ({ url: state.url, props: state.props }),
  Link: ({ href, children, ...props }: React.PropsWithChildren<{ href: string }>) => (
    <a href={href} {...props}>{children}</a>
  ),
}));

vi.mock('../../context/SidebarContext', () => ({
  useSidebar: () => ({
    isMobileOpen: false,
    toggleSidebar: vi.fn(),
    toggleMobileSidebar: vi.fn(),
  }),
}));

vi.mock('../../components/common/NotificationBell', () => ({
  default: ({ basePath }: { basePath: string }) => (
    <div data-testid="notification-bell" data-base-path={basePath} />
  ),
}));

vi.mock('../../components/common/ThemeToggleButton', () => ({
  ThemeToggleButton: () => <div data-testid="theme-toggle" />,
}));

vi.mock('../../components/header/UserDropdown', () => ({
  default: () => <div data-testid="user-dropdown" />,
}));

vi.mock('../../components/header/SuperAdminDropdown', () => ({
  default: () => <div data-testid="super-admin-dropdown" />,
}));

vi.mock('../../components/header/ShopOwnerDropdown', () => ({
  default: ({ actor, urls }: { actor?: { name?: string }; urls?: Record<string, string | null> }) => (
    <div
      data-testid="shop-owner-dropdown"
      data-actor-name={actor?.name ?? ''}
      data-profile-url={urls?.profile ?? ''}
      data-logout-url={urls?.logout ?? ''}
    />
  ),
}));

beforeEach(() => {
  vi.stubGlobal('route', (name: string) => name === 'landing' ? '/' : `/${name}`);
  state.url = '/shop-owner/erp/workspace';
  state.props = {
    auth: {
      erpActor: {
        type: 'shop_owner',
        id: 7,
        name: 'North Star Shoes',
        guard: 'shop_owner',
        ownerMode: true,
        tenantOwnerId: 7,
      },
      shop_owner: { name: 'North Star Shoes' },
      user: null,
    },
    erpUrls: {
      portal: '/shop-owner/dashboard',
      settings: '/shop-owner/settings',
      workspace: '/shop-owner/erp/workspace',
      notifications: '/shop-owner/notifications',
      profile: '/shop-owner/shop-profile',
      logout: '/shop-owner/logout',
      manageModules: '/shop-owner/settings',
    },
  };
});

it('renders owner identity and server URLs without a navbar portal action', () => {
  render(<AppHeaderERP />);

  expect(screen.getByRole('banner')).toHaveClass('xl:border-b');
  const compactBrand = screen.getByRole('link', { name: 'SoleSpace' });
  expect(compactBrand).toHaveClass('xl:hidden');
  expect(compactBrand.querySelector('svg')).not.toBeInTheDocument();
  expect(screen.queryByText('TailAdmin')).not.toBeInTheDocument();
  expect(screen.getByText('North Star Shoes')).toBeInTheDocument();
  expect(screen.getByText('Owner mode')).toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /back to shop owner portal/i })).not.toBeInTheDocument();
  expect(screen.getByTestId('notification-bell')).toHaveAttribute('data-base-path', '/api/shop-owner/notifications');
  expect(screen.getByTestId('shop-owner-dropdown')).toHaveAttribute('data-profile-url', '/shop-owner/shop-profile');
  expect(screen.getByTestId('shop-owner-dropdown')).toHaveAttribute('data-logout-url', '/shop-owner/logout');
});

it('keeps employee identity and notification selection in employee mode', () => {
  state.url = '/erp/staff/dashboard';
  state.props = {
    auth: {
      erpActor: {
        type: 'employee',
        id: 11,
        name: 'Staff User',
        guard: 'user',
        ownerMode: false,
        tenantOwnerId: 7,
      },
      user: { role: 'STAFF', roles: ['STAFF'] },
      shop_owner: null,
    },
    erpUrls: {
      portal: null,
      settings: null,
      workspace: null,
      notifications: null,
      profile: null,
      logout: null,
      manageModules: null,
    },
  };

  render(<AppHeaderERP />);

  expect(screen.getByTestId('user-dropdown')).toBeInTheDocument();
  expect(screen.queryByTestId('shop-owner-dropdown')).not.toBeInTheDocument();
  expect(screen.queryByText('Owner mode')).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /back to shop owner portal/i })).not.toBeInTheDocument();
  expect(screen.getByTestId('notification-bell')).toHaveAttribute('data-base-path', '/api/staff/notifications');
});
