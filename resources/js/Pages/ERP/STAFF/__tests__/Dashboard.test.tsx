import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import StaffDashboard from '../Dashboard';

const staffState = vi.hoisted(() => ({
    props: {
        dashboard: {
            title: 'Staff Dashboard',
            description: 'Assigned work',
            refreshed_at: '2026-09-02T12:00:00+08:00',
            summary: { assigned_open_work: 3, active_orders: 2, completed_today: 1, attendance_status: 'present' },
            attendance: { status: 'present', label: 'Present', recorded_at: '2026-09-02T09:00:00+08:00' },
            trend: { period_label: 'Assigned orders received', points: [{ label: 'Apr', start: '2026-04-01', assigned_orders: 2 }] },
            recent_work: [{ id: 1, reference: 'ORD-1', status: 'processing', created_at: '2026-09-02T09:00:00+08:00' }],
            links: { orders: '/erp/staff/job-orders', customers: '/erp/staff/customers', attendance: '/erp/time-in' },
        },
    },
    reload: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: React.ReactNode }) => <a href={href}>{children}</a>,
    router: { reload: staffState.reload },
    usePage: () => staffState,
}));

vi.mock('../../../../layout/AppLayout_ERP', () => ({
    default: ({ children }: React.PropsWithChildren) => <div>{children}</div>,
}));

vi.mock('react-apexcharts', () => ({ default: () => <div data-testid="staff-chart" /> }));

beforeEach(() => {
    staffState.reload.mockReset();
});

it('renders the staff workload snapshot and its existing destinations', () => {
    render(<StaffDashboard />);

    expect(screen.getByRole('heading', { name: 'Staff Dashboard' })).toBeInTheDocument();
    expect(screen.getByText('Assigned open work')).toBeInTheDocument();
    expect(screen.getByText('3')).toBeInTheDocument();
    expect(screen.getByText('ORD-1')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /open job orders/i })).toHaveAttribute('href', '/erp/staff/job-orders');
    expect(screen.getByRole('link', { name: /view customers/i })).toHaveAttribute('href', '/erp/staff/customers');
});

it('refreshes through the Inertia dashboard prop', () => {
    render(<StaffDashboard />);

    fireEvent.click(screen.getByRole('button', { name: /refresh dashboard data/i }));

    expect(staffState.reload).toHaveBeenCalledWith({ only: ['dashboard'], preserveScroll: true });
});
