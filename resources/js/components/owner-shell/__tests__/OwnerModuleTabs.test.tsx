import React from 'react';
import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import OwnerModuleTabs from '../OwnerModuleTabs';

vi.mock('@inertiajs/react', () => ({
  Link: ({ href, children, ...props }: React.PropsWithChildren<{ href: string }>) => (
    <a href={href} {...props}>{children}</a>
  ),
}));

it('renders only server-derived module links and marks the current page', () => {
  render(
    <OwnerModuleTabs
      currentUrl="/shop-owner/erp/crm/customers"
      moduleLabel="Customers"
      links={[
        { label: 'Overview', url: '/shop-owner/operate/customers' },
        { label: 'Customers', url: '/shop-owner/erp/crm/customers' },
        { label: 'Customer Reviews', url: '/shop-owner/erp/crm/customer-reviews' },
      ]}
    />,
  );

  expect(screen.getByRole('navigation', { name: 'Customers navigation' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Overview' })).toHaveAttribute('href', '/shop-owner/operate/customers');
  expect(screen.getByRole('link', { name: 'Customers' })).toHaveAttribute('aria-current', 'page');
  expect(screen.getByRole('link', { name: 'Customer Reviews' })).toHaveAttribute('href', '/shop-owner/erp/crm/customer-reviews');
  expect(screen.queryByRole('link', { name: /invoice|expense|approval|audit|create/i })).not.toBeInTheDocument();
});
