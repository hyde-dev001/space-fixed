import React from 'react';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
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
  cancelBatch: vi.fn(),
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
  suggestions: vi.fn(), updateBatch: mocks.updateBatch, removeBatchStop: mocks.removeBatchStop, markUrgent: mocks.markUrgent, cancelBatch: mocks.cancelBatch,
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
  mocks.cancelBatch.mockResolvedValue({});
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
  expect(screen.getAllByRole('button', { name: 'Review & Offer' })[0]).toBeEnabled();
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

it('shows rider rejection details on a rejected draft card and workspace only', () => {
  const rejectedDraft = {
    id: 1, delivery_date: '2026-07-15', delivery_window: 'morning', status: 'draft', capacity: 10,
    assigned_stop_count: 1, rejection_reason: 'Vehicle unavailable', rejected_at: '2026-07-15T08:30:00Z', rider_profile: null,
    legs: [{ ...scheduledLeg, stop_sequence: 1 }],
  };
  mocks.props.batches = [rejectedDraft];
  const view = render(<Batches />);

  expect(screen.getByRole('alert')).toHaveTextContent('Rejected by rider');
  expect(screen.getByRole('alert')).toHaveTextContent('Vehicle unavailable');
  expect(screen.getByRole('alert')).toHaveTextContent('Jul 15, 2026, 8:30 AM');

  fireEvent.click(screen.getByRole('button', { name: 'Edit batch 1' }));
  expect(screen.getAllByRole('alert')).toHaveLength(2);
  expect(screen.getAllByRole('alert')[1]).toHaveTextContent('Rejected by rider');
  expect(screen.getAllByRole('alert')[1]).toHaveTextContent('Vehicle unavailable');

  view.unmount();
  mocks.props.batches = [{ ...rejectedDraft, rejection_reason: null, rejected_at: null }];
  render(<Batches />);
  expect(screen.queryByText('Rejected by rider')).not.toBeInTheDocument();
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
  expect(mocks.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['batches', 'pool', 'unscheduled', 'riders'] }));
});

it('reviews an ordered draft and requires a rider before offering', async () => {
  openDraft([
    { ...unscheduledLeg, id: 7, stop_sequence: 1, urgent_at: '2026-07-15T08:00:00Z' },
    { ...scheduledLeg, id: 8, stop_sequence: 2 },
  ]);
  fireEvent.click(screen.getAllByRole('button', { name: 'Review & Offer' })[0]);

  const dialog = screen.getByRole('dialog', { name: 'Review & Offer Batch #1' });
  expect(dialog).toBeInTheDocument();
  expect(screen.getByText('2 ordered stops')).toBeInTheDocument();
  expect(dialog).toHaveTextContent('1 urgent');
  expect(screen.getByRole('button', { name: 'Offer Batch to Rider' })).toBeDisabled();

  await waitFor(() => expect(screen.getByLabelText('Select rider')).toHaveFocus());
  fireEvent.change(screen.getByLabelText('Select rider'), { target: { value: '3' } });
  fireEvent.click(screen.getByRole('button', { name: 'Offer Batch to Rider' }));

  await waitFor(() => expect(mocks.offerBatch).toHaveBeenCalledWith(1, 3, undefined));
  expect(mocks.confirm).toHaveBeenCalledWith(expect.objectContaining({ title: 'Offer Batch #1 to Rider One?' }));
  expect(mocks.toast).toHaveBeenCalledWith('success', 'Batch offered');
});

it('warns when the selected rider capacity is below the stop count', () => {
  mocks.props.riders = [{ id: 3, name: 'Rider One', active: true, availability_status: 'available', rider_type: 'employee', daily_capacity: 1 }];
  openDraft();
  fireEvent.click(screen.getAllByRole('button', { name: 'Review & Offer' })[0]);
  fireEvent.change(screen.getByLabelText('Select rider'), { target: { value: '3' } });

  expect(screen.getByText('This route exceeds Rider One’s capacity of 1 stop.')).toBeInTheDocument();
});

