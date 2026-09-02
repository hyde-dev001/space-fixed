import React from 'react';
import { render, screen } from '@testing-library/react';
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

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: React.ReactNode }) => <a href={href}>{children}</a>,
    usePage: () => pageState,
}));

vi.mock('@/layout/AppLayout_ERP', () => ({
    default: ({ children }: React.PropsWithChildren) => <div>{children}</div>,
}));

vi.mock('@/utils/erpCapabilities', () => ({
    erpUrl: () => null,
}));

vi.mock('react-apexcharts', () => ({ default: () => <div data-testid="repair-dashboard-chart" /> }));

beforeEach(() => {
    pageState.props = {
        ...pageState.props,
        auth: { erpActor: { ownerMode: true } },
    };
});

it('renders the repair dashboard without a runtime hook error', () => {
    render(<DashboardRepair />);

    expect(screen.getByRole('heading', { name: 'Repair Dashboard' })).toBeInTheDocument();
});
