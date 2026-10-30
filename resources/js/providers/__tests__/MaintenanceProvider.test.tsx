import React, { useEffect } from 'react';
import { act, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { MaintenanceProvider, useMaintenance } from '../MaintenanceProvider';

const { axiosGet, routerListeners, routerVisit } = vi.hoisted(() => ({
  axiosGet: vi.fn(),
  routerListeners: new Map<string, Set<(event?: unknown) => void>>(),
  routerVisit: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  router: {
    on: vi.fn((eventName: string, callback: (event?: unknown) => void) => {
      const callbacks = routerListeners.get(eventName) ?? new Set();
      callbacks.add(callback);
      routerListeners.set(eventName, callbacks);
      return () => callbacks.delete(callback);
    }),
    visit: routerVisit,
  },
}));

function emitRouter(eventName: string, event?: unknown): void {
  routerListeners.get(eventName)?.forEach((callback) => callback(event));
}

function Consumer(): React.JSX.Element {
  const { state, isFreezeActive, isRouteFrozen, registerDirtySource, refreshError, serverNow } = useMaintenance();

  useEffect(() => registerDirtySource('checkout-form', true), [registerDirtySource]);

  return (
    <>
      <output data-testid="maintenance-state">{state.state}</output>
      <output data-testid="freeze-state">{String(isFreezeActive)}</output>
      <output data-testid="route-freeze">{String(isRouteFrozen('checkout.create-order'))}</output>
      <output data-testid="refresh-error">{String(refreshError)}</output>
      <output data-testid="server-now">{serverNow()}</output>
    </>
  );
}

function renderProvider(): void {
  render(
    <MaintenanceProvider>
      <Consumer />
    </MaintenanceProvider>,
  );
}

const scheduledResponse = {
  data: {
    state: 'scheduled',
    id: 42,
    title: 'Platform upgrade',
    message: 'A short maintenance window.',
    starts_at: '2026-09-14T15:00:00.000000Z',
    ends_at: '2026-09-14T15:30:00.000000Z',
    notify_before_minutes: 15,
    transaction_freeze_minutes: 3,
    progress_stage: null,
    update_message: null,
    update_message_updated_at: null,
    server_time: '2026-09-14T14:55:00.000Z',
  },
};

beforeEach(() => {
  vi.useRealTimers();
  localStorage.clear();
  axiosGet.mockReset();
  routerVisit.mockReset();
  routerListeners.clear();
  Object.defineProperty(window, 'axios', {
    configurable: true,
    value: { get: axiosGet },
  });
  window.history.replaceState({}, '', '/');
});

describe('MaintenanceProvider', () => {
  it('fetches once and polls on the shared 30-second cadence', async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-09-14T14:55:00.000Z'));
    axiosGet.mockResolvedValue(scheduledResponse);

    renderProvider();
    await act(async () => {});
    expect(axiosGet).toHaveBeenCalledTimes(1);

    await act(async () => {
      vi.advanceTimersByTime(30_000);
    });
    expect(axiosGet).toHaveBeenCalledTimes(2);
  });

  it('resynchronizes after navigation and visible-tab changes', async () => {
    axiosGet.mockResolvedValue(scheduledResponse);
    renderProvider();
    await waitFor(() => expect(axiosGet).toHaveBeenCalledTimes(1));

    await act(async () => emitRouter('navigate'));
    await act(async () => document.dispatchEvent(new Event('visibilitychange')));

    expect(axiosGet).toHaveBeenCalledTimes(3);
  });

  it('uses server time for freeze state and warns without storing form data', async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-09-14T14:58:00.000Z'));
    axiosGet.mockResolvedValue({
      data: { ...scheduledResponse.data, server_time: '2026-09-14T14:58:00.000Z' },
    });

    renderProvider();
    await act(async () => {});
    expect(screen.getByTestId('maintenance-state')).toHaveTextContent('scheduled');

    expect(screen.getByTestId('freeze-state')).toHaveTextContent('true');
    expect(screen.getByTestId('route-freeze')).toHaveTextContent('true');
    expect(screen.getByText('Platform upgrade')).toBeInTheDocument();
    expect(screen.getByRole('dialog', { name: /maintenance warning/i })).toBeInTheDocument();
    expect(localStorage.getItem('maintenance-warning:42')).toBe('1');
    expect(localStorage.length).toBe(1);
    expect(screen.getByTestId('server-now')).toHaveTextContent(String(Date.parse('2026-09-14T14:58:00.000Z')));
  });

  it('retains the last successful state when a refresh fails', async () => {
    axiosGet.mockResolvedValueOnce({ data: { ...scheduledResponse.data, state: 'active' } });
    axiosGet.mockRejectedValueOnce(new Error('offline'));
    renderProvider();
    await waitFor(() => expect(screen.getByTestId('maintenance-state')).toHaveTextContent('active'));

    await act(async () => emitRouter('navigate'));

    expect(screen.getByTestId('maintenance-state')).toHaveTextContent('active');
    expect(screen.getByTestId('refresh-error')).toHaveTextContent('true');
  });

  it('transitions to maintenance only once for concurrent active events', async () => {
    axiosGet.mockReturnValue(new Promise(() => undefined));
    renderProvider();

    await act(async () => {
      window.dispatchEvent(new CustomEvent('solespace:maintenance-active'));
      window.dispatchEvent(new CustomEvent('solespace:maintenance-active'));
    });

    expect(routerVisit).toHaveBeenCalledTimes(1);
    expect(routerVisit).toHaveBeenCalledWith('/maintenance', { replace: true });
  });

  it('does not redirect or show public maintenance UI inside the admin portal', async () => {
    window.history.replaceState({}, '', '/admin/maintenance');
    axiosGet.mockResolvedValue({
      data: { ...scheduledResponse.data, state: 'active' },
    });

    renderProvider();
    await waitFor(() => expect(axiosGet).toHaveBeenCalledTimes(1));

    expect(routerVisit).not.toHaveBeenCalled();
    expect(screen.queryByText('Platform upgrade')).not.toBeInTheDocument();
  });
});