it('requires an override reason when same-date workload exceeds rider capacity', async () => {
  const candidate = {
    id: 1, delivery_date: '2026-07-15', delivery_window: 'morning', status: 'draft', capacity: 6,
    assigned_stop_count: 2, rider_profile: null, legs: [
      { ...unscheduledLeg, id: 7, stop_sequence: 1, scheduled_delivery_date: '2026-07-15', delivery_window: 'morning' },
      { ...scheduledLeg, id: 8, stop_sequence: 2 },
    ],
  };
  const assignedRider = { id: 3, name: 'Rider One', active: true, availability_status: 'available', rider_type: 'employee', daily_capacity: null };
  mocks.props = {
    ...mocks.props,
    dailyRiderCapacity: 6,
    riders: [assignedRider],
    batches: [
      candidate,
      { ...candidate, id: 2, delivery_window: 'afternoon', status: 'in_progress', assigned_stop_count: 5, rider_profile: assignedRider, legs: [] },
      { ...candidate, id: 3, delivery_date: '2026-07-16', status: 'accepted', assigned_stop_count: 4, rider_profile: assignedRider, legs: [] },
    ],
  };
  render(<Batches />);
  fireEvent.click(screen.getByRole('button', { name: 'Edit batch 1' }));
  fireEvent.click(screen.getAllByRole('button', { name: 'Review & Offer' })[0]);

  expect(screen.getByRole('option', { name: 'Rider One · 5/6 used today' })).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText('Select rider'), { target: { value: '3' } });
  expect(screen.getByText('5 used + 2 stops = 7/6')).toBeInTheDocument();

  const reason = screen.getByLabelText('Capacity override reason');
  const offer = screen.getByRole('button', { name: 'Offer Batch to Rider' });
  expect(offer).toBeDisabled();
  fireEvent.change(reason, { target: { value: '   ' } });
  expect(offer).toBeDisabled();
  fireEvent.change(reason, { target: { value: 'Operational priority' } });
  fireEvent.click(offer);

  await waitFor(() => expect(mocks.offerBatch).toHaveBeenCalledWith(1, 3, 'Operational priority'));
});

it('keeps the review and draft intact when the rider offer fails', async () => {
  mocks.offerBatch.mockRejectedValue({ response: { data: { message: 'Rider is no longer available.' } } });
  openDraft();
  fireEvent.click(screen.getAllByRole('button', { name: 'Review & Offer' })[0]);
  fireEvent.change(screen.getByLabelText('Select rider'), { target: { value: '3' } });
  fireEvent.click(screen.getByRole('button', { name: 'Offer Batch to Rider' }));

  expect(await screen.findByRole('alert')).toHaveTextContent('Rider is no longer available.');
  expect(screen.getByRole('dialog', { name: 'Review & Offer Batch #1' })).toBeInTheDocument();
  expect(screen.getByRole('heading', { name: 'Batch #1', level: 2 })).toBeInTheDocument();
});

it('allows an override retry when server capacity is newer than the page', async () => {
  mocks.offerBatch
    .mockRejectedValueOnce({ response: { data: { errors: { capacity_override_reason: ['Capacity changed. Add an override reason.'] } } } })
    .mockRejectedValueOnce({ response: { data: { message: 'Rider Two became unavailable.' } } });
  mocks.props.riders = [
    { id: 3, name: 'Rider One', active: true, availability_status: 'available', rider_type: 'employee', daily_capacity: 10 },
    { id: 4, name: 'Rider Two', active: true, availability_status: 'available', rider_type: 'employee', daily_capacity: 10 },
  ];
  openDraft();
  fireEvent.click(screen.getAllByRole('button', { name: 'Review & Offer' })[0]);
  fireEvent.change(screen.getByLabelText('Select rider'), { target: { value: '3' } });
  expect(screen.queryByLabelText('Capacity override reason')).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Offer Batch to Rider' }));

  expect(await screen.findByRole('alert')).toHaveTextContent('Capacity changed. Add an override reason.');
  expect(screen.getByLabelText('Capacity override reason')).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText('Select rider'), { target: { value: '4' } });
  expect(screen.queryByLabelText('Capacity override reason')).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Offer Batch to Rider' })).toBeEnabled();
  fireEvent.click(screen.getByRole('button', { name: 'Offer Batch to Rider' }));
  await waitFor(() => expect(mocks.offerBatch).toHaveBeenNthCalledWith(2, 1, 4, undefined));

  fireEvent.change(screen.getByLabelText('Select rider'), { target: { value: '3' } });
  expect(screen.getByLabelText('Capacity override reason')).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText('Capacity override reason'), { target: { value: '  Dispatch recovery  ' } });
  fireEvent.click(screen.getByRole('button', { name: 'Offer Batch to Rider' }));

  await waitFor(() => expect(mocks.offerBatch).toHaveBeenNthCalledWith(3, 1, 3, 'Dispatch recovery'));
});

