import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import ModuleLanding from '../ModuleLanding';

const state = vi.hoisted(() => ({
  props: {
    activeModule: {
      key: 'logistics',
      slug: 'logistics',
      label: 'Logistics',
      description: 'Manage shipments and delivery operations for your shop.',
      pages: [
        { label: 'Dashboard', routeName: 'shop-owner.erp.logistics.dashboard', url: '/shop-owner/erp/logistics/dashboard' },
        { label: 'Shipments', routeName: 'shop-owner.erp.logistics.shipments', url: '/shop-owner/erp/logistics/shipments' },
        { label: 'Riders', routeName: 'shop-owner.erp.logistics.riders', url: '/shop-owner/erp/logistics/riders' },
      ],
    },
    urls: {
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
    activeModule: {
      key: 'logistics',
      slug: 'logistics',
      label: 'Logistics',
      description: 'Manage shipments and delivery operations for your shop.',
      pages: [
        { label: 'Dashboard', routeName: 'shop-owner.erp.logistics.dashboard', url: '/shop-owner/erp/logistics/dashboard' },
        { label: 'Shipments', routeName: 'shop-owner.erp.logistics.shipments', url: '/shop-owner/erp/logistics/shipments' },
        { label: 'Riders', routeName: 'shop-owner.erp.logistics.riders', url: '/shop-owner/erp/logistics/riders' },
      ],
    },
    urls: {
      workspace: '/shop-owner/erp/workspace',
    },
  };
});

it('renders the selected module and only its server-provided pages', () => {
  render(<ModuleLanding />);

  expect(screen.getByRole('heading', { name: 'Logistics' })).toBeInTheDocument();
  expect(screen.getByText('Manage shipments and delivery operations for your shop.')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Dashboard' })).toHaveAttribute(
    'href',
    '/shop-owner/erp/logistics/dashboard',
  );
  expect(screen.getByRole('link', { name: 'Shipments' })).toHaveAttribute(
    'href',
    '/shop-owner/erp/logistics/shipments',
  );
  expect(screen.getByRole('link', { name: 'Riders' })).toHaveAttribute(
    'href',
    '/shop-owner/erp/logistics/riders',
  );
  expect(screen.queryByText('Finance')).not.toBeInTheDocument();
});

it('returns to the ERP module picker', () => {
  render(<ModuleLanding />);

  expect(screen.getByRole('link', { name: /back to ERP workspace/i })).toHaveAttribute(
    'href',
    '/shop-owner/erp/workspace',
  );
});
