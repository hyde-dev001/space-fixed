import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import CashierDashboard from '../Dashboard';

const cashierState = vi.hoisted(() => ({
    props: {
        dashboard: {
            title: 'Cashier Dashboard',
            description: 'POS activity',
            refreshed_at: '2026-09-02T12:00:00+08:00',
            summary: { today_transactions: 4, today_sales: '400.00', pending_payments: 1, refund_queue: 2 },
            trend: { period_label: 'Daily settled sales', points: [{ label: 'Wed', date: '2026-09-02', transactions: 4, sales: '400.00' }] },
            status_breakdown: [{ status: 'paid', count: 4 }],
            recent_transactions: [{ id: 1, transaction_no: 'POS-1', module_type: 'retail', total_amount: '100.00', paid_amount: '100.00', status: 'paid', created_at: '2026-09-02T09:00:00+08:00' }],
            links: { point_of_sale: '/erp/cashier/point-of-sale' },
        },
    },
    reload: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: React.ReactNode }) => <a href={href}>{children}</a>,
    router: { reload: cashierState.reload },
    usePage: () => cashierState,
}));

vi.mock('../../../../layout/AppLayout_ERP', () => ({
    default: ({ children }: React.PropsWithChildren) => <div>{children}</div>,
}));

vi.mock('react-apexcharts', () => ({ default: () => <div data-testid="cashier-chart" /> }));

beforeEach(() => {
    cashierState.reload.mockReset();
});

it('renders POS-backed cashier metrics and the POS destination', () => {
    render(<CashierDashboard />);

    expect(screen.getByRole('heading', { name: 'Cashier Dashboard' })).toBeInTheDocument();
    expect(screen.getByText("Today's sales")).toBeInTheDocument();
    expect(screen.getByText('₱400.00')).toBeInTheDocument();
    expect(screen.getByText('POS-1')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /open point of sale/i })).toHaveAttribute('href', '/erp/cashier/point-of-sale');
});

it('refreshes through the Inertia dashboard prop', () => {
    render(<CashierDashboard />);

    fireEvent.click(screen.getByRole('button', { name: /refresh dashboard data/i }));

    expect(cashierState.reload).toHaveBeenCalledWith({ only: ['dashboard'], preserveScroll: true });
});
