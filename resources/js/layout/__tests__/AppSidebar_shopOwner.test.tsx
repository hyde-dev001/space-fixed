import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import AppSidebarShopOwner from '../AppSidebar_shopOwner';

const state = vi.hoisted(() => ({
  shopModules: {} as Record<string, unknown>,
  shopOwner: {
    registration_type: 'individual',
    business_type: 'both',
    is_company: false,
    can_manage_staff: false,
  },
}));

vi.mock('@inertiajs/react', () => ({
  usePage: () => ({
    url: '/shop-owner/dashboard',
    props: {
      auth: {
        shop_owner: state.shopOwner,
        shopModules: state.shopModules,
      },
    },
  }),
  Link: ({ href, children, ...props }: React.PropsWithChildren<{ href: string }>) => (
    <a href={href} {...props}>{children}</a>
  ),
}));

vi.mock('ziggy-js', () => ({
  route: (name: string) => `/${name}`,
}));

vi.mock('../../context/SidebarContext', () => ({
  useSidebar: () => ({
    isExpanded: true,
    isMobileOpen: false,
    isHovered: false,
    setIsHovered: vi.fn(),
    openSubmenu: null,
    toggleSubmenu: vi.fn(),
  }),
}));

const accessible = (overrides: Record<string, boolean> = {}) => Object.fromEntries(
  ['retail_operations', 'repair_operations', 'hr_employees', 'finance', 'crm', 'inventory', 'procurement', 'logistics']
    .map((key) => [key, {
      eligible: true,
      enabled: overrides[key] !== false,
      accessible: overrides[key] !== false,
      code: overrides[key] === false ? 'MODULE_DISABLED' : null,
      reason: overrides[key] === false ? 'Disabled' : null,
    }]),
);

beforeEach(() => {
  state.shopOwner = {
    registration_type: 'individual',
    business_type: 'both',
    is_company: false,
    can_manage_staff: false,
  };
  state.shopModules = accessible({ logistics: false, repair_operations: false });
});

it('hides disabled owner modules while keeping core dashboard visible', () => {
  render(<AppSidebarShopOwner />);

  expect(document.querySelector('a[href="/shop-owner.dashboard"]')).toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Job Orders Repair' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Shipments' })).not.toBeInTheDocument();
});

it('renders an accessible owner module', () => {
  state.shopModules = accessible({ logistics: true, repair_operations: true });
  render(<AppSidebarShopOwner />);

  expect(screen.getByRole('link', { name: 'Job Orders Repair' })).toBeInTheDocument();
});

it('shows enabled employee modules to a business shop owner', () => {
  state.shopOwner = {
    registration_type: 'company',
    business_type: 'both',
    is_company: true,
    can_manage_staff: true,
  };
  state.shopModules = accessible();

  render(<AppSidebarShopOwner />);

  expect(screen.getByRole('link', { name: 'HR & Employees' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Finance' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Inventory' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Procurement' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'CRM' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Logistics' })).toHaveAttribute('href', '/shop-owner.logistics.shipments');
  expect(screen.getByRole('link', { name: 'Job Orders Retail' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Job Orders Repair' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Product Management' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Services Management' })).toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Warranty Queue' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Stock Management' })).not.toBeInTheDocument();
});

it('hides disabled employee modules from a business shop owner', () => {
  state.shopOwner = {
    registration_type: 'company',
    business_type: 'retail',
    is_company: true,
    can_manage_staff: true,
  };
  state.shopModules = accessible({ hr_employees: false, finance: false, inventory: false, procurement: false, crm: false, logistics: false });

  render(<AppSidebarShopOwner />);

  expect(screen.queryByRole('link', { name: 'HR & Employees' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Finance' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Inventory' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Procurement' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'CRM' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Logistics' })).not.toBeInTheDocument();
});
