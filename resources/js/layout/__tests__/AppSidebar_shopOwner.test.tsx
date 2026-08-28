import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import AppSidebarShopOwner from '../AppSidebar_shopOwner';

const state = vi.hoisted(() => ({
  url: '/shop-owner/dashboard',
  shopModules: {} as Record<string, unknown> | undefined,
  moduleStates: {} as Record<string, unknown>,
  moduleEnforcementEnabled: undefined as boolean | undefined,
  erpUrls: { portal: null as string | null | undefined, workspace: null as string | null },
  activeModule: null as {
    label: string;
    pages: Array<{
      label: string;
      routeName: string;
      url: string;
      groupKey?: string | null;
      groupLabel?: string | null;
      groupOrder?: number | null;
      pageOrder?: number | null;
    }>;
  } | null,
  ownerShell: {
    presentation: 'canonical',
    selection_reason: 'always_on',
    context: 'company',
    groups: Array<{
      key: string,
      label: string,
      order: number,
      default_expanded: boolean,
      items: Array<{
        key: string,
        label: string,
        canonical_url: string,
        available: boolean,
        unavailable_reason: null,
        management_url: null,
        active_matching: string[],
      }>,
    }>,
  } | undefined,
  shopOwner: {
    registration_type: 'individual',
    business_type: 'both',
    is_company: false,
    can_manage_staff: false,
  },
}));

vi.mock('@inertiajs/react', () => ({
  usePage: () => ({
    url: state.url,
    props: {
      auth: {
        shop_owner: state.shopOwner,
        shopModules: state.shopModules,
        shopModuleEnforcementEnabled: state.moduleEnforcementEnabled,
      },
      moduleStates: state.moduleStates,
      shopModuleEnforcementEnabled: state.moduleEnforcementEnabled,
      activeModule: state.activeModule,
      ownerShell: state.ownerShell,
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
  useSidebar: () => {
    const [openSubmenu, setOpenSubmenu] = React.useState<string | null>(null);

    return {
      isExpanded: true,
      isMobileOpen: false,
      isHovered: false,
      setIsHovered: vi.fn(),
      openSubmenu,
      toggleSubmenu: (key: string) => setOpenSubmenu((current) => current === key ? null : key),
    };
  },
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
  state.url = '/shop-owner/dashboard';
  state.shopOwner = {
    registration_type: 'individual',
    business_type: 'both',
    is_company: false,
    can_manage_staff: false,
  };
  state.shopModules = accessible({ logistics: false, repair_operations: false });
  state.moduleStates = state.shopModules;
  state.moduleEnforcementEnabled = undefined;
  state.erpUrls = { portal: '/shop-owner/dashboard', workspace: null };
  state.activeModule = null;
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
        label: 'Approval Center',
        canonical_url: '/shop-owner/action-center',
        available: true,
        unavailable_reason: null,
        management_url: null,
        active_matching: ['/shop-owner/action-center'],
      }],
    }],
  };
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
    ok: true,
    json: async () => ({ pending_count: 0 }),
  }));
});

it('hides disabled owner modules while keeping core dashboard visible', () => {
  render(<AppSidebarShopOwner />);

  expect(screen.getByRole('link', { name: 'SoleSpace' })).toHaveAttribute('href', '/shop-owner.dashboard');
  expect(document.querySelector('a[href="/shop-owner.dashboard"]')).toBeInTheDocument();
  expect(screen.queryByText('Employee Modules', { exact: true })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /^Logistics$/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Job Orders Repair' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Shipments' })).not.toBeInTheDocument();
});

it('renders individual owner operational pages with business and module access', () => {
  state.shopModules = accessible({ logistics: true, repair_operations: true });
  render(<AppSidebarShopOwner />);

  expect(screen.getByText('Repair & Sales')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Job Orders Retail' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Job Orders Repair' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Product Management' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Cashier' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Services Management' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Stock Management' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Warranty Queue' })).toBeInTheDocument();
  expect(screen.getByText('Customer Management')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Customer Support' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Repair Support' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Customers' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Customer Reviews' })).toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: /^Logistics$/i }));

  expect(screen.getByRole('link', { name: 'Shipments' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Riders' })).toBeInTheDocument();
});

it('uses one Approval Center entry instead of the Approval Pages group', () => {
  render(<AppSidebarShopOwner />);

  expect(screen.getByRole('link', { name: /^Dashboard$/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /Assist Center/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /^Approval Center$/i })).toHaveAttribute(
    'href',
    '/shop-owner/action-center',
  );
  expect(screen.queryByRole('button', { name: 'Approval Pages' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /Refund Approval|Price Approvals|Payslip Approval|Salary Adjustment Approval|Purchase Request Approval|Expense Approvals|Repair Reject Approval/i })).not.toBeInTheDocument();
});

it('shows an accessible pending-count badge only when the bounded summary is positive', async () => {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
    ok: true,
    json: async () => ({ pending_count: 3 }),
  }));

  render(<AppSidebarShopOwner />);

  await waitFor(() => expect(screen.getByRole('status', { name: '3 pending approvals' })).toBeInTheDocument());
  expect(screen.getByRole('status', { name: '3 pending approvals' })).toHaveTextContent('3');
});

it('does not show a badge when the summary is zero or unavailable', async () => {
  vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('summary unavailable')));

  render(<AppSidebarShopOwner />);

  await waitFor(() => expect(screen.getByRole('link', { name: /^Approval Center$/i })).toBeInTheDocument());
  expect(screen.queryByRole('status')).not.toBeInTheDocument();
});