it('requires a cancellation reason and calls the existing endpoint', async () => {
  mocks.confirm.mockResolvedValueOnce({ isConfirmed: true, value: 'Customer requested another date' });
  openDraft();
  fireEvent.click(screen.getByLabelText('More actions for batch 1'));
  fireEvent.click(screen.getByRole('button', { name: 'Cancel batch' }));

  await waitFor(() => expect(mocks.cancelBatch).toHaveBeenCalledWith(1, 'Customer requested another date'));
  expect(mocks.confirm).toHaveBeenCalledWith(expect.objectContaining({ input: 'textarea', title: 'Cancel Batch #1?', inputValidator: expect.any(Function) }));
  expect(mocks.toast).toHaveBeenCalledWith('success', 'Batch cancelled');
});

const batchForStatus = (id: number, status: string) => ({
  id, delivery_date: '2026-07-15', delivery_window: 'morning', status, capacity: 10, assigned_stop_count: 1,
  rider_profile: status === 'draft' ? null : { id: 3, name: 'Rider One', active: true, availability_status: 'available', rider_type: 'employee', daily_capacity: 10 },
  legs: [{ ...unscheduledLeg, id: id * 10, status: status === 'completed' ? 'delivered' : 'pending', stop_sequence: 1, scheduled_delivery_date: '2026-07-15', delivery_window: 'morning' }],
});

it('filters active batches by status and keeps history collapsed separately', () => {
  mocks.props.batches = [batchForStatus(1, 'draft'), batchForStatus(2, 'offered'), batchForStatus(3, 'completed')];
  render(<Batches />);
  const active = screen.getByRole('region', { name: 'Active batches' });
  expect(within(active).getByText('Batch #1')).toBeInTheDocument();
  expect(within(active).getByText('Batch #2')).toBeInTheDocument();
  expect(within(active).queryByText('Batch #3')).not.toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'Offered (1)' }));
  expect(within(active).queryByText('Batch #1')).not.toBeInTheDocument();
  expect(within(active).getByText('Batch #2')).toBeInTheDocument();
  expect(screen.getByText('History (1)').closest('details')).not.toHaveAttribute('open');
});

it('uses status-aware primary and secondary actions', () => {
  mocks.props.batches = [
    batchForStatus(1, 'draft'), batchForStatus(2, 'offered'), batchForStatus(3, 'accepted'),
    batchForStatus(4, 'in_progress'), batchForStatus(5, 'completed'), batchForStatus(6, 'cancelled'),
  ];
  render(<Batches />);
  expect(screen.getByRole('button', { name: 'Edit batch 1' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'View offer 2' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'View route 3' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'View progress 4' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'View summary 5' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'View summary 6' })).toBeInTheDocument();
  expect(screen.queryByLabelText('More actions for batch 4')).not.toBeInTheDocument();
});

it('keeps active offered routes read-only while urgency remains available', () => {
  mocks.props.batches = [batchForStatus(2, 'offered')];
  render(<Batches />);
  fireEvent.click(screen.getByRole('button', { name: 'View offer 2' }));

  expect(screen.queryByRole('button', { name: 'Move stop 1 up' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Remove stop 1' })).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Mark urgent stop 1' })).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Review & Offer' })).not.toBeInTheDocument();
});

it('shows the no-riders state without allowing an offer', () => {
  mocks.props.riders = [];
  openDraft();
  fireEvent.click(screen.getAllByRole('button', { name: 'Review & Offer' })[0]);

  expect(screen.getByText('No available riders. Keep this batch as a draft and try again later.')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Offer Batch to Rider' })).toBeDisabled();
});

it('shows delivery-pool skeletons while refreshed props are pending', async () => {
  mocks.reload.mockImplementation(() => undefined);
  openDraft();
  fireEvent.click(screen.getByRole('button', { name: 'Mark urgent stop 1' }));

  expect(await screen.findAllByTestId('delivery-skeleton')).toHaveLength(3);
});
