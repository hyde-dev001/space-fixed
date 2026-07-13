import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import LogisticsSettings from '../Settings';

const mocks = vi.hoisted(() => ({
  put: vi.fn().mockResolvedValue({ data: {} }),
  settings: {
    operating_days: [1, 2, 3, 4, 5],
    cutoff_time: '15:00:00',
    blackout_dates: [],
    lead_time_days: 1,
    morning_start: '08:00:00',
    morning_end: '12:00:00',
    afternoon_start: '13:00:00',
    afternoon_end: '18:00:00',
    coverage_radius_km: '20.00',
    daily_rider_capacity: 20,
    max_delivery_attempts: 2,
  },
}));

vi.mock('axios', () => ({ default: { put: mocks.put } }));
vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  usePage: () => ({ props: { settings: mocks.settings } }),
}));
vi.mock('@/layout/AppLayout_ERP', () => ({
  default: ({ children }: React.PropsWithChildren) => <>{children}</>,
}));

it('submits untouched time settings without seconds', async () => {
  render(<LogisticsSettings />);
  fireEvent.click(screen.getByRole('button', { name: 'Save' }));

  await waitFor(() => expect(mocks.put).toHaveBeenCalledWith('/api/logistics/settings', expect.objectContaining({
    cutoff_time: '15:00',
    morning_start: '08:00',
    morning_end: '12:00',
    afternoon_start: '13:00',
    afternoon_end: '18:00',
  })));
});
