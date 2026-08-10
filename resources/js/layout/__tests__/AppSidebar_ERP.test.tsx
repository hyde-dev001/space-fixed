import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import AppSidebarERP from '../AppSidebar_ERP';

const state = vi.hoisted(() => ({
  url: '/erp/logistics',
  role: 'Logistics Dispatcher',
  roles: ['Logistics Dispatcher'] as string[],
  permissions: [] as string[],
  shopModules: {} as Record<string, unknown> | undefined,
  moduleStates: {} as Record<string, unknown>,
  moduleEnforcementEnabled: undefined as boolean | undefined,
  erpActor: null as null | { type: string; ownerMode: boolean },
  erpCapabilities: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', () => ({
  usePage: () => ({
    url: state.url,
    props: {
      auth: {
        user: { role: state.role, roles: state.roles },
        permissions: state.permissions,
        shopModules: state.shopModules,
        shopModuleEnforcementEnabled: state.moduleEnforcementEnabled,
        erpActor: state.erpActor,
        erpCapabilities: state.erpCapabilities,
      },
      moduleStates: state.moduleStates,
      shopModuleEnforcementEnabled: state.moduleEnforcementEnabled,
    },
  }),
  Link: ({ href, children, ...props }: React.PropsWithChildren<{ href: string }>) => <a href={href} {...props}>{children}</a>,
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
  state.url = '/erp/logistics';
  state.role = 'Logistics Dispatcher';
  state.roles = ['Logistics Dispatcher'];
  state.permissions = [];
  state.shopModules = moduleStates();
  state.moduleStates = state.shopModules;
  state.moduleEnforcementEnabled = undefined;
  state.erpActor = null;
  state.erpCapabilities = {};
  Object.defineProperty(window, 'localStorage', {
    configurable: true,
    value: { getItem: vi.fn(), removeItem: vi.fn() },
  });
});

function moduleStates(overrides: Record<string, boolean> = {}) {
  return Object.fromEntries(
    ['retail_operations', 'repair_operations', 'hr_employees', 'finance', 'crm', 'inventory', 'procurement', 'logistics']
      .map((key) => [key, {
        eligible: true,
        enabled: overrides[key] !== false,
        accessible: overrides[key] !== false,
        code: overrides[key] === false ? 'MODULE_DISABLED' : null,
        reason: overrides[key] === false ? 'Disabled' : null,
      }]),
  );
}

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

  state.permissions = ['access-logistics-dashboard', 'assign-logistics-deliveries'];
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

it('hides disabled employee modules but keeps core logistics settings visible', () => {
  state.permissions = ['access-logistics-dashboard', 'configure-logistics-settings'];
  state.shopModules = moduleStates({ logistics: false });

  render(<AppSidebarERP />);

  expect(screen.queryByRole('link', { name: /^logistics$/i })).not.toBeInTheDocument();
  expect(screen.getByRole('link', { name: /^settings$/i })).toHaveAttribute('href', '/erp/logistics/settings');
});

it('keeps employee permission filtering when an eligible module is enabled', () => {
  state.permissions = ['access-logistics-dashboard', 'assign-logistics-deliveries'];
  state.shopModules = moduleStates({ logistics: true });

  render(<AppSidebarERP />);

  expect(screen.getByRole('link', { name: /^logistics$/i })).toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /settings/i })).not.toBeInTheDocument();
});

it('uses the top-level module state when the nested auth state is unavailable', () => {
  state.shopModules = undefined;
  state.moduleStates = moduleStates();
  state.moduleEnforcementEnabled = true;
  state.permissions = ['access-logistics-dashboard', 'assign-logistics-deliveries'];

  render(<AppSidebarERP />);

  expect(screen.getByRole('link', { name: /^logistics$/i })).toBeInTheDocument();
});

it('uses owner capability URLs and excludes employee self-service navigation', () => {
  state.url = '/shop-owner/erp/workspace';
  state.role = 'MANAGER';
  state.roles = ['MANAGER'];
  state.permissions = ['access-manager-dashboard', 'access-attendance-records', 'access-view-payslip'];
  state.erpActor = { type: 'shop_owner', ownerMode: true };
  state.erpCapabilities = {
    'GET:shop-owner.erp.workspace': {
      allowed: true,
      method: 'GET',
      routeName: 'shop-owner.erp.workspace',
      url: '/shop-owner/erp/workspace',
      reason: null,
    },
  };

  render(<AppSidebarERP />);

  expect(screen.getByRole('link', { name: /ERP Workspace/i })).toHaveAttribute(
    'href',
    '/shop-owner/erp/workspace',
  );
  expect(screen.getByRole('link', { name: /ERP Workspace/i })).toHaveClass('menu-item-active');
  expect(screen.queryByRole('link', { name: /log attendance/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /my payslips/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /employee/i })).not.toBeInTheDocument();
});
