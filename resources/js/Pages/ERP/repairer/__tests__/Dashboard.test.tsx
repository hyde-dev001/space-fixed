import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import DashboardRepair from '../dashboardRepair';

const pageState = vi.hoisted(() => ({
    props: {
        initialDashboard: {
            metricCards: [],
            requestedServices: [],
            revenueRows: [],
            recentRepairs: [],
        },
        auth: { erpActor: { ownerMode: true } },
    },
}));

const axiosState = vi.hoisted(() => ({
    get: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: React.ReactNode }) => <a href={href}>{children}</a>,
    usePage: () => pageState,
}));

vi.mock('axios', () => ({
    default: axiosState,
}));

vi.mock('@/layout/AppLayout_ERP', () => ({
    default: ({ children }: React.PropsWithChildren) => <div>{children}</div>,
}));

vi.mock('@/utils/erpCapabilities', () => ({
    erpUrl: () => null,
}));

vi.mock('react-apexcharts', () => ({ default: () => <div data-testid="repair-dashboard-chart" /> }));

beforeEach(() => {
    axiosState.get.mockReset();
    pageState.props = {
        ...pageState.props,
        auth: { erpActor: { ownerMode: true } },
    };
});

it('renders the repair dashboard without a runtime hook error', () => {
    render(<DashboardRepair />);

    expect(screen.getByRole('heading', { name: 'Repair Dashboard' })).toBeInTheDocument();
    expect(screen.queryByText('ERP module')).not.toBeInTheDocument();
});

it('uses shared metric cards and dark-mode surfaces for package analytics', async () => {
    pageState.props = {
        ...pageState.props,
        auth: { erpActor: { ownerMode: false } },
    };
    axiosState.get.mockResolvedValueOnce({
        data: {
            success: true,
            data: {
                overview: {
                    total_packages: 2,
                    active_packages: 2,
                    inactive_packages: 0,
                    total_bookings: 18,
                    package_revenue: 6579.45,
                    package_base_revenue: 6000,
                    add_on_revenue: 0,
                    average_order_value: 365.53,
                    add_on_attach_rate: 0,
                    bookings_last_30_days: 18,
                    revenue_last_30_days: 6579.45,
                },
                top_packages: [],
                monthly_trend: [{ month: 'Sep 2026', bookings: 18, revenue: 6579.45 }],
                recent_bookings: [],
            },
        },
    });

    render(<DashboardRepair />);

    await waitFor(() => expect(screen.getAllByTestId('dashboard-metric-card')).toHaveLength(5));

    const packageAnalytics = screen.getByTestId('repair-package-analytics');
    const packageCards = within(packageAnalytics).getAllByTestId('dashboard-metric-card');

    expect(packageAnalytics).toHaveClass('dark:bg-white/[0.03]');
    expect(packageCards).toHaveLength(5);
    expect(packageCards.map((card) => card.getAttribute('aria-label'))).toEqual([
        'Packages',
        'Bookings',
        'Net Revenue (Excl. VAT)',
        'Avg Order',
        'Add-on Attach Rate',
    ]);
    expect(within(packageCards[2]).getByText('₱6579.45')).toBeInTheDocument();
});
