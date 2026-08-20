import React from 'react';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
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
  default: ({ inline }: { inline?: boolean }) => (
    <div data-testid={inline ? 'inline-user-dropdown' : 'user-dropdown'}>
      {inline && <><span>Profile &amp; Password</span><span>Sign Out</span></>}
    </div>
  ),
}));

vi.mock('../../components/header/SuperAdminDropdown', () => ({
  default: ({ inline }: { inline?: boolean }) => (
    <div data-testid={inline ? 'inline-super-admin-dropdown' : 'super-admin-dropdown'} />
  ),
}));

vi.mock('../../components/header/ShopOwnerDropdown', () => ({
  default: ({ actor, urls, inline }: { actor?: { name?: string }; urls?: Record<string, string | null>; inline?: boolean }) => (
    <div
      data-testid={inline ? 'inline-shop-owner-dropdown' : 'shop-owner-dropdown'}
      data-actor-name={actor?.name ?? ''}
      data-profile-url={urls?.profile ?? ''}
      data-logout-url={urls?.logout ?? ''}
    >
      {inline && <><span>Shop Profile</span><span>Sign Out</span></>}
    </div>
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

it('hides the center owner identity pill while keeping the account dropdown', () => {
  render(<AppHeaderERP />);

  expect(screen.getByRole('banner')).toHaveClass('xl:border-b');
  const compactBrand = screen.getByRole('link', { name: 'SoleSpace' });
  expect(compactBrand).toHaveClass('xl:hidden');
  expect(compactBrand.querySelector('svg')).not.toBeInTheDocument();
  expect(screen.queryByText('TailAdmin')).not.toBeInTheDocument();
  expect(screen.queryByText('North Star Shoes')).not.toBeInTheDocument();
  expect(screen.queryByText('Owner mode')).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /back to shop owner portal/i })).not.toBeInTheDocument();
  expect(screen.getAllByTestId('notification-bell')[0]).toHaveAttribute('data-base-path', '/api/shop-owner/notifications');
  expect(screen.getByTestId('shop-owner-dropdown')).toBeInTheDocument();
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
  expect(screen.getAllByTestId('notification-bell')[0]).toHaveAttribute('data-base-path', '/api/staff/notifications');
});

it('opens the compact application menu as a non-modal dropdown and keeps notifications outside it', async () => {
  render(<AppHeaderERP />);

  const trigger = screen.getByRole('button', { name: 'Toggle Application Menu' });
  expect(screen.queryByRole('region', { name: 'Application menu' })).not.toBeInTheDocument();
  expect(screen.getAllByTestId('notification-bell')).toHaveLength(2);

  fireEvent.click(trigger);

  expect(screen.getByRole('region', { name: 'Application menu' })).toBeInTheDocument();
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  expect(trigger).toHaveAttribute('aria-expanded', 'true');
  expect(trigger).toHaveAttribute('aria-haspopup', 'true');
  expect(screen.getByText('Alerts & notifications').parentElement).toHaveClass('xl:hidden');
  expect(screen.getByText('Appearance')).toBeInTheDocument();
  expect(screen.getByTestId('inline-shop-owner-dropdown')).toBeInTheDocument();
  expect(screen.getByText('Shop Profile')).toBeInTheDocument();
  expect(screen.getByText('Sign Out')).toBeInTheDocument();
  expect(document.body.style.overflow).toBe('');

  fireEvent.keyDown(document, { key: 'Escape' });

  await waitFor(() => {
    expect(screen.queryByRole('region', { name: 'Application menu' })).not.toBeInTheDocument();
    expect(document.activeElement).toBe(trigger);
  });
  expect(trigger).toHaveAttribute('aria-expanded', 'false');
});

it('closes the compact application menu when clicking outside the dropdown', () => {
  render(<AppHeaderERP />);

  fireEvent.click(screen.getByRole('button', { name: 'Toggle Application Menu' }));
  fireEvent.pointerDown(document.body);

  expect(screen.queryByRole('region', { name: 'Application menu' })).not.toBeInTheDocument();
});

it('renders regular account actions directly inside the compact menu', () => {
  state.url = '/erp/staff/dashboard';
  state.props = {
    auth: {
      erpActor: { type: 'employee', id: 11, name: 'Staff User', guard: 'user', ownerMode: false, tenantOwnerId: 7 },
      user: { name: 'Daniel Cruz', email: 'logistics.dispatcher.2@solespace.com', role: 'STAFF', roles: ['Logistics Dispatcher'] },
      shop_owner: null,
    },
    erpUrls: { profile: '/erp/profile' },
  };

  render(<AppHeaderERP />);
  fireEvent.click(screen.getByRole('button', { name: 'Toggle Application Menu' }));

  const menu = screen.getByRole('region', { name: 'Application menu' });
  expect(within(menu).getByTestId('inline-user-dropdown')).toBeInTheDocument();
  expect(within(menu).getByText('Profile & Password')).toBeInTheDocument();
  expect(within(menu).getByText('Sign Out')).toBeInTheDocument();
  expect(screen.getAllByTestId('user-dropdown')).toHaveLength(1);
});
