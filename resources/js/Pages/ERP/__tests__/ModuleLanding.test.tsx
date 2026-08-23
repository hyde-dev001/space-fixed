import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import ModuleLanding from '../ModuleLanding';

const state = vi.hoisted(() => ({
  props: {
    activeModule: {
      key: 'crm',
      slug: 'crm',
      label: 'Customers',
      description: 'Manage customers and customer relationships for your shop.',
      overview: { label: 'Overview', url: '/shop-owner/operate/customers' },
      pages: [
        { label: 'Customers', routeName: 'shop-owner.erp.crm.customers', url: '/shop-owner/erp/crm/customers' },
        { label: 'Customer Reviews', routeName: 'shop-owner.erp.crm.customer-reviews', url: '/shop-owner/erp/crm/customer-reviews' },
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
      key: 'crm',
      slug: 'crm',
      label: 'Customers',
      description: 'Manage customers and customer relationships for your shop.',
      overview: { label: 'Overview', url: '/shop-owner/operate/customers' },
      pages: [
        { label: 'Customers', routeName: 'shop-owner.erp.crm.customers', url: '/shop-owner/erp/crm/customers' },
        { label: 'Customer Reviews', routeName: 'shop-owner.erp.crm.customer-reviews', url: '/shop-owner/erp/crm/customer-reviews' },
      ],
    },
    urls: {
      workspace: '/shop-owner/erp/workspace',
    },
  };
});

it('renders the selected module and summarizes only its server-provided pages', () => {
  render(<ModuleLanding />);

  expect(screen.getByRole('heading', { name: 'Customers' })).toBeInTheDocument();
  expect(screen.getByText('Manage customers and customer relationships for your shop.')).toBeInTheDocument();
  expect(screen.getByText('2 pages available in the module navigation.')).toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Customers' })).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'Customer Reviews' })).not.toBeInTheDocument();
  expect(screen.queryByText('Invoices')).not.toBeInTheDocument();
  expect(screen.queryByText('Expenses')).not.toBeInTheDocument();
});

it('returns to the ERP module picker', () => {
  render(<ModuleLanding />);

  expect(screen.getByRole('link', { name: /back to ERP workspace/i })).toHaveAttribute(
    'href',
    '/shop-owner/erp/workspace',
  );
});
