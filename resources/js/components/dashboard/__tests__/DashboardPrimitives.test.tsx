import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
  DashboardMetricCard,
  DashboardPanel,
  DashboardShell,
  DashboardState,
  DashboardTrendChart,
} from '../index';

vi.mock('react-apexcharts', () => ({
  default: () => <div data-testid="dashboard-chart-renderer" />,
}));

describe('dashboard primitives', () => {
  it('keeps the shared shell structure and exposes refresh semantics', () => {
    const onRefresh = vi.fn();

    render(
      <DashboardShell
        testId="shared-dashboard"
        eyebrow="ERP module"
        title="Shared dashboard"
        description="A current operational view."
        snapshotDescription="Current shop records"
        onRefresh={onRefresh}
        refreshedAt="2026-09-02T12:00:00+08:00"
      >
        <p>Dashboard content</p>
      </DashboardShell>,
    );

    expect(screen.getByTestId('shared-dashboard')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Shared dashboard' })).toBeInTheDocument();
    expect(screen.getByText('Operational snapshot')).toBeInTheDocument();
    expect(screen.getByText('Dashboard content')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /refresh dashboard data/i }));
    expect(onRefresh).toHaveBeenCalledTimes(1);
  });

  it('renders a neutral metric card with an optional destination', () => {
    render(
      <DashboardMetricCard
        label="Open tasks"
        value="12"
        description="Assigned work waiting for completion"
        context="Workflow"
        icon={() => <span aria-hidden="true">icon</span>}
        href="/erp/staff/job-orders"
      />,
    );

    expect(screen.getByTestId('dashboard-metric-card')).toHaveAttribute('aria-label', 'Open tasks');
    expect(screen.getByRole('link', { name: /open tasks/i })).toHaveAttribute('href', '/erp/staff/job-orders');
  });

  it('keeps panels and state messages accessible', () => {
    const onRetry = vi.fn();

    render(
      <DashboardPanel title="Needs attention" description="Review the current queue.">
        <DashboardState
          status="error"
          title="Unable to load"
          message="Try again."
          onRetry={onRetry}
        />
      </DashboardPanel>,
    );

    expect(screen.getByRole('heading', { name: 'Needs attention' })).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('Unable to load');
    fireEvent.click(screen.getByRole('button', { name: /try again/i }));
    expect(onRetry).toHaveBeenCalledTimes(1);
  });

  it('shows a truthful no-data state instead of mounting an empty chart', () => {
    render(
      <DashboardTrendChart
        title="Activity trend"
        categories={[]}
        series={[]}
        emptyMessage="No activity recorded yet."
      />,
    );

    expect(screen.getByText('No activity recorded yet.')).toBeInTheDocument();
    expect(screen.queryByTestId('dashboard-chart-renderer')).not.toBeInTheDocument();
  });
});
