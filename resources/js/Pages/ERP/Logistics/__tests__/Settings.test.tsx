import React from 'react';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import LogisticsSettings from '../Settings';

const mocks = vi.hoisted(() => ({
  put: vi.fn().mockResolvedValue({ data: {} }),
  success: vi.fn(),
  error: vi.fn(),
  confirm: vi.fn(),
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
    arrival_radius_m: 100,
    daily_rider_capacity: 20,
    max_delivery_attempts: 2,
  },
  shopLocation: {
    latitude: 14.599512 as number | null,
    longitude: 120.984222 as number | null,
    address: '123 Test Street',
  },
}));

vi.mock('axios', () => ({ default: { put: mocks.put } }));
vi.mock('@/utils/workflowFeedback', () => ({
  workflowFeedback: { success: mocks.success, error: mocks.error, confirm: mocks.confirm },
}));
vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  usePage: () => ({ props: { settings: mocks.settings, shopLocation: mocks.shopLocation } }),
}));
vi.mock('@/layout/AppLayout_ERP', () => ({
  default: ({ children }: React.PropsWithChildren) => <>{children}</>,
}));
vi.mock('../DeliveryCoverageMap', () => ({
  default: ({ latitude, longitude, radiusKm }: { latitude: number; longitude: number; radiusKm: number }) => (
    <div
      data-testid="delivery-coverage-map"
      data-latitude={latitude}
      data-longitude={longitude}
      data-radius-km={radiusKm}
    />
  ),
}));

beforeEach(() => {
  mocks.put.mockReset().mockResolvedValue({ data: {} });
  mocks.success.mockReset();
  mocks.error.mockReset();
  mocks.confirm.mockReset().mockResolvedValue({ isConfirmed: true });
  mocks.shopLocation.latitude = 14.599512;
  mocks.shopLocation.longitude = 120.984222;
});

it('explains that rider capacity is measured in delivery stops', () => {
  render(<LogisticsSettings />);

  expect(screen.getByLabelText('Daily delivery stops per rider')).toHaveValue(20);
  expect(screen.getByText('One delivery address counts as one stop, regardless of item quantity.')).toBeInTheDocument();
});

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

it('commits a selected time only after confirming the picker', async () => {
  render(<LogisticsSettings />);

  fireEvent.click(screen.getByRole('button', { name: 'Cutoff, 03:00 PM' }));
  expect(screen.getByRole('dialog', { name: 'Set cutoff time' })).toBeInTheDocument();

  fireEvent.click(within(screen.getByTestId('time-picker-hour')).getByRole('option', { name: '04', exact: true }));
  fireEvent.click(within(screen.getByTestId('time-picker-minute')).getByRole('option', { name: '30', exact: true }));
  fireEvent.click(within(screen.getByTestId('time-picker-period')).getByRole('option', { name: 'PM', exact: true }));
  fireEvent.click(screen.getByRole('button', { name: 'Done' }));

  expect(screen.getByRole('button', { name: 'Cutoff, 04:30 PM' })).toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'Save' }));
  await waitFor(() => expect(mocks.put).toHaveBeenCalledWith('/api/logistics/settings', expect.objectContaining({
    cutoff_time: '16:30',
  })));
});

it('discards a time picker draft when canceled', () => {
  render(<LogisticsSettings />);

  fireEvent.click(screen.getByRole('button', { name: 'Cutoff, 03:00 PM' }));
  fireEvent.click(within(screen.getByTestId('time-picker-hour')).getByRole('option', { name: '07', exact: true }));
  fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

  expect(screen.getByRole('button', { name: 'Cutoff, 03:00 PM' })).toBeInTheDocument();
  expect(screen.queryByRole('dialog', { name: 'Set cutoff time' })).not.toBeInTheDocument();
});

it('supports dragging a wheel column to the next time value', () => {
  render(<LogisticsSettings />);
  fireEvent.click(screen.getByRole('button', { name: 'Cutoff, 03:00 PM' }));

  const hourWheel = screen.getByTestId('time-picker-hour');
  fireEvent.pointerDown(hourWheel, { pointerId: 1, clientY: 200, button: 0 });
  fireEvent.pointerMove(hourWheel, { pointerId: 1, clientY: 152 });
  fireEvent.pointerUp(hourWheel, { pointerId: 1, clientY: 152 });

  expect(within(hourWheel).getByRole('option', { name: '04', exact: true })).toHaveAttribute('aria-selected', 'true');
});

