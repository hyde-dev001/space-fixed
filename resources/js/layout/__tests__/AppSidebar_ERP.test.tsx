import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import AppSidebarERP from '../AppSidebar_ERP';

const sidebarSource = readFileSync(resolve('resources/js/layout/AppSidebar_ERP.tsx'), 'utf8');

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
  erpUrls: { portal: null as string | null | undefined, workspace: null as string | null },
  ownerShell: null as Record<string, unknown> | null,
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
      ownerShell: state.ownerShell,
      activeModule: state.activeModule,
    },
  }),
  Link: ({ href, children, ...props }: React.PropsWithChildren<{ href: string }>) => <a href={href} {...props}>{children}</a>,
}));

vi.mock('ziggy-js', () => ({
  route: (name: string) => {
    if (name === 'landing') return '/';
    if (name === 'erp.time-in') return '/erp/time-in';
    if (name === 'shop-owner.dashboard') return '/shop-owner.dashboard';
    const ownerSubmenuPaths: Record<string, string> = {
      'shop-owner.logistics.dashboard': '/shop-owner/logistics',
      'shop-owner.logistics.shipments': '/shop-owner/logistics/shipments',
      'shop-owner.logistics.riders': '/shop-owner/logistics/riders',
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
  state.erpUrls = { portal: null, workspace: null };
  state.ownerShell = null;
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

  const { container } = render(<AppSidebarERP />);

  expect(screen.getByRole('link', { name: 'SoleSpace' })).toHaveAttribute('href', '/erp/time-in');
  expect(container.querySelector('aside')).toHaveClass('xl:mt-0', 'xl:translate-x-0');
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

it('shows core navigation with one Approval Center entry without the retired ERP picker', () => {
  state.url = '/shop-owner/erp/workspace/?tab=modules';
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
  state.ownerShell = {
    presentation: 'canonical',
    selection_reason: 'always_on',
    context: 'company',
    groups: [{
      key: 'home',
      label: 'Home',
      order: 0,
      default_expanded: true,
      items: [{
        key: 'action-center',
        label: 'Action Center',
        canonical_url: '/shop-owner/action-center',
        available: true,
        unavailable_reason: null,
        management_url: null,
        active_matching: ['/shop-owner/action-center'],
      }],
    }],
  };

  render(<AppSidebarERP />);

  expect(screen.queryByRole('link', { name: /ERP Workspace/i })).not.toBeInTheDocument();
  expect(screen.getByRole('link', { name: /^Dashboard$/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /Audit Logs/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /User Access Control/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /Suspend Accounts/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /Assist Center/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /Vouchers & Discount/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /Approval Center/i })).toHaveAttribute(
    'href',
    '/shop-owner/action-center',
  );
  expect(screen.queryByRole('link', { name: /HR & Employees/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /^Logistics$/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /^Customers$/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /log attendance/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /my payslips/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /employee profile/i })).not.toBeInTheDocument();
});

it('keeps the employee ERP primary entries when no ownerShell metadata is provided', () => {
  state.erpActor = null;
  state.role = 'Logistics Dispatcher';
  state.roles = ['Logistics Dispatcher'];
  state.permissions = ['access-logistics-dashboard', 'configure-logistics-settings'];

  render(<AppSidebarERP />);

  expect(screen.getByRole('link', { name: /Log Attendance/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /settings/i })).toBeInTheDocument();
});

it('keeps employee ERP navigation when canonical owner metadata is shared', () => {
  state.erpActor = null;
  state.ownerShell = {
    presentation: 'canonical',
    context: 'company',
    groups: [],
  };
  state.role = 'Logistics Dispatcher';
  state.roles = ['Logistics Dispatcher'];
  state.permissions = ['access-logistics-dashboard', 'configure-logistics-settings'];

  render(<AppSidebarERP />);

  expect(screen.getByRole('link', { name: /Log Attendance/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /settings/i })).toBeInTheDocument();
  expect(screen.queryByTestId('canonical-owner-sidebar')).not.toBeInTheDocument();
});

it('preserves the employee HR attendance and payroll groups', () => {
  state.url = '/erp/hr?section=attendance';
  state.role = 'HR';
  state.roles = ['HR'];
  state.permissions = [
    'access-hr-dashboard',
    'access-employee-directory',
    'access-attendance-records',
    'access-leave-approvals',
    'access-overtime-approvals',
    'access-payslip-generation',
    'access-view-payslip',
    'manage-salary-changes',
  ];

  render(<AppSidebarERP />);

  expect(screen.getByRole('button', { name: 'Attendance Monitoring' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'View Attendance' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Leave Requests' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Overtime Requests' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Payroll' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'View Slip' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Generate Slip' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Salary Changes' })).toBeInTheDocument();
});

it('renders the approved Manager workspace in the required groups and order', () => {
  state.url = '/erp/manager/dashboard';
  state.role = 'MANAGER';
  state.roles = ['MANAGER'];
  state.permissions = [
    'access-manager-dashboard',
    'access-manager-job-orders',
    'access-manager-repair-jobs',
    'access-inventory-overview',
    'access-manager-staff-workload',
    'access-manager-leave-approvals',
    'access-manager-suspension-approvals',
    'access-manager-reports',
    'access-audit-logs',
  ];

  render(<AppSidebarERP />);

  expect(screen.getByRole('heading', { name: 'OPERATIONS' })).toBeInTheDocument();
  expect(screen.getByRole('heading', { name: 'PEOPLE & APPROVALS' })).toBeInTheDocument();
  expect(screen.getByRole('heading', { name: 'REVIEW' })).toBeInTheDocument();

  const managerLinks = screen.getAllByRole('link').map((link) => link.textContent?.trim());
  expect(managerLinks).toEqual([
    'SoleSpace',
    'Log Attendance',
    'My Payslips',
    'Manager Dashboard',
    'Job Orders',
    'Repair Jobs',
    'Inventory Overview',
    'Staff & Workload',
    'Leave Approvals',
    'Suspension Approvals',
    'Termination Approvals',
    'Rehire Approvals',
    'Reports & Analytics',
    'Audit Logs',
  ]);

  expect(screen.getByRole('link', { name: 'Manager Dashboard' })).toHaveAttribute('href', '/erp/manager/dashboard');
  expect(screen.getByRole('link', { name: 'Job Orders' })).toHaveAttribute('href', '/erp/manager/job-orders');
  expect(screen.getByRole('link', { name: 'Repair Jobs' })).toHaveAttribute('href', '/erp/manager/repair-jobs');
  expect(screen.getByRole('link', { name: 'Inventory Overview' })).toHaveAttribute('href', '/erp/manager/inventory-overview');
  expect(screen.getByRole('link', { name: 'Staff & Workload' })).toHaveAttribute('href', '/erp/manager/staff-workload');
  expect(screen.getByRole('link', { name: 'Leave Approvals' })).toHaveAttribute('href', '/erp/manager/leave-approvals');
  expect(screen.getByRole('link', { name: 'Suspension Approvals' })).toHaveAttribute('href', '/erp/manager/suspension-approvals');
  expect(screen.getByRole('link', { name: 'Termination Approvals' })).toHaveAttribute('href', '/erp/manager/termination-approvals');
  expect(screen.getByRole('link', { name: 'Rehire Approvals' })).toHaveAttribute('href', '/erp/manager/rehire-approvals');
  expect(screen.getByRole('link', { name: 'Reports & Analytics' })).toHaveAttribute('href', '/erp/manager/reports');
  expect(screen.getByRole('link', { name: 'Audit Logs' })).toHaveAttribute('href', '/erp/manager/audit-logs');
  expect(screen.getByRole('link', { name: 'Log Attendance' })).toHaveAttribute('href', '/erp/time-in');
  expect(screen.getByRole('link', { name: 'My Payslips' })).toHaveAttribute('href', '/erp/my-payslips');

  expect(screen.queryByRole('link', { name: /notifications|profile|action center|customer complaints|assist center|dss|assign staff|takeover|permission administration|product upload/i })).not.toBeInTheDocument();
});

it('keeps Manager self-service payslips visible when Finance is disabled', () => {
  state.url = '/erp/manager/dashboard';
  state.role = 'MANAGER';
  state.roles = ['MANAGER'];
  state.shopModules = moduleStates({ finance: false });
  state.moduleStates = state.shopModules;
  state.permissions = ['access-manager-dashboard'];

  render(<AppSidebarERP />);

  expect(screen.getByRole('link', { name: 'Log Attendance' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'My Payslips' })).toBeInTheDocument();
});

it('shows Manager operational pages when the Manager role has an incomplete permission payload', () => {
  state.url = '/erp/manager/dashboard';
  state.role = 'MANAGER';
  state.roles = ['MANAGER'];
  state.shopOwner.business_type = 'both';
  state.permissions = ['access-manager-dashboard'];

  render(<AppSidebarERP />);

  expect(screen.getByRole('link', { name: 'Job Orders' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Repair Jobs' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Staff & Workload' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Leave Approvals' })).toBeInTheDocument();
});

it('hides only the Manager page whose read capability is missing', () => {
  state.url = '/erp/manager/dashboard';
  state.role = 'OPERATIONS COORDINATOR';
  state.roles = ['OPERATIONS COORDINATOR'];
  state.shopOwner.business_type = 'both';
  state.permissions = [
    'access-manager-dashboard',
    'access-manager-repair-jobs',
    'access-inventory-overview',
    'access-manager-staff-workload',
    'access-manager-leave-approvals',
    'access-manager-suspension-approvals',
    'access-manager-reports',
    'access-audit-logs',
  ];

  render(<AppSidebarERP />);

  expect(screen.queryByRole('link', { name: 'Job Orders' })).not.toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Repair Jobs' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Staff & Workload' })).toBeInTheDocument();
});

it('hides Job Orders for repair-only Manager shops', () => {
  state.url = '/erp/manager/repair-jobs';
  state.role = 'MANAGER';
  state.roles = ['MANAGER'];
  state.shopOwner.business_type = 'repair';
  state.permissions = [
    'access-manager-dashboard',
    'access-manager-job-orders',
    'access-manager-repair-jobs',
    'access-inventory-overview',
    'access-manager-staff-workload',
    'access-manager-leave-approvals',
    'access-manager-suspension-approvals',
    'access-manager-reports',
    'access-audit-logs',
  ];

  render(<AppSidebarERP />);

  expect(screen.queryByRole('link', { name: 'Job Orders' })).not.toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Repair Jobs' })).toBeInTheDocument();
});

it.each(['retail', 'both'])('shows Job Orders for %s Manager shops', (businessType) => {
  state.url = '/erp/manager/job-orders';
  state.role = 'MANAGER';
  state.roles = ['MANAGER'];
  state.shopOwner.business_type = businessType;
  state.permissions = [
    'access-manager-dashboard',
    'access-manager-job-orders',
  ];

  render(<AppSidebarERP />);

  expect(screen.getByRole('link', { name: 'Job Orders' })).toBeInTheDocument();
});

it('does not retain obsolete Manager destinations in the sidebar route map', () => {
  expect(sidebarSource).not.toContain('erp.manager.user-management');
  expect(sidebarSource).not.toContain('erp.manager.dss-insights');
  expect(sidebarSource).not.toContain('erp.manager.products');
  expect(sidebarSource).not.toContain('erp.manager.repair-rejection-review');
  expect(sidebarSource).not.toContain('erp.manager.suspend-approval');
});

it('shows only the active owner module pages inside the ERP shell', () => {
  state.url = '/shop-owner/erp/logistics/shipments';
  state.erpActor = { type: 'shop_owner', ownerMode: true };
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
  state.activeModule = {
    key,
    slug,
    label,
    pages: [...pages],
  };

  render(<AppSidebarERP />);

  expect(screen.queryByRole('link', { name: /ERP Workspace/i })).not.toBeInTheDocument();
  pages.forEach((page) => {
    expect(document.querySelector(`a[href="${page.url}"]`)).toBeInTheDocument();
  });
  expect(document.querySelector('a[href="/shop-owner/dashboard"]')).not.toBeInTheDocument();
  expect(document.querySelector('a[href="/shop-owner/audit-logs"]')).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /User Access Control/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /Approval Pages/i })).not.toBeInTheDocument();
});
