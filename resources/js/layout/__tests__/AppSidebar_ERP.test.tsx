import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import AppSidebarERP from '../AppSidebar_ERP';

const state = vi.hoisted(() => ({
  role: 'Logistics Dispatcher',
  roles: ['Logistics Dispatcher'] as string[],
  permissions: [] as string[],
}));

vi.mock('@inertiajs/react', () => ({
  usePage: () => ({
    url: '/erp/logistics',
    props: {
      auth: {
        user: { role: state.role, roles: state.roles },
        permissions: state.permissions,
      },
    },
  }),
  Link: ({ href, children }: React.PropsWithChildren<{ href: string }>) => <a href={href}>{children}</a>,
}));

vi.mock('ziggy-js', () => ({
  route: (name: string) => {
    if (name === 'landing') return '/';
    throw new Error('use sidebar fallback map');
  },
}));

vi.mock('../../context/SidebarContext', () => ({
  useSidebar: () => ({
    isExpanded: true,
    isMobileOpen: false,
    isHovered: false,
    setIsHovered: vi.fn(),
    openSubmenu: null,
    toggleSubmenu: vi.fn(),
    setOpenSubmenu: vi.fn(),
  }),
}));

beforeEach(() => {
  state.role = 'Logistics Dispatcher';
  state.roles = ['Logistics Dispatcher'];
  state.permissions = [];
  Object.defineProperty(window, 'localStorage', {
    configurable: true,
    value: { getItem: vi.fn(), removeItem: vi.fn() },
  });
});

it('keeps supplier orders under Inventory without showing Procurement pages', () => {
  state.role = 'Inventory Manager';
  state.roles = ['Inventory Manager'];
  state.permissions = [
    'view-inventory',
    'access-supplier-order-monitoring',
    'procurement.view',
    'procurement.receive_purchase_orders',
  ];

  render(<AppSidebarERP />);

  expect(screen.getByRole('link', { name: /supplier orders/i })).toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /purchase requests/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /purchase orders/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /suppliers management/i })).not.toBeInTheDocument();
});

it('shows logistics settings only with its permission', () => {
  state.permissions = ['access-logistics-dashboard', 'configure-logistics-settings'];
  const { unmount } = render(<AppSidebarERP />);
  expect(screen.getByRole('link', { name: /settings/i })).toHaveAttribute('href', '/erp/logistics/settings');
  unmount();

  state.permissions = ['access-logistics-dashboard'];
  render(<AppSidebarERP />);
  expect(screen.queryByRole('link', { name: /settings/i })).not.toBeInTheDocument();
});

it('shows batches only with its permission', () => {
  state.permissions = ['manage-logistics-batches'];
  const { unmount } = render(<AppSidebarERP />);
  expect(screen.getByRole('link', { name: /batches/i })).toHaveAttribute('href', '/erp/logistics/batches');
  unmount();

  state.permissions = ['assign-logistics-deliveries'];
  render(<AppSidebarERP />);
  expect(screen.queryByRole('link', { name: /batches/i })).not.toBeInTheDocument();
});
