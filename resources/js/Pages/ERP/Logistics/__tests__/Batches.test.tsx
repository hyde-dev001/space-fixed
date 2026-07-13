import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import Batches from '../Batches';

const mocks = vi.hoisted(() => ({
  props: {} as Record<string, unknown>,
  scheduleLegs: vi.fn(),
  createBatch: vi.fn(),
  offerBatch: vi.fn(),
  reload: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  router: { reload: mocks.reload },
  usePage: () => ({ props: mocks.props }),
}));
vi.mock('@/layout/AppLayout_ERP', () => ({ default: ({ children }: React.PropsWithChildren) => <>{children}</> }));
vi.mock('@/services/logisticsApi', () => ({ logisticsApi: {
  scheduleLegs: mocks.scheduleLegs,
  createBatch: mocks.createBatch,
  offerBatch: mocks.offerBatch,
  suggestions: vi.fn(), updateBatch: vi.fn(), removeBatchStop: vi.fn(), markUrgent: vi.fn(),
} }));

beforeEach(() => {
  vi.clearAllMocks();
  mocks.props = {
    batches: [],
    pool: [],
    riders: [{ id: 3, name: 'Rider One', active: true, availability_status: 'available', rider_type: 'employee', daily_capacity: 10 }],
    unscheduled: [{ id: 7, status: 'pending', shipment: { source_type: 'order', source_id: 55 } }],
  };
  mocks.scheduleLegs.mockResolvedValue({});
  mocks.createBatch.mockResolvedValue({ data: { batch: { id: 41 } } });
  mocks.offerBatch.mockResolvedValue({});
});

