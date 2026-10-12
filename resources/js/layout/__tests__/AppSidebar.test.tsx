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
        page_permissions?: string[];
      };
    };
  };
};

const pageState = vi.hoisted<PageState>(() => ({
  url: '/admin/system-monitoring',
  props: {},
}));

const sidebarScrollTop = vi.hoisted(() => ({ current: 0 }));

vi.mock('@inertiajs/react', () => ({
  usePage: () => pageState,
  Link: ({ href, children, viewTransition, ...props }: React.PropsWithChildren<{ href: string; viewTransition?: boolean }>) => (
    <a href={href} data-view-transition={viewTransition ? 'true' : undefined} {...props}>{children}</a>
  ),
}));

vi.mock('../../context/SidebarContext', () => ({
  useSidebar: () => {
    const [expandedSections, setExpandedSections] = React.useState<string[]>([]);

    return {
      isExpanded: true,
      isMobileOpen: false,
      isHovered: false,
      setIsHovered: vi.fn(),
      sidebarScrollTop,
      expandedSections: new Set(expandedSections),
      toggleSidebarSection: (section: string) => {
        setExpandedSections((current) => (
          current.includes(section)
            ? current.filter((item) => item !== section)
            : [...current, section]
        ));
      },
    };
  },
}));

beforeEach(() => {
  sidebarScrollTop.current = 0;
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
          'view_platform_maintenance',
        ],
      },
    },
  };

  (globalThis as { route?: (name: string) => string }).route = (name: string) => {
    const routes: Record<string, string> = {
      landing: '/',
      'admin.system-monitoring': '/admin/system-monitoring',
      'admin.maintenance.index': '/admin/maintenance',
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

function setRole(role: string, capabilities: string[] = [], pagePermissions?: string[]): void {
  pageState.props = {
    auth: {
      super_admin: { role, capabilities, page_permissions: pagePermissions },
    },
  };
}

it('shows truthful canonical operational links to both privileged roles', () => {
  render(<AppSidebar />);

  ['OVERVIEW', 'PEOPLE & ACCESS', 'SHOP OPERATIONS', 'SHOP OWNER APPROVALS', 'REPORTS & AUDIT', 'PLATFORM ADMINISTRATION']
    .forEach((section) => fireEvent.click(screen.getByRole('button', { name: section })));

  expect(screen.getByRole('link', { name: /dashboard/i })).toHaveAttribute('href', '/admin/system-monitoring');
  expect(screen.getByRole('link', { name: /audit history/i })).toHaveAttribute('href', '/admin/audit');
  expect(screen.getByRole('link', { name: /system maintenance/i })).toHaveAttribute('href', '/admin/maintenance');
  expect(screen.getByRole('link', { name: /user management/i })).toHaveAttribute('href', '/admin/users');
  expect(screen.getByRole('link', { name: /shop management/i })).toHaveAttribute('href', '/admin/registrations');
  expect(screen.getByRole('link', { name: /registered shops/i })).toHaveAttribute('href', '/admin/shops');
  expect(screen.queryByText(/notification & communication tools/i)).not.toBeInTheDocument();
  expect(screen.queryAllByRole('link').some((link) => link.getAttribute('href')?.includes('/superAdmin/'))).toBe(false);
});

it('organizes privileged pages into semantic navigation sections', () => {
  render(<AppSidebar />);

  expect(screen.getByRole('button', { name: 'OVERVIEW' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'PEOPLE & ACCESS' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'SHOP OPERATIONS' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'SHOP OWNER APPROVALS' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'USER APPROVALS & APPEALS' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'REPORTS & AUDIT' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'PLATFORM ADMINISTRATION' })).toBeInTheDocument();
  expect(screen.getByTestId('super-admin-section-icon-overview')).toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'SHOP OWNER APPROVALS' }));
  const shopOwnerApprovals = screen.getByRole('button', { name: 'SHOP OWNER APPROVALS' }).parentElement;
  expect(shopOwnerApprovals).toContainElement(screen.getByRole('link', { name: /shop management/i }));
  expect(shopOwnerApprovals).toContainElement(screen.getByRole('link', { name: /document renewals/i }));
  expect(shopOwnerApprovals).toContainElement(screen.getByRole('link', { name: /business upgrade requests/i }));

  fireEvent.click(screen.getByRole('button', { name: 'USER APPROVALS & APPEALS' }));
  const userApprovals = screen.getByRole('button', { name: 'USER APPROVALS & APPEALS' }).parentElement;
  expect(userApprovals).toContainElement(screen.getByRole('link', { name: /suspension appeals/i }));
});