it('updates the active wheel option while the column is scrolled', () => {
  render(<LogisticsSettings />);
  fireEvent.click(screen.getByRole('button', { name: 'Cutoff, 03:00 PM' }));

  const periodWheel = screen.getByTestId('time-picker-period');
  Object.defineProperty(periodWheel, 'scrollTop', { configurable: true, writable: true, value: 48 });
  fireEvent.scroll(periodWheel);

  expect(within(periodWheel).getByRole('option', { name: 'PM', exact: true }))
    .toHaveClass('border-blue-200', 'bg-blue-50/70');
  expect(within(periodWheel).getByRole('option', { name: 'AM', exact: true }))
    .not.toHaveClass('border-blue-200', 'bg-blue-50/70');
  expect(screen.getByText('Selected 03:00 PM')).toBeInTheDocument();
});

it('centers and clearly marks the active value in every wheel column', () => {
  render(<LogisticsSettings />);
  fireEvent.click(screen.getByRole('button', { name: 'Morning start, 08:00 AM' }));

  const hourWheel = screen.getByTestId('time-picker-hour');
  const minuteWheel = screen.getByTestId('time-picker-minute');
  const periodWheel = screen.getByTestId('time-picker-period');

  expect(within(hourWheel).getByRole('option', { name: '08', exact: true })).toHaveClass('text-gray-950');
  expect(within(minuteWheel).getByRole('option', { name: '00', exact: true })).toHaveClass('text-gray-950');
  expect(within(periodWheel).getByRole('option', { name: 'AM', exact: true })).toHaveClass('text-gray-950');
  expect(hourWheel).toHaveProperty('scrollTop', 7 * 48);
});

it('shows a success alert after saving', async () => {
  render(<LogisticsSettings />);
  fireEvent.click(screen.getByRole('button', { name: 'Save' }));

  await waitFor(() => expect(mocks.success).toHaveBeenCalledWith({
    title: 'Settings saved',
    text: 'Logistics settings were updated successfully.',
  }));
});

it('shows Laravel validation messages when saving fails', async () => {
  mocks.put.mockRejectedValue({
    response: { data: { errors: {
      operating_days: ['Select at least one operating day.'],
      afternoon_start: ['Afternoon start must be after morning end.'],
    } } },
  });
  render(<LogisticsSettings />);
  fireEvent.click(screen.getByRole('button', { name: 'Save' }));

  await waitFor(() => expect(mocks.error).toHaveBeenCalledWith(
    'Select at least one operating day.\nAfternoon start must be after morning end.',
    'Check your settings',
  ));
});

it('discards unsaved changes after confirmation', async () => {
  render(<LogisticsSettings />);
  fireEvent.change(screen.getByLabelText('Lead days'), { target: { value: '4' } });
  fireEvent.change(screen.getByLabelText('Blackout date'), { target: { value: '2026-12-25' } });
  fireEvent.click(screen.getByRole('button', { name: 'Discard changes' }));

  await waitFor(() => expect(screen.getByLabelText('Lead days')).toHaveValue(1));
  expect(screen.getByLabelText('Blackout date')).toHaveValue('');
  expect(mocks.confirm).toHaveBeenCalled();
});

it('prevents adding a past blackout date', () => {
  render(<LogisticsSettings />);
  fireEvent.change(screen.getByLabelText('Blackout date'), { target: { value: '2001-04-04' } });

  expect(screen.getByRole('button', { name: 'Add' })).toBeDisabled();
});

it('keeps blackout controls and actions balanced on narrow screens', () => {
  render(<LogisticsSettings />);

  expect(screen.getByLabelText('Blackout date')).toHaveClass('min-w-0', 'flex-1');
  expect(screen.getByRole('button', { name: 'Add' })).toHaveClass('min-h-11', 'shrink-0');
  expect(screen.getByRole('button', { name: 'Save' })).toHaveClass('w-full');
  expect(screen.getByRole('button', { name: 'Discard changes' })).toHaveClass('w-full');
});

it('shows the saved service area and arrival check radius', () => {
  render(<LogisticsSettings />);

  expect(screen.getByLabelText('Arrival check radius (metres)')).toHaveValue(100);
  expect(screen.getByText("Used when riders tap I've arrived at customer locations.")).toBeInTheDocument();

  const map = screen.getByTestId('delivery-coverage-map');
  expect(map).toHaveAttribute('data-latitude', '14.599512');
  expect(map).toHaveAttribute('data-longitude', '120.984222');
  expect(map).toHaveAttribute('data-radius-km', '20');

  fireEvent.change(screen.getByLabelText('Coverage radius (km)'), { target: { value: '12.5' } });
  expect(map).toHaveAttribute('data-radius-km', '12.5');
});

it('links to Shop Settings when the saved shop pin is missing', () => {
  mocks.shopLocation.latitude = null;
  mocks.shopLocation.longitude = null;

  render(<LogisticsSettings />);

  expect(screen.queryByTestId('delivery-coverage-map')).not.toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'Set the shop location in Shop Settings' }))
    .toHaveAttribute('href', '/shop-owner/settings');
});
