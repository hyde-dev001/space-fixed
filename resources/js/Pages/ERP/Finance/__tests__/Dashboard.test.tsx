import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import FinanceDashboard from '../Dashboard';

const getSummary = vi.fn();

vi.mock('../../../../hooks/useFinanceApi', () => ({
  useFinanceApi: () => ({ get: getSummary }),
}));

vi.mock('../../../../layout/AppLayout_ERP', () => ({
  default: ({ children }: React.PropsWithChildren) => <div>{children}</div>,
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  usePage: () => ({ props: { auth: { user: { name: 'Owner' } } } }),
}));

vi.mock('react-apexcharts', () => ({ default: () => <div data-testid="trend-chart" /> }));

const summary = {
  period: { type: 'current_year', start: '2026-01-01', end: '2026-12-31', timezone: 'Asia/Manila' },
  primary: {
    net_revenue: '110.00',
    incurred_expenses: '20.00',
    net_operating_result: '90.00',
    net_cash_movement: '80.00',
  },
  supporting: { gross_revenue: '120.00', executed_refunds: '10.00', paid_expenses: '30.00' },
  trend: Array.from({ length: 6 }, (_, index) => ({ month: `2026-${String(index + 1).padStart(2, '0')}`, net_revenue: '10.00', incurred_expenses: '2.00', net_cash_movement: '8.00' })),
  definitions: { net_revenue: 'Gross revenue less executed refunds.' },
  integrity_warnings: [],
};

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={queryClient}><FinanceDashboard /></QueryClientProvider>);
}

beforeEach(() => {
  getSummary.mockReset();
  getSummary.mockResolvedValue({ ok: true, status: 200, data: summary });
});

it('renders backend-owned primary and supporting metrics without client comparisons', async () => {
  renderPage();

  await waitFor(() => expect(screen.getByText('₱110.00')).toBeInTheDocument());
  expect(screen.getByText('Net operating result')).toBeInTheDocument();
  expect(screen.getByText('Gross revenue')).toBeInTheDocument();
  expect(screen.getByTestId('trend-chart')).toBeInTheDocument();
  expect(screen.queryByText(/statutory profit/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/\+15%|15%/)).not.toBeInTheDocument();
});

it('shows a forbidden state without replacing it with empty tax-like data', async () => {
  getSummary.mockResolvedValue({ ok: false, status: 403, error: 'Forbidden' });
  renderPage();

  await waitFor(() => expect(screen.getByText(/do not have access/i)).toBeInTheDocument());
  expect(screen.queryByText('₱0.00')).not.toBeInTheDocument();
});