function selectOrderAndSchedule() {
  fireEvent.click(screen.getByRole('checkbox', { name: /order #55/i }));
  fireEvent.change(screen.getByLabelText('Delivery date'), { target: { value: '2026-07-15' } });
}

it('schedules, creates, then offers a batch to the selected rider', async () => {
  render(<Batches />);
  selectOrderAndSchedule();
  fireEvent.change(screen.getByLabelText('Rider'), { target: { value: '3' } });
  fireEvent.click(screen.getByRole('button', { name: 'Create & offer batch' }));

  await waitFor(() => expect(mocks.offerBatch).toHaveBeenCalledWith(41, 3));
  expect(mocks.scheduleLegs).toHaveBeenCalledWith([7], '2026-07-15', 'morning');
  expect(mocks.createBatch).toHaveBeenCalledWith({ delivery_date: '2026-07-15', delivery_window: 'morning', leg_ids: [7] });
  expect(mocks.scheduleLegs.mock.invocationCallOrder[0]).toBeLessThan(mocks.createBatch.mock.invocationCallOrder[0]);
  expect(mocks.createBatch.mock.invocationCallOrder[0]).toBeLessThan(mocks.offerBatch.mock.invocationCallOrder[0]);
});

it('creates a draft when no rider is selected', async () => {
  render(<Batches />);
  selectOrderAndSchedule();
  fireEvent.click(screen.getByRole('button', { name: 'Create draft batch' }));

  await waitFor(() => expect(mocks.createBatch).toHaveBeenCalled());
  expect(mocks.offerBatch).not.toHaveBeenCalled();
});

it('schedules only the unscheduled subset of a mixed selection', async () => {
  mocks.props.pool = [{ id: 8, status: 'pending', scheduled_delivery_date: '2026-07-15', delivery_window: 'morning' }];
  render(<Batches />);
  selectOrderAndSchedule();
  fireEvent.click(screen.getByRole('checkbox', { name: /leg #8/i }));
  fireEvent.click(screen.getByRole('button', { name: 'Create draft batch' }));

  await waitFor(() => expect(mocks.createBatch).toHaveBeenCalled());
  expect(mocks.scheduleLegs).toHaveBeenCalledWith([7], '2026-07-15', 'morning');
  expect(mocks.createBatch).toHaveBeenCalledWith({ delivery_date: '2026-07-15', delivery_window: 'morning', leg_ids: [7, 8] });
});

it('selects only eligible deliveries and shows useful dispatch details', () => {
  mocks.props.pool = [
    { id: 8, status: 'pending', scheduled_delivery_date: '2026-07-15', delivery_window: 'morning' },
    { id: 9, status: 'pending', scheduled_delivery_date: '2026-07-16', delivery_window: 'morning' },
  ];
  render(<Batches />);
  fireEvent.change(screen.getByLabelText('Delivery date'), { target: { value: '2026-07-15' } });
  fireEvent.change(screen.getByLabelText('Rider'), { target: { value: '3' } });
  fireEvent.click(screen.getByRole('checkbox', { name: 'Select all eligible deliveries' }));

  expect(screen.getByText('2 selected')).toBeInTheDocument();
  expect(screen.getByText('Rider capacity: 10 stops')).toBeInTheDocument();
  expect(screen.getByText(/Jul 15, 2026 · Morning/)).toBeInTheDocument();
  expect(screen.getByRole('checkbox', { name: /leg #9/i })).toBeDisabled();
});

it('disables duplicate submission while batch creation is pending', async () => {
  let resolveCreate!: (value: unknown) => void;
  mocks.createBatch.mockReturnValue(new Promise((resolve) => { resolveCreate = resolve; }));
  render(<Batches />);
  selectOrderAndSchedule();
  fireEvent.click(screen.getByRole('button', { name: 'Create draft batch' }));

  expect(await screen.findByRole('button', { name: 'Creating batch...' })).toBeDisabled();
  resolveCreate({ data: { batch: { id: 41 } } });
  await waitFor(() => expect(mocks.reload).toHaveBeenCalled());
});

it('retries batch creation without scheduling the same stops twice', async () => {
  mocks.createBatch.mockRejectedValueOnce({ response: { data: { message: 'Please retry.' } } });
  render(<Batches />);
  selectOrderAndSchedule();
  fireEvent.click(screen.getByRole('button', { name: 'Create draft batch' }));
  expect(await screen.findByRole('alert')).toHaveTextContent('Please retry.');

  fireEvent.click(screen.getByRole('button', { name: 'Create draft batch' }));
  await waitFor(() => expect(mocks.createBatch).toHaveBeenCalledTimes(2));
  expect(mocks.scheduleLegs).toHaveBeenCalledTimes(1);
});

it('exposes the created draft and clears stale selection when offering fails', async () => {
  mocks.offerBatch.mockRejectedValue({ response: { data: { message: 'Rider is no longer available.' } } });
  render(<Batches />);
  selectOrderAndSchedule();
  fireEvent.change(screen.getByLabelText('Rider'), { target: { value: '3' } });
  fireEvent.click(screen.getByRole('button', { name: 'Create & offer batch' }));

  expect(await screen.findByRole('alert')).toHaveTextContent('Draft batch created, but the rider offer failed. Assign a rider from the draft batch below.');
  expect(mocks.reload).toHaveBeenCalled();
  expect(screen.getByRole('button', { name: 'Create draft batch' })).toBeDisabled();
  expect(screen.getByRole('checkbox', { name: /order #55/i })).not.toBeChecked();
});

it('shows an empty state when no deliveries are ready', () => {
  mocks.props.unscheduled = [];
  render(<Batches />);
  expect(screen.getByText('No deliveries ready for batching.')).toBeInTheDocument();
});

it('shows server validation errors', async () => {
  mocks.createBatch.mockRejectedValue({ response: { data: { errors: { legs: ['Selected deliveries are unavailable.'] } } } });
  render(<Batches />);
  selectOrderAndSchedule();
  fireEvent.click(screen.getByRole('button', { name: 'Create draft batch' }));
  expect(await screen.findByRole('alert')).toHaveTextContent('Selected deliveries are unavailable.');
});

it('reloads and clears a partially scheduled selection when its slot changes', async () => {
  mocks.createBatch.mockRejectedValue({ response: { data: { message: 'Please retry.' } } });
  render(<Batches />);
  selectOrderAndSchedule();
  fireEvent.click(screen.getByRole('button', { name: 'Create draft batch' }));
  await screen.findByRole('alert');
  fireEvent.change(screen.getByLabelText('Delivery date'), { target: { value: '2026-07-16' } });

  expect(mocks.reload).toHaveBeenCalled();
  expect(screen.getByRole('checkbox', { name: /order #55/i })).not.toBeChecked();
});

it('formats existing batch dates for dispatchers', () => {
  mocks.props.batches = [{ id: 1, delivery_date: '2026-07-15T00:00:00.000000Z', delivery_window: 'morning', status: 'draft', capacity: 10, assigned_stop_count: 1, legs: [] }];
  render(<Batches />);
  expect(screen.getByText('Batch #1 · Jul 15, 2026 · Morning')).toBeInTheDocument();
});
