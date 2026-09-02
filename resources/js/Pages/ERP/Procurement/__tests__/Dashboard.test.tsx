import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import Dashboard from '../Dashboard';

const dashboardState = vi.hoisted(() => {
  const months = [
    ['Apr 2026', '2026-04-01', '2026-04-30'],
    ['May 2026', '2026-05-01', '2026-05-31'],
    ['Jun 2026', '2026-06-01', '2026-06-30'],
    ['Jul 2026', '2026-07-01', '2026-07-31'],
    ['Aug 2026', '2026-08-01', '2026-08-31'],
    ['Sep 2026', '2026-09-01', '2026-09-30'],
  ].map(([label, start, end], index) => ({
    label,
    start,
    end,
    purchase_requests: index === 3 ? 2 : 0,
    purchase_orders: index === 4 ? 1 : 0,
  }));

  const dashboard = {
    title: 'Procurement Dashboard',
    description: 'Monitor purchasing activity for your shop.',
    summary: {
      purchase_requests: 8,
      awaiting_review: 3,
      purchase_orders: 5,
      open_order_value: '12450.00',
    },
    trend: { period_label: 'Last 6 months', months },
    request_statuses: [
      { key: 'draft', label: 'Draft', count: 2 },
      { key: 'pending_finance', label: 'Pending Finance', count: 3 },
      { key: 'approved', label: 'Approved', count: 3 },
    ],
    order_statuses: [
      { key: 'in_transit', label: 'In Transit', count: 1 },
      { key: 'completed', label: 'Completed', count: 4 },
    ],
    recent_activity: [
      {
        type: 'Purchase request',
        reference: 'PR-000001',
        description: 'Bulk laces',
        status: 'Pending Finance',
        amount: '1250.00',
        occurred_at: '2026-08-28T08:00:00+08:00',
        url: null,
      },
      {
        type: 'Purchase order',
        reference: 'PO-000001',
        description: 'Premium soles',
        status: 'In Transit',
        amount: '3200.00',
        occurred_at: '2026-08-24T08:00:00+08:00',
        url: null,
      },
    ],
    refreshed_at: '2026-09-02T12:00:00+08:00',
    links: {
      purchase_requests: '/erp/procurement/purchase-request',
      purchase_orders: '/erp/procurement/purchase-orders',
    },
  };

  return { current: dashboard, dashboard };
});

const reload = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  router: { reload },
  usePage: () => ({ props: { dashboard: dashboardState.current } }),
}));

vi.mock('react-apexcharts', () => ({
  default: () => <div data-testid="mock-apex-chart" />,
}));

vi.mock('../../../../layout/AppLayout_ERP', () => ({
  default: ({ children }: React.PropsWithChildren) => <>{children}</>,
}));

beforeEach(() => {
  dashboardState.current = dashboardState.dashboard;
  reload.mockReset();
});

it('renders the rich procurement dashboard read model', () => {
  render(<Dashboard />);

  expect(screen.getByRole('heading', { name: 'Procurement Dashboard' })).toBeInTheDocument();
  expect(screen.queryByText('ERP module')).not.toBeInTheDocument();
  expect(screen.getByText('Awaiting review')).toBeInTheDocument();
  expect(screen.getByText('Open order value')).toBeInTheDocument();
  expect(screen.getAllByTestId('procurement-summary-card')).toHaveLength(4);
  expect(screen.getByTestId('procurement-activity-chart')).toBeInTheDocument();
  expect(screen.getByRole('heading', { name: 'Purchase request status' })).toBeInTheDocument();
  expect(screen.getByRole('heading', { name: 'Purchase order status' })).toBeInTheDocument();
  expect(screen.getByRole('heading', { name: 'Recent activity' })).toBeInTheDocument();
  expect(screen.getByText('PR-000001')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /view purchase requests/i })).toHaveAttribute(
    'href',
    '/erp/procurement/purchase-request',
  );
});

it('refreshes only the dashboard payload while preserving scroll position', () => {
  render(<Dashboard />);

  fireEvent.click(screen.getByRole('button', { name: /refresh data/i }));

  expect(reload).toHaveBeenCalledWith({ only: ['dashboard'], preserveScroll: true });
});

it('renders a zeroed trend and empty activity state when the shop has no records', () => {
  dashboardState.current = {
    ...dashboardState.dashboard,
    summary: {
      purchase_requests: 0,
      awaiting_review: 0,
      purchase_orders: 0,
      open_order_value: '0.00',
    },
    trend: {
      period_label: 'Last 6 months',
      months: dashboardState.dashboard.trend.months.map((month) => ({
        ...month,
        purchase_requests: 0,
        purchase_orders: 0,
      })),
    },
    request_statuses: dashboardState.dashboard.request_statuses.map((status) => ({ ...status, count: 0 })),
    order_statuses: dashboardState.dashboard.order_statuses.map((status) => ({ ...status, count: 0 })),
    recent_activity: [],
  };

  render(<Dashboard />);

  expect(screen.getAllByTestId('procurement-trend-point')).toHaveLength(6);
  expect(screen.getByText(/no recent procurement activity/i)).toBeInTheDocument();
});
