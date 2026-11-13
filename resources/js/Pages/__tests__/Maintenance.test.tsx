import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Maintenance from '../Maintenance';

const { routerVisit, refresh } = vi.hoisted(() => ({
  routerVisit: vi.fn(),
  refresh: vi.fn(),
}));

const maintenanceContext = vi.hoisted(() => ({
  state: {
    state: 'active' as const,
    id: 42,
    title: 'Platform upgrade',
    message: 'We are applying a short update.',
    starts_at: '2026-09-14T15:00:00.000Z',
    ends_at: '2026-09-14T15:30:00.000Z',
    notify_before_minutes: 15,
    transaction_freeze_minutes: 3,
    progress_stage: 'Final Checks',
    update_message: 'Verifying the payment service.',
    update_message_updated_at: '2026-09-14T15:10:00.000Z',
    server_time: '2026-09-14T15:10:00.000Z',
  },
  refreshError: false,
  serverNow: () => Date.parse('2026-09-14T15:10:00.000Z'),
}));

vi.mock('@inertiajs/react', () => ({
  Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
  router: { visit: routerVisit },
}));

vi.mock('../../providers/MaintenanceProvider', () => ({
  useMaintenance: () => ({
    ...maintenanceContext,
    refresh,
  }),
}));

vi.mock('../../layout/AppLayout', () => ({
  default: ({ children }: React.PropsWithChildren) => <div>{children}</div>,
}));

beforeEach(() => {
  routerVisit.mockReset();
  refresh.mockReset();
  maintenanceContext.state = {
    ...maintenanceContext.state,
    state: 'active',
  };
  maintenanceContext.refreshError = false;
});

describe('Maintenance page', () => {
  it('shows active maintenance details and refreshes on demand', () => {
    render(<Maintenance status={maintenanceContext.state} safe_return_to="/" />);

    expect(screen.getByRole('heading', { name: 'Platform upgrade' })).toBeInTheDocument();
    expect(screen.getByText('Final Checks')).toBeInTheDocument();
    expect(screen.getByText('Verifying the payment service.')).toBeInTheDocument();
    expect(screen.getByText(/expected completion/i)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Check Again' }));
    expect(refresh).toHaveBeenCalledTimes(1);
    expect(routerVisit).not.toHaveBeenCalled();
  });

  it('retains the last known state and explains failed refreshes', () => {
    maintenanceContext.refreshError = true;
    render(<Maintenance status={maintenanceContext.state} safe_return_to="/" />);

    expect(screen.getByRole('alert')).toHaveTextContent(/could not refresh/i);
    expect(screen.getByRole('heading', { name: 'Platform upgrade' })).toBeInTheDocument();
    expect(screen.queryByText(/service restored/i)).not.toBeInTheDocument();
  });

  it('shows Service Restored without redirecting and uses a canonical GET return', () => {
    maintenanceContext.state = { state: 'operational', server_time: '2026-09-14T15:31:00.000Z' };
    render(<Maintenance status={maintenanceContext.state} safe_return_to="/shop-owner/home" />);

    expect(screen.getByRole('status')).toHaveTextContent('Service Restored');
    expect(routerVisit).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('button', { name: 'Safe Return' }));
    expect(routerVisit).toHaveBeenCalledWith('/shop-owner/home', { method: 'get', replace: true });
  });

  it('shows unavailable status without treating it as restored', () => {
    maintenanceContext.state = { state: 'unavailable', server_time: '2026-09-14T15:31:00.000Z' };
    render(<Maintenance status={maintenanceContext.state} safe_return_to="/" />);

    expect(screen.getByRole('heading', { name: /maintenance status unavailable/i })).toBeInTheDocument();
    expect(screen.queryByText(/service restored/i)).not.toBeInTheDocument();
  });
});
