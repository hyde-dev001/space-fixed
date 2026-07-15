import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import Batches from '../Batches';

const mocks = vi.hoisted(() => ({
  props: {} as Record<string, unknown>,
  scheduleLegs: vi.fn(),
  createBatch: vi.fn(),
  offerBatch: vi.fn(),
  updateBatch: vi.fn(),
  removeBatchStop: vi.fn(),
  markUrgent: vi.fn(),
  reload: vi.fn(),
  toast: vi.fn(),
  confirm: vi.fn(),
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
  suggestions: vi.fn(), updateBatch: mocks.updateBatch, removeBatchStop: mocks.removeBatchStop, markUrgent: mocks.markUrgent, cancelBatch: vi.fn(),
} }));
vi.mock('@/utils/workflowFeedback', () => ({ workflowFeedback: {
  toast: mocks.toast,
  confirm: mocks.confirm,
  alert: vi.fn(),
} }));

const unscheduledLeg = {
  id: 7,
  status: 'pending',
  shipment: { id: 70, source_type: 'order', source_id: 55 },
  destination_snapshot: { name: 'Ana Reyes', phone: '09171234567', address: 'Dasmarinas, Cavite' },
};
const scheduledLeg = {
  id: 8,
  status: 'pending',
  scheduled_delivery_date: '2026-07-15',
  delivery_window: 'morning',
  shipment: { id: 80, source_type: 'order', source_id: 81 },
  destination_snapshot: { name: 'Ben Cruz', phone: '09987654321', address: 'Imus, Cavite' },
};

beforeEach(() => {
  vi.clearAllMocks();
  mocks.props = {
    batches: [],
    pool: [scheduledLeg],
    riders: [{ id: 3, name: 'Rider One', active: true, availability_status: 'available', rider_type: 'employee', daily_capacity: 10 }],
    unscheduled: [unscheduledLeg],
    dailyRiderCapacity: 10,
  };
  mocks.scheduleLegs.mockResolvedValue({});
  mocks.createBatch.mockResolvedValue({ data: { batch: { id: 41 } } });
  mocks.updateBatch.mockResolvedValue({});
  mocks.removeBatchStop.mockResolvedValue({});
  mocks.markUrgent.mockResolvedValue({});
  mocks.toast.mockResolvedValue({});
  mocks.confirm.mockResolvedValue({ isConfirmed: true });
});

function openBuilder() {
  fireEvent.click(screen.getByRole('button', { name: 'New Batch' }));
  fireEvent.change(screen.getByLabelText('Delivery date'), { target: { value: '2026-07-15' } });
}

