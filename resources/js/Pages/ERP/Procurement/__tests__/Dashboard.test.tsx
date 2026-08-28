import React from 'react';
import { render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import Dashboard from '../Dashboard';

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  usePage: () => ({
    props: {
      dashboard: {
        cards: [
          { label: 'Purchase requests', value: 3, description: 'Requests in the procurement flow' },
          { label: 'Purchase orders', value: 2, description: 'Orders placed for this shop' },
        ],
      },
    },
  }),
}));

vi.mock('../../../../layout/AppLayout_ERP', () => ({
  default: ({ children }: React.PropsWithChildren) => <>{children}</>,
}));

it('renders the owner-safe procurement dashboard from scoped summary data', () => {
  render(<Dashboard />);

  expect(screen.getByTestId('procurement-dashboard')).toHaveClass('w-full');
  expect(screen.getByTestId('procurement-dashboard')).not.toHaveClass('max-w-7xl');
  expect(screen.getByRole('heading', { name: 'Procurement Dashboard' })).toBeInTheDocument();
  expect(screen.getByTestId('procurement-module-summary'))
    .toHaveClass('metrics-card', 'border-gray-200', 'bg-white', 'dark:border-gray-800');
  expect(screen.getByLabelText('Purchase requests'))
    .toHaveClass('metrics-card', 'border-gray-200');
  expect(screen.getByText('Procurement health')).toBeInTheDocument();
  expect(screen.queryByRole('heading', { name: 'Procurement workspace' })).not.toBeInTheDocument();
  expect(screen.queryByText(/workspace pages/i)).not.toBeInTheDocument();
  expect(screen.getByText('Purchase requests')).toBeInTheDocument();
  expect(screen.getByText('Purchase orders')).toBeInTheDocument();
  expect(screen.getByText('3')).toBeInTheDocument();
  expect(screen.getByText('2')).toBeInTheDocument();
  expect(screen.queryByRole('navigation', { name: 'Procurement pages' })).not.toBeInTheDocument();
});