it('shows an icon beside every privileged page link', () => {
  setRole('super_admin', [
    'intervene_accounts',
    'review_registrations',
    'moderate_reports',
    'view_appeals',
    'view_privileged_audit',
    'view_monitoring',
    'view_platform_maintenance',
    'manage_administrators',
    'manage_plans',
    'manage_platform_fees',
  ]);

  render(<AppSidebar />);

  for (const section of [
    'OVERVIEW',
    'PEOPLE & ACCESS',
    'SHOP OPERATIONS',
    'SHOP OWNER APPROVALS',
    'USER APPROVALS & APPEALS',
    'REPORTS & AUDIT',
    'PLATFORM ADMINISTRATION',
  ]) {
    fireEvent.click(screen.getByRole('button', { name: section }));
  }

  for (const page of [
    'dashboard',
    'admin-management',
    'user-management',
    'registered-shops',
    'shop-management',
    'document-renewals',
    'business-upgrade-requests',
    'suspension-appeals',
    'shop-reports',
    'audit-history',
    'system-maintenance',
    'subscription-management',
    'platform-fees',
  ]) {
    expect(screen.getByTestId(`super-admin-page-icon-${page}`).querySelector('svg')).not.toBeNull();
  }
});

it('restores the sidebar scroll position after the page layout remounts', () => {
  const firstRender = render(<AppSidebar />);
  const firstScrollRegion = screen.getByTestId('super-admin-sidebar-scroll-region');

  firstScrollRegion.scrollTop = 120;
  fireEvent.scroll(firstScrollRegion);
  firstRender.unmount();

  render(<AppSidebar />);

  expect(screen.getByTestId('super-admin-sidebar-scroll-region')).toHaveProperty('scrollTop', 120);
});

it('toggles each navigation section without closing the other sections', () => {
  render(<AppSidebar />);

  const shopOwnerApprovals = screen.getByRole('button', { name: 'SHOP OWNER APPROVALS' });
  const userApprovals = screen.getByRole('button', { name: 'USER APPROVALS & APPEALS' });

  expect(shopOwnerApprovals).toHaveAttribute('aria-expanded', 'false');
  expect(userApprovals).toHaveAttribute('aria-expanded', 'false');
  expect(screen.queryByRole('link', { name: /shop management/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /suspension appeals/i })).not.toBeInTheDocument();

  fireEvent.click(shopOwnerApprovals);

  expect(shopOwnerApprovals).toHaveAttribute('aria-expanded', 'true');
  expect(screen.getByRole('link', { name: /shop management/i })).toBeInTheDocument();
  expect(userApprovals).toHaveAttribute('aria-expanded', 'false');
  expect(screen.queryByRole('link', { name: /suspension appeals/i })).not.toBeInTheDocument();

  fireEvent.click(userApprovals);

  expect(userApprovals).toHaveAttribute('aria-expanded', 'true');
  expect(screen.getByRole('link', { name: /suspension appeals/i })).toBeInTheDocument();

  fireEvent.click(shopOwnerApprovals);

  expect(shopOwnerApprovals).toHaveAttribute('aria-expanded', 'false');
  expect(userApprovals).toHaveAttribute('aria-expanded', 'true');
  expect(screen.getByRole('link', { name: /suspension appeals/i })).toBeInTheDocument();
});

it('marks direct super admin links for shared active-state transitions', () => {
  render(<AppSidebar />);

  fireEvent.click(screen.getByRole('button', { name: 'OVERVIEW' }));
  fireEvent.click(screen.getByRole('button', { name: 'REPORTS & AUDIT' }));

  expect(screen.getByRole('link', { name: /dashboard/i }))
    .toHaveAttribute('data-view-transition', 'true');
  expect(screen.getByRole('link', { name: /dashboard/i }))
    .toHaveAttribute('aria-current', 'page');
  expect(screen.getByRole('link', { name: /audit history/i }))
    .toHaveAttribute('data-view-transition', 'true');
});

it('hides administrator and plan management from a regular admin', () => {
  render(<AppSidebar />);

  expect(screen.queryByRole('link', { name: /admin management/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /subscription management/i })).not.toBeInTheDocument();
});

it('hides every optional page when the server sends an empty page-access list', () => {
  setRole('admin', [
    'intervene_accounts',
    'review_registrations',
    'moderate_reports',
    'view_appeals',
    'view_privileged_audit',
    'view_monitoring',
    'view_platform_maintenance',
  ], []);

  render(<AppSidebar />);

  fireEvent.click(screen.getByRole('button', { name: 'OVERVIEW' }));

  expect(screen.getByRole('link', { name: /dashboard/i })).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /people & access/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /registered shops/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /subscription management/i })).not.toBeInTheDocument();
});

it('shows administrator and plan management only to a capable super admin', () => {
  setRole('super_admin', ['manage_administrators', 'manage_plans']);

  render(<AppSidebar />);

  fireEvent.click(screen.getByRole('button', { name: 'PEOPLE & ACCESS' }));
  fireEvent.click(screen.getByRole('button', { name: 'PLATFORM ADMINISTRATION' }));

  expect(screen.getByRole('link', { name: /admin management/i })).toHaveAttribute('href', '/admin/administrators');
  expect(screen.getByRole('link', { name: /subscription management/i })).toHaveAttribute('href', '/admin/subscriptions');
});

it('fails closed when restricted capability data is absent', () => {
  setRole('super_admin');

  render(<AppSidebar />);

  expect(screen.queryByRole('link', { name: /admin management/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /subscription management/i })).not.toBeInTheDocument();
});