function selectOrder55() {
  fireEvent.click(screen.getByRole('checkbox', { name: /order #55/i }));
}

it('opens a responsive two-column new-batch workspace', () => {
  render(<Batches />);
  expect(screen.getByText('Choose New Batch or open an existing batch to begin.')).toBeInTheDocument();

  openBuilder();

  expect(screen.getByTestId('batch-workspace')).toHaveClass('lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]');
  expect(screen.getByRole('heading', { name: 'Available deliveries' })).toBeInTheDocument();
  expect(screen.getByRole('heading', { name: 'New batch' })).toBeInTheDocument();
});

it.each([
  ['order', '55', 'Order #55'],
  ['customer', 'Ana', 'Order #55'],
  ['phone', '0917123', 'Order #55'],
  ['address', 'Imus', 'Order #81'],
])('searches by %s', (_field, query, expected) => {
  render(<Batches />);
  openBuilder();
  fireEvent.change(screen.getByLabelText('Search deliveries'), { target: { value: query } });

  expect(screen.getByText(expected)).toBeInTheDocument();
  expect(screen.getAllByRole('checkbox', { name: /order #/i })).toHaveLength(1);
});

it('filters by schedule status and clears all filters', () => {
  render(<Batches />);
  openBuilder();
  fireEvent.change(screen.getByLabelText('Schedule status'), { target: { value: 'scheduled' } });
  expect(screen.getByText('Order #81')).toBeInTheDocument();
  expect(screen.queryByText('Order #55')).not.toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
  expect(screen.getByText('Order #55')).toBeInTheDocument();
  expect(screen.getByText('Order #81')).toBeInTheDocument();
});

it('selects only matching eligible deliveries and reports the count', () => {
  mocks.props.pool = [scheduledLeg, { ...scheduledLeg, id: 9, scheduled_delivery_date: '2026-07-16', shipment: { id: 90, source_type: 'order', source_id: 90 } }];
  render(<Batches />);
  openBuilder();
  fireEvent.change(screen.getByLabelText('Search deliveries'), { target: { value: 'Ana' } });
  fireEvent.click(screen.getByRole('checkbox', { name: 'Select all matching deliveries' }));

  expect(screen.getByText('1 selected')).toBeInTheDocument();
  expect(screen.getByRole('checkbox', { name: /order #55/i })).toBeChecked();
});

it('distinguishes no deliveries from no filter matches', () => {
  const { rerender } = render(<Batches />);
  openBuilder();
  fireEvent.change(screen.getByLabelText('Search deliveries'), { target: { value: 'missing customer' } });
  expect(screen.getByText('No deliveries match your filters.')).toBeInTheDocument();

  mocks.props = { ...mocks.props, pool: [], unscheduled: [] };
  rerender(<Batches />);
  expect(screen.getByText('No deliveries ready for batching.')).toBeInTheDocument();
});

it('requires and submits a capacity override reason', async () => {
  mocks.props.dailyRiderCapacity = 1;
  render(<Batches />);
  openBuilder();
  fireEvent.click(screen.getByRole('checkbox', { name: 'Select all matching deliveries' }));

  expect(screen.getByText('This batch exceeds the daily rider capacity of 1 stop.')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Save Draft' })).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Capacity override reason'), { target: { value: 'Two nearby stops' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save Draft' }));

  await waitFor(() => expect(mocks.createBatch).toHaveBeenCalledWith({
    delivery_date: '2026-07-15', delivery_window: 'morning', leg_ids: [7, 8], dispatcher_override_reason: 'Two nearby stops',
  }));
});

it('schedules only the unscheduled subset before saving the draft', async () => {
  render(<Batches />);
  openBuilder();
  fireEvent.click(screen.getByRole('checkbox', { name: 'Select all matching deliveries' }));
  fireEvent.click(screen.getByRole('button', { name: 'Save Draft' }));

  await waitFor(() => expect(mocks.createBatch).toHaveBeenCalled());
  expect(mocks.scheduleLegs).toHaveBeenCalledWith([7], '2026-07-15', 'morning');
  expect(mocks.createBatch).toHaveBeenCalledWith({
    delivery_date: '2026-07-15', delivery_window: 'morning', leg_ids: [7, 8], dispatcher_override_reason: undefined,
  });
  expect(mocks.scheduleLegs.mock.invocationCallOrder[0]).toBeLessThan(mocks.createBatch.mock.invocationCallOrder[0]);
  expect(mocks.offerBatch).not.toHaveBeenCalled();
  expect(mocks.toast).toHaveBeenCalledWith('success', 'Draft saved');
  expect(mocks.reload).toHaveBeenCalledWith({ only: ['batches', 'pool', 'unscheduled'] });
});

it('retries draft creation without scheduling the same stops twice', async () => {
  mocks.createBatch.mockRejectedValueOnce({ response: { data: { message: 'Please retry.' } } });
  render(<Batches />);
  openBuilder();
  selectOrder55();
  fireEvent.click(screen.getByRole('button', { name: 'Save Draft' }));
  expect(await screen.findByRole('alert')).toHaveTextContent('Please retry.');

  fireEvent.click(screen.getByRole('button', { name: 'Save Draft' }));
  await waitFor(() => expect(mocks.createBatch).toHaveBeenCalledTimes(2));
  expect(mocks.scheduleLegs).toHaveBeenCalledTimes(1);
});

it('keeps the saved batch selected after refreshed props hydrate it', async () => {
  const { rerender } = render(<Batches />);
  openBuilder();
  selectOrder55();
  fireEvent.click(screen.getByRole('button', { name: 'Save Draft' }));
  await waitFor(() => expect(mocks.createBatch).toHaveBeenCalled());

  mocks.props = { ...mocks.props, batches: [{
    id: 41, delivery_date: '2026-07-15', delivery_window: 'morning', status: 'draft', capacity: 10,
    assigned_stop_count: 1, rider_profile: null, legs: [{ ...unscheduledLeg, stop_sequence: 1, scheduled_delivery_date: '2026-07-15', delivery_window: 'morning' }],
  }] };
  rerender(<Batches />);

  expect(screen.getAllByRole('heading', { name: 'Batch #41' })).toHaveLength(2);
  expect(screen.getByRole('button', { name: 'Review & Offer' })).toBeEnabled();
});

it('formats existing batch dates and shows useful stop details', () => {
  mocks.props.batches = [{
    id: 1, delivery_date: '2026-07-15T00:00:00.000000Z', delivery_window: 'morning', status: 'draft', capacity: 10,
    assigned_stop_count: 1, rider_profile: null, legs: [{ ...unscheduledLeg, id: 8, stop_sequence: 1, urgent_at: '2026-07-15T08:00:00Z', scheduled_delivery_date: '2026-07-15', delivery_window: 'morning' }],
  }];
  render(<Batches />);
  expect(screen.getByText('Batch #1')).toBeInTheDocument();
  expect(screen.getByText('Jul 15, 2026 · Morning')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Expand batch 1' }));
  expect(screen.getAllByText('Ana Reyes')).toHaveLength(2);
  expect(screen.getByText('Dasmarinas, Cavite')).toBeInTheDocument();
  expect(screen.getByText('Urgent')).toBeInTheDocument();
});

function openDraft(legs = [
  { ...unscheduledLeg, id: 7, stop_sequence: 1, scheduled_delivery_date: '2026-07-15', delivery_window: 'morning', urgent_at: null },
  { ...scheduledLeg, id: 8, stop_sequence: 2 },
]) {
  mocks.props.batches = [{
    id: 1, delivery_date: '2026-07-15', delivery_window: 'morning', status: 'draft', capacity: 10,
    assigned_stop_count: legs.length, rider_profile: null, legs,
  }];
  render(<Batches />);
  fireEvent.click(screen.getByRole('button', { name: 'Edit batch 1' }));
}

it('persists one ordered id list when a saved draft stop moves', async () => {
  openDraft();
  fireEvent.click(screen.getByRole('button', { name: 'Move stop 2 up' }));

  await waitFor(() => expect(mocks.updateBatch).toHaveBeenCalledWith(1, [8, 7]));
  expect(mocks.toast).toHaveBeenCalledWith('success', 'Stop order updated');
});

it('confirms removal with the stop and customer', async () => {
  openDraft();
  fireEvent.click(screen.getByRole('button', { name: 'Remove stop 1' }));
  await waitFor(() => expect(mocks.removeBatchStop).toHaveBeenCalledWith(1, 7));
  expect(mocks.confirm).toHaveBeenCalledWith(expect.objectContaining({ title: 'Remove stop 1?', text: expect.stringContaining('Ana Reyes') }));
});

it('warns that removing the final stop deletes the empty batch', async () => {
  openDraft([{ ...unscheduledLeg, id: 7, stop_sequence: 1, scheduled_delivery_date: '2026-07-15', delivery_window: 'morning' }]);
  fireEvent.click(screen.getByRole('button', { name: 'Remove stop 1' }));
  await waitFor(() => expect(mocks.confirm).toHaveBeenCalledWith(expect.objectContaining({ title: 'Delete this empty batch?', text: expect.stringContaining('final stop') })));
});

it('sets and clears urgency immediately while hiding it for terminal stops', async () => {
  openDraft([
    { ...unscheduledLeg, id: 7, status: 'pending', stop_sequence: 1, urgent_at: null },
    { ...scheduledLeg, id: 8, status: 'pending', stop_sequence: 2, urgent_at: '2026-07-15T08:00:00Z' },
    { ...scheduledLeg, id: 9, status: 'delivered', stop_sequence: 3, urgent_at: null },
  ]);
  fireEvent.click(screen.getByRole('button', { name: 'Mark urgent stop 1' }));
  await waitFor(() => expect(mocks.markUrgent).toHaveBeenCalledWith(7, true));
  fireEvent.click(screen.getByRole('button', { name: 'Clear urgent stop 2' }));
  await waitFor(() => expect(mocks.markUrgent).toHaveBeenCalledWith(8, false));
  expect(screen.queryByRole('button', { name: /urgent stop 3/i })).not.toBeInTheDocument();
});

it('disables the stop action while its request is pending', async () => {
  let resolveUrgent!: (value: unknown) => void;
  mocks.markUrgent.mockReturnValue(new Promise((resolve) => { resolveUrgent = resolve; }));
  openDraft();
  fireEvent.click(screen.getByRole('button', { name: 'Mark urgent stop 1' }));

  expect(screen.getByRole('button', { name: 'Mark urgent stop 1' })).toBeDisabled();
  expect(screen.getByRole('button', { name: 'Mark urgent stop 2' })).toBeEnabled();
  resolveUrgent({});
  await waitFor(() => expect(mocks.reload).toHaveBeenCalled());
});

it('offers a focused refresh prompt when saved batch data is stale', async () => {
  mocks.updateBatch.mockRejectedValue({ response: { status: 422, data: { message: 'Only draft batches can be changed.' } } });
  openDraft();
  fireEvent.click(screen.getByRole('button', { name: 'Move stop 2 up' }));

  await waitFor(() => expect(mocks.confirm).toHaveBeenCalledWith(expect.objectContaining({
    title: 'Batch changed', confirmButtonText: 'Refresh batch data',
  })));
  expect(mocks.reload).toHaveBeenCalledWith({ only: ['batches', 'pool', 'unscheduled', 'riders'] });
});
