import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import Dashboard from '../Dashboard';

const mocks = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, ...props }: React.PropsWithChildren<Record<string, unknown>>) => <a {...props}>{children}</a>,
  usePage: () => ({ props: mocks.props }),
}));

vi.mock('@/layout/AppLayout_ERP', () => ({
  default: ({ children }: React.PropsWithChildren) => <>{children}</>,
}));

const defaultStats = {
  requested: 6,
  active: 12,
  completed: 48,
  cancelled: 2,
  due_today: 5,
  overdue: 2,
  failed_attempts: 3,
  unassigned: 1,
  rider_workload: 9,
  delivery_success_rate: 92.5,
};

const defaultCapabilities = {
  'GET:erp.logistics.shipments': {
    allowed: true,
    method: 'GET',
    routeName: 'erp.logistics.shipments',
    url: '/shop-owner/oversee/logistics/shipments',
    reason: null,
  },
  'GET:erp.logistics.riders': {
    allowed: true,
    method: 'GET',
    routeName: 'erp.logistics.riders',
    url: '/shop-owner/oversee/logistics/riders',
    reason: null,
  },
};

beforeEach(() => {
  mocks.props = {
    stats: defaultStats,
    auth: { erpActor: { ownerMode: true } },
    erpCapabilities: defaultCapabilities,
    canViewShipments: true,
  };
});

it('presents named delivery-health metrics instead of raw statistic keys', () => {
  render(<Dashboard />);

  expect(screen.getByTestId('logistics-dashboard')).toHaveClass('w-full');
  expect(screen.getByTestId('logistics-dashboard')).not.toHaveClass('max-w-7xl');
  expect(screen.getByRole('heading', { name: 'Logistics Dashboard' })).toBeInTheDocument();
  expect(screen.getByText('Active shipments')).toBeInTheDocument();
  expect(screen.getByText('Due today')).toBeInTheDocument();
  expect(screen.getAllByText('Overdue deliveries')).toHaveLength(2);
  expect(screen.getByText('Delivery success rate')).toBeInTheDocument();
  expect(screen.getByText('92.5%')).toBeInTheDocument();
  expect(screen.getByRole('progressbar', { name: 'Delivery success rate' })).toHaveAttribute('aria-valuenow', '92.5');
  expect(screen.queryByText('due_today')).not.toBeInTheDocument();
});

it('turns operational exceptions into an actionable attention section', () => {
  render(<Dashboard />);

  expect(screen.getByRole('heading', { name: 'Needs attention' })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Review overdue deliveries' })).toHaveTextContent('2');
  expect(screen.getByRole('link', { name: 'Review unassigned stops' })).toHaveTextContent('1');
  expect(screen.getByRole('link', { name: 'Review failed attempts' })).toHaveTextContent('3');
});

it('shows a clear state when no delivery exceptions need attention', () => {
  mocks.props = {
    ...mocks.props,
    stats: { ...defaultStats, overdue: 0, unassigned: 0, failed_attempts: 0 },
  };

  render(<Dashboard />);

  expect(screen.getByText('No urgent delivery exceptions right now.')).toBeInTheDocument();
  expect(screen.queryByText('Quick access')).not.toBeInTheDocument();
  expect(screen.queryByRole('heading', { name: 'Continue operations' })).not.toBeInTheDocument();
});
