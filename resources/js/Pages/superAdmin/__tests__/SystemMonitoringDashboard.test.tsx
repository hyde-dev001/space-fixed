import type { ReactNode } from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const pageState = vi.hoisted(() => ({
  dashboard: {
    metrics: { total_users: 10, total_admins: 2, suspended_admins: 1 },
    system_health: [
      { metric: 'Database Connectivity', value: 'Connected', status: 'Excellent' },
      { metric: 'Failed Jobs', value: '1', status: 'Warning' },
    ],
    recent_activity: [{ activity: 'User suspended', time: '1 minute ago', status: 'Info' }],
    performance_metrics: [{ metric: 'Total Admin Accounts', value: '2', status: 'Snapshot' }],
    systems_operational: true,
  },
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href, ...props }: { children?: ReactNode; href?: string; [key: string]: unknown }) => (
    <a href={href} {...props}>{children}</a>
  ),
  usePage: () => ({ props: { dashboard: pageState.dashboard } }),
}));

vi.mock('../../../layout/AppLayout', () => ({
  default: ({ children }: { children?: ReactNode }) => <div>{children}</div>,
}));

import SystemMonitoringDashboard from '../SystemMonitoringDashboard';

describe('SystemMonitoringDashboard', () => {
  it('uses measured labels, one real audit destination, and no inert detail buttons', () => {
    render(<SystemMonitoringDashboard />);

    expect(screen.getByText('Current operational snapshots')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /view audit history/i })).toHaveAttribute('href', '/admin/audit');
    expect(screen.queryAllByRole('button', { name: /see all/i })).toHaveLength(0);
    expect(screen.queryByText('Performance Metrics')).not.toBeInTheDocument();
    expect(screen.queryByText('Live')).not.toBeInTheDocument();
    expect(screen.queryByText('All Systems Operational')).not.toBeInTheDocument();
    expect(screen.getByText(/database connectivity/i)).toBeInTheDocument();
    expect(screen.getByText(/failed jobs/i)).toBeInTheDocument();
  });

  it('does not claim all systems are operational when the server reports a failed health check', () => {
    pageState.dashboard.systems_operational = false;
    render(<SystemMonitoringDashboard />);

    expect(screen.queryByText('All Systems Operational')).not.toBeInTheDocument();
    expect(screen.getByText('Database attention required')).toBeInTheDocument();
    expect(screen.getByText('Failed Jobs')).toBeInTheDocument();
  });
});
