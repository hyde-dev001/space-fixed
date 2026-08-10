import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import Workspace from '../Workspace';

const state = vi.hoisted(() => ({
  props: {
    enabledModules: [{ key: 'finance', label: 'Finance', url: '/shop-owner/erp/finance/audit-logs' }],
    unavailableModules: [
      { key: 'inventory', label: 'Inventory', code: 'MODULE_DISABLED', reason: 'Module disabled for this shop.' },
    ],
    urls: {
      portal: '/shop-owner/dashboard',
      settings: '/shop-owner/settings',
      workspace: '/shop-owner/erp/workspace',
    },
  },
}));

vi.mock('@inertiajs/react', () => ({
  usePage: () => ({ props: state.props }),
  Link: ({ href, children, ...props }: React.PropsWithChildren<{ href: string }>) => (
    <a href={href} {...props}>{children}</a>
  ),
}));

vi.mock('../../../layout/AppLayout_ERP', () => ({
  default: ({ children }: React.PropsWithChildren) => <>{children}</>,
}));

beforeEach(() => {
  state.props = {
    enabledModules: [{ key: 'finance', label: 'Finance', url: '/shop-owner/erp/finance/audit-logs' }],
    unavailableModules: [
      { key: 'inventory', label: 'Inventory', code: 'MODULE_DISABLED', reason: 'Module disabled for this shop.' },
    ],
    urls: {
      portal: '/shop-owner/dashboard',
      settings: '/shop-owner/settings',
      workspace: '/shop-owner/erp/workspace',
    },
  };
});

it('shows available and unavailable modules with a server-provided manage link', () => {
  render(<Workspace />);

  expect(screen.getByRole('heading', { name: 'ERP Workspace' })).toBeInTheDocument();
  expect(screen.getByText('Finance')).toBeInTheDocument();
  expect(screen.getByText('Inventory')).toBeInTheDocument();
  expect(screen.getByText('Module disabled for this shop.')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /manage modules/i })).toHaveAttribute(
    'href',
    '/shop-owner/settings',
  );
});

it('links an available module card to its server-provided entry page', () => {
  render(<Workspace />);

  expect(screen.getByRole('link', { name: /Finance/ })).toHaveAttribute(
    'href',
    '/shop-owner/erp/finance/audit-logs',
  );
});