it('shows individual owners the Approval Center without company modules', () => {
  state.shopModules = accessible({
    finance: false,
    procurement: false,
    logistics: false,
    repair_operations: false,
  });
  render(<AppSidebarShopOwner />);

  expect(screen.getByRole('link', { name: /^Approval Center$/i })).toHaveAttribute(
    'href',
    '/shop-owner/action-center',
  );
  expect(screen.queryByRole('button', { name: 'Approval Pages' })).not.toBeInTheDocument();
});

it('removes Employee Modules and standalone Logistics for a shop owner', () => {
  state.shopOwner = {
    registration_type: 'company',
    business_type: 'both',
    is_company: true,
    can_manage_staff: true,
  };
  state.shopModules = accessible();

  render(<AppSidebarShopOwner />);

  expect(screen.getByRole('link', { name: /^Dashboard$/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /Audit Logs/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /User Access Control/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /Suspend Accounts/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /Assist Center/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /Vouchers & Discount/i })).toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'HR & Employees' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Finance' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Inventory' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Procurement' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'CRM' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /^Logistics$/i })).not.toBeInTheDocument();
});

it('uses the top-level module state when the nested auth state is unavailable', () => {
  state.shopOwner = {
    registration_type: 'company',
    business_type: 'both',
    is_company: true,
    can_manage_staff: true,
  };
  state.shopModules = undefined;
  state.moduleStates = accessible();
  state.moduleEnforcementEnabled = true;

  render(<AppSidebarShopOwner />);

  expect(screen.getByRole('link', { name: /Audit Logs/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /Assist Center/i })).toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Finance' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'CRM' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Customers' })).not.toBeInTheDocument();

  expect(screen.getByRole('link', { name: /^Approval Center$/i })).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Approval Pages' })).not.toBeInTheDocument();
});

it('does not render a retired ERP Workspace entry for a company owner', () => {
  state.shopOwner = {
    registration_type: 'company',
    business_type: 'both',
    is_company: true,
    can_manage_staff: true,
  };
  state.shopModules = accessible();
  render(<AppSidebarShopOwner />);

  expect(screen.queryByRole('link', { name: /ERP Workspace/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Retail Operations' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Repair Operations' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Job Orders Retail' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Job Orders Repair' })).not.toBeInTheDocument();
});

it('renders owner module page groups as collapsible navigation without grouping direct pages', () => {
  state.shopOwner = {
    registration_type: 'company',
    business_type: 'both',
    is_company: true,
    can_manage_staff: true,
  };
  state.shopModules = accessible();
  state.activeModule = {
    label: 'Finance',
    pages: [
      {
        label: 'Dashboard',
        routeName: 'shop-owner.erp.finance.dashboard',
        url: '/shop-owner/erp/finance/dashboard',
      },
      {
        label: 'Expense Approvals',
        routeName: 'shop-owner.erp.finance.expense-approvals',
        url: '/shop-owner/erp/finance/expense-approvals',
        groupKey: 'approvals',
        groupLabel: 'Approvals',
        groupOrder: 20,
        pageOrder: 10,
      },
      {
        label: 'Refund Approvals',
        routeName: 'shop-owner.erp.finance.refund-approvals',
        url: '/shop-owner/erp/finance/refund-approvals',
        groupKey: 'approvals',
        groupLabel: 'Approvals',
        groupOrder: 20,
        pageOrder: 20,
      },
    ],
  };
  state.url = '/shop-owner/erp/finance/expense-approvals';

  render(<AppSidebarShopOwner />);

  expect(screen.getByRole('link', { name: /^Dashboard$/i })).toHaveAttribute(
    'href',
    '/shop-owner/erp/finance/dashboard',
  );
  expect(screen.getByRole('button', { name: 'Approvals' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Expense Approvals' })).toHaveAttribute(
    'href',
    '/shop-owner/erp/finance/expense-approvals',
  );
  expect(screen.getByRole('link', { name: 'Refund Approvals' })).toHaveAttribute(
    'href',
    '/shop-owner/erp/finance/refund-approvals',
  );
});

it('does not keep a stale module scope on a normal shop owner portal page', () => {
  state.shopOwner = {
    registration_type: 'company',
    business_type: 'both',
    is_company: true,
    can_manage_staff: true,
  };
  state.shopModules = accessible();
  state.activeModule = {
    label: 'Retail Operations',
    pages: [{
      label: 'Products',
      routeName: 'shop-owner.erp.retail.products',
      url: '/shop-owner/erp/retail/products',
    }],
  };

  render(<AppSidebarShopOwner />);

  expect(screen.getByRole('link', { name: /^Dashboard$/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /^Approval Center$/i })).toBeInTheDocument();
  expect(screen.queryByText('Retail Operations', { exact: true })).not.toBeInTheDocument();
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

it('keeps supported refund approval visible when every module is unavailable', () => {
  state.shopOwner = {
    registration_type: 'company',
    business_type: 'both',
    is_company: true,
    can_manage_staff: true,
  };
  state.shopModules = accessible({
    retail_operations: false,
    repair_operations: false,
    hr_employees: false,
    finance: false,
    crm: false,
    inventory: false,
    procurement: false,
    logistics: false,
  });

  render(<AppSidebarShopOwner />);

  expect(screen.getByRole('link', { name: /^Approval Center$/i })).toBeInTheDocument();
  expect(screen.queryByText('Employee Modules', { exact: true })).not.toBeInTheDocument();
  expect(screen.queryByText('Customer Management', { exact: true })).not.toBeInTheDocument();
});
