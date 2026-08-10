import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
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
  erpUrls: { workspace: null as string | null },
  activeModule: null as null | {
    key: string;
    slug: string;
    label: string;
    pages: Array<{ label: string; routeName: string; url: string }>;
  },
  shopOwner: {
    registration_type: 'company',
    business_type: 'both',
    is_company: true,
    can_manage_staff: true,
  },
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
        shop_owner: state.shopOwner,
      },
      moduleStates: state.moduleStates,
      shopModuleEnforcementEnabled: state.moduleEnforcementEnabled,
      erpUrls: state.erpUrls,
      activeModule: state.activeModule,
    },
  }),
  Link: ({ href, children, ...props }: React.PropsWithChildren<{ href: string }>) => <a href={href} {...props}>{children}</a>,
}));

vi.mock('ziggy-js', () => ({
  route: (name: string) => {
    if (name === 'landing') return '/';
    const ownerSubmenuPaths: Record<string, string> = {
      'shop-owner.logistics.dashboard': '/shop-owner/logistics',
      'shop-owner.logistics.shipments': '/shop-owner/logistics/shipments',
      'shop-owner.logistics.riders': '/shop-owner/logistics/riders',
      'shop-owner.refund-approvals': '/shop-owner/refund-approvals',
      'shop-owner.price-approvals': '/shop-owner/price-approvals',
      'shop-owner.payslip-approvals': '/shop-owner/payslip-approvals',
      'shop-owner.salary-adjustment-approvals': '/shop-owner/salary-adjustment-approvals',
      'shop-owner.purchase-request-approval': '/shop-owner/purchase-request-approval',
      'shop-owner.expense-approvals': '/shop-owner/expense-approvals',
      'shop-owner.repair-reject-approval': '/shop-owner/repair-reject-approval',
    };
    if (ownerSubmenuPaths[name]) return ownerSubmenuPaths[name];
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
  state.erpUrls = { workspace: null };
  state.activeModule = null;
  state.shopOwner = {
    registration_type: 'company',
    business_type: 'both',
    is_company: true,
    can_manage_staff: true,
  };
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

it('shows core-only navigation in the owner ERP picker', () => {
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
  state.erpUrls = { workspace: '/shop-owner/erp/workspace' };

  render(<AppSidebarERP />);

  expect(screen.getByRole('link', { name: /ERP Workspace/i })).toHaveAttribute(
    'href',
    '/shop-owner/erp/workspace',
  );
  expect(screen.getByRole('link', { name: /ERP Workspace/i })).toHaveClass('menu-item-active');
  expect(screen.getByRole('link', { name: /^Dashboard$/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /Audit Logs/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /User Access Control/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /Suspend Accounts/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /Assist Center/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /Vouchers & Discount/i })).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: /Approval Pages/i }));
  expect(screen.getByRole('link', { name: /Refund Approval/i })).toHaveAttribute(
    'href',
    '/shop-owner/refund-approvals',
  );
  expect(screen.queryByRole('link', { name: /HR & Employees/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /^Logistics$/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /^Customers$/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /log attendance/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /my payslips/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /employee profile/i })).not.toBeInTheDocument();
});

it('shows only the active owner module pages inside the ERP shell', () => {
  state.url = '/shop-owner/erp/logistics/shipments';
  state.erpActor = { type: 'shop_owner', ownerMode: true };
  state.erpUrls = { workspace: '/shop-owner/erp/workspace' };
  state.activeModule = {
    key: 'logistics',
    slug: 'logistics',
    label: 'Logistics',
    pages: [
      { label: 'Dashboard', routeName: 'shop-owner.erp.logistics.dashboard', url: '/shop-owner/erp/logistics/dashboard' },
      { label: 'Shipments', routeName: 'shop-owner.erp.logistics.shipments', url: '/shop-owner/erp/logistics/shipments' },
      { label: 'Riders', routeName: 'shop-owner.erp.logistics.riders', url: '/shop-owner/erp/logistics/riders' },
    ],
  };

  render(<AppSidebarERP />);

  expect(screen.getByRole('link', { name: /ERP Workspace/i })).toHaveAttribute(
    'href',
    '/shop-owner/erp/workspace',
  );
  expect(screen.getByRole('link', { name: /^Dashboard$/i })).toHaveAttribute(
    'href',
    '/shop-owner/erp/logistics/dashboard',
  );
  expect(screen.getByRole('link', { name: /Shipments/i })).toHaveAttribute(
    'href',
    '/shop-owner/erp/logistics/shipments',
  );
  expect(screen.getByRole('link', { name: /Riders/i })).toHaveAttribute(
    'href',
    '/shop-owner/erp/logistics/riders',
  );
  expect(screen.queryByRole('link', { name: /Audit Logs/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /User Access Control/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /HR & Employees/i })).not.toBeInTheDocument();
});

it.each([
  {
    key: 'retail_operations',
    slug: 'retail',
    label: 'Retail Operations',
    pages: [{ label: 'Products', routeName: 'shop-owner.erp.retail.products', url: '/shop-owner/erp/retail/products' }],
  },
  {
    key: 'repair_operations',
    slug: 'repair',
    label: 'Repair Operations',
    pages: [{ label: 'Repair Dashboard', routeName: 'shop-owner.erp.staff.repair-dashboard', url: '/shop-owner/erp/staff/repair-dashboard' }],
  },
  {
    key: 'hr_employees',
    slug: 'hr',
    label: 'HR and Employees',
    pages: [{ label: 'Audit Logs', routeName: 'shop-owner.erp.hr.audit-logs', url: '/shop-owner/erp/hr/audit-logs' }],
  },
  {
    key: 'finance',
    slug: 'finance',
    label: 'Finance',
    pages: [{ label: 'Audit Logs', routeName: 'shop-owner.erp.finance.audit-logs', url: '/shop-owner/erp/finance/audit-logs' }],
  },
  {
    key: 'crm',
    slug: 'crm',
    label: 'CRM',
    pages: [
      { label: 'Dashboard', routeName: 'shop-owner.erp.crm.dashboard', url: '/shop-owner/erp/crm/dashboard' },
      { label: 'Customers', routeName: 'shop-owner.erp.crm.customers', url: '/shop-owner/erp/crm/customers' },
      { label: 'Customer Reviews', routeName: 'shop-owner.erp.crm.customer-reviews', url: '/shop-owner/erp/crm/customer-reviews' },
    ],
  },
  {
    key: 'inventory',
    slug: 'inventory',
    label: 'Inventory',
    pages: [
      { label: 'Dashboard', routeName: 'shop-owner.erp.inventory.inventory-dashboard', url: '/shop-owner/erp/inventory/inventory-dashboard' },
      { label: 'Product Inventory', routeName: 'shop-owner.erp.inventory.product-inventory', url: '/shop-owner/erp/inventory/product-inventory' },
      { label: 'Stock Movement', routeName: 'shop-owner.erp.inventory.stock-movement', url: '/shop-owner/erp/inventory/stock-movement' },
    ],
  },
  {
    key: 'procurement',
    slug: 'procurement',
    label: 'Procurement',
    pages: [{ label: 'Suppliers Management', routeName: 'shop-owner.erp.procurement.suppliers-management', url: '/shop-owner/erp/procurement/suppliers-management' }],
  },
  {
    key: 'logistics',
    slug: 'logistics',
    label: 'Logistics',
    pages: [
      { label: 'Dashboard', routeName: 'shop-owner.erp.logistics.dashboard', url: '/shop-owner/erp/logistics/dashboard' },
      { label: 'Shipments', routeName: 'shop-owner.erp.logistics.shipments', url: '/shop-owner/erp/logistics/shipments' },
      { label: 'Riders', routeName: 'shop-owner.erp.logistics.riders', url: '/shop-owner/erp/logistics/riders' },
    ],
  },
] as const)('scopes the sidebar to the $key module', ({ key, slug, label, pages }) => {
  state.url = pages[0].url;
  state.erpActor = { type: 'shop_owner', ownerMode: true };
  state.erpUrls = { workspace: '/shop-owner/erp/workspace' };
  state.activeModule = {
    key,
    slug,
    label,
    pages: [...pages],
  };

  render(<AppSidebarERP />);

  expect(screen.getByRole('link', { name: /ERP Workspace/i })).toHaveAttribute(
    'href',
    '/shop-owner/erp/workspace',
  );
  pages.forEach((page) => {
    expect(document.querySelector(`a[href="${page.url}"]`)).toBeInTheDocument();
  });
  expect(document.querySelector('a[href="/shop-owner/dashboard"]')).not.toBeInTheDocument();
  expect(document.querySelector('a[href="/shop-owner/audit-logs"]')).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /User Access Control/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /Approval Pages/i })).not.toBeInTheDocument();
});
