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
  cancelBatch: vi.fn(),
  restoreBatch: vi.fn(),
  get: vi.fn(),
  reload: vi.fn(),
  toast: vi.fn(),
  error: vi.fn(),
  confirm: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  router: { get: mocks.get, reload: mocks.reload },
  usePage: () => ({ props: mocks.props }),
}));
vi.mock('@/layout/AppLayout_ERP', () => ({ default: ({ children }: React.PropsWithChildren) => <>{children}</> }));
vi.mock('@/services/logisticsApi', () => ({ logisticsApi: {
  scheduleLegs: mocks.scheduleLegs,
  createBatch: mocks.createBatch,
  offerBatch: mocks.offerBatch,
  suggestions: vi.fn(), updateBatch: mocks.updateBatch, removeBatchStop: mocks.removeBatchStop, cancelBatch: mocks.cancelBatch, restoreBatch: mocks.restoreBatch,
} }));
vi.mock('@/utils/workflowFeedback', () => ({ workflowFeedback: {
  toast: mocks.toast,
  error: mocks.error,
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
  shipment: {
    id: 80,
    source_type: 'order',
    source_id: 81,
    order_summary: {
      available: true,
      order_number: '#81',
      total_quantity: 5,
      variant_count: 2,
      model_count: 2,
      items: [
        { id: 1, brand: 'Adidas', model: 'Ultraboost', color: 'Black', size: '9', quantity: 3, image: null },
        { id: 2, brand: 'Puma', model: 'Suede', color: 'Blue', size: '10', quantity: 2, image: null },
      ],
    },
  },
  destination_snapshot: { name: 'Ben Cruz', phone: '09987654321', address: 'Imus, Cavite', delivery_instructions: 'Call upon arrival' },
};
const repairLeg = {
  id: 9,
  status: 'pending',
  shipment: {
    id: 90,
    source_type: 'repair_request',
    source_id: 91,
    source_summary: {
      request_number: 'REP-2026-0042',
      customer_name: 'Cara Santos',
      shoe_summary: 'Adidas Ultraboost',
    },
  },
  destination_snapshot: { name: 'Cara Santos', phone: '09170000000', address: 'Bacoor, Cavite' },
};

beforeEach(() => {
  vi.clearAllMocks();
  mocks.props = {
    batches: [],
    pool: [scheduledLeg],
    riders: [{ id: 3, name: 'Rider One', active: true, availability_status: 'available', rider_type: 'employee', daily_capacity: 10 }],
    unscheduled: [unscheduledLeg],
    dailyRiderCapacity: 10,
    maxDeliveryAttempts: 2,
    today: '2026-07-01',
    filters: { module: 'all', date: null, window: 'all' },
    availableModules: ['retail'],
    showModuleFilter: false,
  };
  mocks.scheduleLegs.mockResolvedValue({});
  mocks.createBatch.mockResolvedValue({ data: { batch: { id: 41 } } });
  mocks.updateBatch.mockResolvedValue({});
  mocks.removeBatchStop.mockResolvedValue({});
  mocks.cancelBatch.mockResolvedValue({});
  mocks.restoreBatch.mockResolvedValue({});
  mocks.toast.mockResolvedValue({});
  mocks.error.mockResolvedValue({});
  mocks.confirm.mockResolvedValue({ isConfirmed: true });
});

function openBuilder() {
  fireEvent.click(screen.getByRole('button', { name: 'New Batch' }));
  fireEvent.change(screen.getByLabelText('Delivery date'), { target: { value: '2026-07-15' } });
}

function selectOrder55() {
  fireEvent.click(screen.getByRole('checkbox', { name: /order #55/i }));
}

function getCompactBatchButton(name: string, container: HTMLElement = document.body) {
  return within(within(container).getByTestId('compact-batch-list')).getByRole('button', { name });
}

it('opens a responsive new-batch workspace without compact overflow', () => {
  render(<Batches />);
  expect(screen.getByText('Choose New Batch or open an existing batch to begin.')).toBeInTheDocument();
  expect(screen.getByTestId('batch-page-header-controls')).toHaveClass('w-full', 'xl:w-auto');

  openBuilder();

  expect(screen.getByTestId('batch-page-main')).toHaveClass('overflow-x-clip');
  expect(screen.getByTestId('batch-workspace')).toHaveClass('xl:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]');
  expect(screen.getByTestId('batch-workspace')).not.toHaveClass('lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]');
  expect(screen.getByTestId('batch-filter-grid')).toHaveClass('grid-cols-1');
  expect(screen.getByRole('heading', { name: 'Available deliveries' })).toBeInTheDocument();
  expect(screen.getByRole('heading', { name: 'New batch' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Save Draft' })).toHaveClass('w-full', 'sm:w-auto');
});

it('disables past dates in the delivery date calendar', () => {
  mocks.props = { ...mocks.props, today: '2026-08-13' };
  render(<Batches />);

  fireEvent.click(screen.getByRole('button', { name: 'Open delivery date picker' }));

  expect(screen.getByRole('dialog', { name: 'Delivery date calendar' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Select August 12, 2026' })).toBeDisabled();
  expect(screen.getByRole('button', { name: 'Select August 13, 2026' })).toBeEnabled();
  expect(screen.getByRole('button', { name: 'Select August 15, 2026' })).toBeEnabled();
});

it('selects and clears a delivery date from the calendar', () => {
  mocks.props = { ...mocks.props, today: '2026-08-13' };
  render(<Batches />);

  fireEvent.click(screen.getByRole('button', { name: 'Open delivery date picker' }));
  fireEvent.click(screen.getByRole('button', { name: 'Select August 15, 2026' }));

  expect(screen.getByRole('button', { name: 'Open delivery date picker' })).toHaveTextContent('08/15/2026');
  expect(mocks.get).toHaveBeenCalledWith('/erp/logistics/batches', expect.objectContaining({ date: '2026-08-15' }), expect.anything());

  fireEvent.click(screen.getByRole('button', { name: 'Open delivery date picker' }));
  fireEvent.click(screen.getByRole('button', { name: 'Clear date' }));

  expect(screen.getByRole('button', { name: 'Open delivery date picker' })).toHaveTextContent('mm/dd/yyyy');
});

it('shows prior failed-attempt context on a reassigned pool stop', () => {
  mocks.props.pool = [{ ...scheduledLeg, failed_attempt_count: 1, attempts: [{ id: 4, status: 'failed', attempt_number: 1, reason_code: 'recipient_unavailable' }] }];
  render(<Batches />);
  openBuilder();
  fireEvent.click(screen.getByRole('checkbox', { name: /order #81/i }));
  expect(screen.getByText('Failed attempt - 1/2')).toBeInTheDocument();
  expect(screen.getByText('Recipient Unavailable')).toBeInTheDocument();
});

it.each([
  ['order', '55', 'Order #55'],
  ['customer', 'Ana', 'Order #55'],
  ['phone', '0917123', 'Order #55'],
  ['address', 'Imus', 'Order #81'],
  ['brand', 'Adidas', 'Order #81'],
  ['model', 'Ultraboost', 'Order #81'],
])('searches by %s', (_field, query, expected) => {
  render(<Batches />);
  openBuilder();
  fireEvent.change(screen.getByLabelText('Search deliveries'), { target: { value: query } });

  expect(screen.getByText(expected)).toBeInTheDocument();
  expect(screen.getAllByRole('checkbox', { name: /order #/i })).toHaveLength(1);
});

it('identifies the products and quantity in available deliveries', () => {
  render(<Batches />);
  openBuilder();

  const delivery = screen.getByRole('checkbox', { name: /order #81/i }).closest('label');
  expect(delivery).not.toBeNull();
  expect(within(delivery!).getByText('Adidas Ultraboost')).toBeInTheDocument();
  expect(within(delivery!).getByText(/5 pairs.*2 variants.*\+1 more/)).toBeInTheDocument();
  expect(within(delivery!).getByText('Delivery instructions')).toBeInTheDocument();
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

it('requires two compatible deliveries and disables the other module', () => {
  mocks.props = {
    ...mocks.props,
    unscheduled: [unscheduledLeg, repairLeg],
    availableModules: ['retail', 'repair'],
    showModuleFilter: true,
  };
  render(<Batches />);
  expect(screen.getByLabelText('Filter batches by module')).toBeInTheDocument();

  openBuilder();
  expect(screen.getByText('Select at least 2 deliveries')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Save Draft' })).toBeDisabled();

  selectOrder55();
  expect(screen.getByRole('button', { name: 'Save Draft' })).toBeDisabled();
  expect(screen.getByRole('checkbox', { name: /repair REP-2026-0042/i })).toBeDisabled();
  expect(screen.getByText('Choose one module per batch')).toBeInTheDocument();

  fireEvent.click(screen.getByRole('checkbox', { name: /order #81/i }));
  expect(screen.getByRole('button', { name: 'Save Draft' })).toBeEnabled();
});

it('requests backend-filtered batch data for the selected module and slot', () => {
  mocks.props = {
    ...mocks.props,
    availableModules: ['retail', 'repair'],
    showModuleFilter: true,
  };
  render(<Batches />);

  fireEvent.change(screen.getByLabelText('Filter batches by module'), { target: { value: 'repair' } });
  expect(mocks.get).toHaveBeenLastCalledWith('/erp/logistics/batches', {
    module: 'repair',
    date: undefined,
    window: 'morning',
  }, expect.objectContaining({ only: ['batches', 'pool', 'unscheduled', 'filters'] }));
});

it('shows repair request, customer, and shoe details in the delivery pool', () => {
  mocks.props = { ...mocks.props, pool: [], unscheduled: [repairLeg] };
  render(<Batches />);

  expect(screen.getByText('Repair REP-2026-0042')).toBeInTheDocument();
  expect(screen.getByText('Cara Santos')).toBeInTheDocument();
  expect(screen.getByText('Adidas Ultraboost')).toBeInTheDocument();
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
  fireEvent.click(screen.getByRole('checkbox', { name: 'Select all matching deliveries' }));
  fireEvent.click(screen.getByRole('button', { name: 'Save Draft' }));
  expect(await screen.findByRole('alert')).toHaveTextContent('Please retry.');

  fireEvent.click(screen.getByRole('button', { name: 'Save Draft' }));
  await waitFor(() => expect(mocks.createBatch).toHaveBeenCalledTimes(2));
  expect(mocks.scheduleLegs).toHaveBeenCalledTimes(1);
});

it('shows a Swal error when draft creation is rejected', async () => {
  mocks.createBatch.mockRejectedValueOnce({
    response: { status: 422, data: { errors: { delivery_window: ['The selected delivery window conflicts with an existing batch.'] } } },
  });
  render(<Batches />);
  openBuilder();
  fireEvent.click(screen.getByRole('checkbox', { name: 'Select all matching deliveries' }));
  fireEvent.click(screen.getByRole('button', { name: 'Save Draft' }));

  await waitFor(() => expect(mocks.error).toHaveBeenCalledWith(
    'The selected delivery window conflicts with an existing batch.',
    'Draft not saved',
  ));
  expect(screen.getByRole('alert')).toHaveTextContent('The selected delivery window conflicts with an existing batch.');
});

it('keeps the saved batch selected after refreshed props hydrate it', async () => {
  const { rerender } = render(<Batches />);
  openBuilder();
  fireEvent.click(screen.getByRole('checkbox', { name: 'Select all matching deliveries' }));
  fireEvent.click(screen.getByRole('button', { name: 'Save Draft' }));
  await waitFor(() => expect(mocks.createBatch).toHaveBeenCalled());

  mocks.props = { ...mocks.props, batches: [{
    id: 41, delivery_date: '2026-07-15', delivery_window: 'morning', status: 'draft', capacity: 10,
    assigned_stop_count: 2, rider_profile: null, legs: [
      { ...unscheduledLeg, stop_sequence: 1, scheduled_delivery_date: '2026-07-15', delivery_window: 'morning' },
      { ...scheduledLeg, stop_sequence: 2 },
    ],
  }] };
  rerender(<Batches />);

  expect(screen.getByRole('table', { name: 'Active batches' })).toHaveTextContent('Batch #41');
  expect(screen.getAllByRole('button', { name: 'Review & Offer' })[0]).toBeEnabled();
});

it('formats existing batch dates and shows useful stop details', () => {
  mocks.props.batches = [{
    id: 1, delivery_date: '2026-07-15T00:00:00.000000Z', delivery_window: 'morning', status: 'draft', capacity: 10,
    assigned_stop_count: 1, rider_profile: null, legs: [{ ...unscheduledLeg, id: 8, stop_sequence: 1, urgent_at: '2026-07-15T08:00:00Z', scheduled_delivery_date: '2026-07-15', delivery_window: 'morning' }],
  }];
  render(<Batches />);
  expect(screen.getByRole('row', { name: /Batch #1/ })).toBeInTheDocument();
  expect(screen.getByText('Jul 15, 2026 · Morning')).toBeInTheDocument();
  fireEvent.click(getCompactBatchButton('View details for batch 1'));
  const details = screen.getByRole('dialog', { name: 'Batch 1 details' });
  expect(details).toHaveTextContent('Ana Reyes');
  expect(details).toHaveTextContent('Dasmarinas, Cavite');
  expect(details).toHaveTextContent('Urgent');
  fireEvent.click(screen.getByRole('button', { name: 'Close batch details' }));
  fireEvent.click(getCompactBatchButton('Edit batch 1'));
  expect(screen.getAllByText('Jul 15, 2026 · Morning')).toHaveLength(2);
  expect(screen.getByText('Jul 15, 2026 · Morning · Pending')).toBeInTheDocument();
});

it('shows rider rejection details on a rejected draft card and workspace only', () => {
  const rejectedDraft = {
    id: 1, delivery_date: '2026-07-15', delivery_window: 'morning', status: 'draft', capacity: 10,
    assigned_stop_count: 1, rejection_reason: 'Vehicle unavailable', rejected_at: '2026-07-15T08:30:00Z', rider_profile: null,
    legs: [{ ...scheduledLeg, stop_sequence: 1 }],
  };
  mocks.props.batches = [rejectedDraft];
  const view = render(<Batches />);

  const compactAlert = within(screen.getByTestId('compact-batch-list')).getByRole('alert');
  expect(compactAlert).toHaveTextContent('Rejected by rider');
  expect(compactAlert).toHaveTextContent('Vehicle unavailable');
  expect(compactAlert).toHaveTextContent(/Jul 15, 2026.*4:30 PM/);

  fireEvent.click(getCompactBatchButton('Edit batch 1'));
  const workspace = screen.getByTestId('batch-workspace');
  expect(within(workspace).getByRole('alert')).toHaveTextContent('Rejected by rider');
  expect(within(workspace).getByRole('alert')).toHaveTextContent('Vehicle unavailable');

  view.unmount();
  mocks.props.batches = [{ ...rejectedDraft, rejected_at: 'not-a-timestamp' }];
  const invalidView = render(<Batches />);
  expect(within(screen.getByTestId('compact-batch-list')).getByRole('alert')).toHaveTextContent('Vehicle unavailable');
  expect(screen.queryByText('Invalid Date')).not.toBeInTheDocument();
  fireEvent.click(getCompactBatchButton('Edit batch 1'));
  expect(within(screen.getByTestId('batch-workspace')).getByRole('alert')).toHaveTextContent('Rejected by rider');
  expect(screen.queryByText('Invalid Date')).not.toBeInTheDocument();

  invalidView.unmount();
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
  fireEvent.click(getCompactBatchButton('Edit batch 1'));
}

it('identifies the products and quantity in a live route stop', () => {
  openDraft([{ ...scheduledLeg, stop_sequence: 1 }]);

  const stop = screen.getByRole('article', { name: 'Stop 1: Ben Cruz' });
  expect(within(stop).getByTestId('batch-stop-layout')).toHaveClass('grid-cols-[auto_minmax(0,1fr)]', 'gap-x-2', 'gap-y-4', 'xl:flex', 'xl:gap-3');
  expect(within(stop).getByTestId('batch-stop-identity')).toHaveClass('gap-2');
  expect(within(stop).getByTestId('batch-stop-details')).toHaveClass('contents', 'xl:block');
  expect(within(stop).getByTestId('batch-stop-actions')).toHaveClass('col-span-2', 'justify-center', 'gap-2', 'xl:justify-end', 'xl:gap-1');
  expect(within(stop).getByRole('button', { name: 'Move stop 1 up' })).toHaveClass('inline-flex', 'items-center', 'justify-center');
  expect(within(stop).getByRole('button', { name: 'Move stop 1 down' })).toHaveClass('inline-flex', 'items-center', 'justify-center');
  expect(within(stop).getByRole('button', { name: 'Remove stop 1' })).toHaveClass('inline-flex', 'items-center', 'justify-center');
  expect(within(stop).getByText('Adidas Ultraboost')).toBeInTheDocument();
  expect(within(stop).getByText('Adidas Ultraboost')).toHaveClass('break-words', 'xl:truncate');
  expect(within(stop).getByText(/5 pairs.*2 variants.*\+1 more/)).toBeInTheDocument();
  expect(within(stop).getByText('Delivery instructions')).toBeInTheDocument();
});

it('shows the same arrival checks inside a batch stop', () => {
  openDraft([{
    ...scheduledLeg,
    stop_sequence: 1,
    arrivals: {
      pickup: {
        result: 'verified', distance_m: 18.2, radius_m: 100, accuracy_m: 12,
        recorded_at: '2026-07-15T02:30:00Z',
      },
      dropoff: {
        result: 'outside_geofence', distance_m: 154.6, radius_m: 100, accuracy_m: 15,
        exception_reason: 'pin_incorrect', recorded_at: '2026-07-15T03:45:00Z',
      },
    },
  }]);

  const stop = screen.getByRole('article', { name: 'Stop 1: Ben Cruz' });
  expect(within(stop).getByText('Pickup arrival')).toBeInTheDocument();
  expect(within(stop).getByText('Verified arrival')).toBeInTheDocument();
  expect(within(stop).getByText('Customer arrival')).toBeInTheDocument();
  expect(within(stop).getByText('Outside geofence')).toBeInTheDocument();
  expect(within(stop).getByText('Pin incorrect')).toBeInTheDocument();
});

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
  expect(dialog).toHaveClass('max-h-[100dvh]');
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
  fireEvent.click(getCompactBatchButton('Edit batch 1'));
  fireEvent.click(screen.getAllByRole('button', { name: 'Review & Offer' })[0]);

  expect(screen.getByRole('option', { name: 'Rider One · 5/6 stops used today' })).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText('Select rider'), { target: { value: '3' } });
  expect(screen.getByText('5 stops used + 2 stops = 7/6')).toBeInTheDocument();

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
  fireEvent.click(within(screen.getByTestId('compact-batch-list')).getByLabelText('More actions for batch 1'));
  fireEvent.click(screen.getByRole('menuitem', { name: 'Cancel batch' }));

  await waitFor(() => expect(mocks.cancelBatch).toHaveBeenCalledWith(1, 'Customer requested another date'));
  expect(mocks.confirm).toHaveBeenCalledWith(expect.objectContaining({ input: 'textarea', title: 'Cancel Batch #1?', inputValidator: expect.any(Function) }));
  expect(mocks.toast).toHaveBeenCalledWith('success', 'Batch cancelled');
});

const batchForStatus = (id: number, status: string) => ({
  id, delivery_date: '2026-07-15', delivery_window: 'morning', status, capacity: 10, assigned_stop_count: 1,
  rider_profile: status === 'draft' ? null : { id: 3, name: 'Rider One', active: true, availability_status: 'available', rider_type: 'employee', daily_capacity: 10 },
  legs: [{ ...unscheduledLeg, id: id * 10, status: status === 'completed' ? 'delivered' : 'pending', stop_sequence: 1, scheduled_delivery_date: '2026-07-15', delivery_window: 'morning' }],
});

it('collapses available deliveries while editing and reopens it for a new batch', () => {
  mocks.props.batches = [batchForStatus(1, 'draft')];
  render(<Batches />);

  fireEvent.click(getCompactBatchButton('Edit batch 1'));
  expect(screen.getByTestId('batch-workspace')).toHaveClass('xl:grid-cols-1');
  expect(screen.getByRole('heading', { name: 'Batch #1' })).toBeInTheDocument();
  expect(screen.getByLabelText('Search deliveries')).not.toBeVisible();
  expect(screen.getByRole('button', { name: 'Show available deliveries' })).toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'Show available deliveries' }));
  expect(screen.getByLabelText('Search deliveries')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Collapse available deliveries' })).toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'New Batch' }));
  expect(screen.getByRole('heading', { name: 'New batch' })).toBeInTheDocument();
  expect(screen.getByLabelText('Search deliveries')).toBeInTheDocument();
  expect(screen.getByTestId('batch-workspace')).toHaveClass('xl:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]');
});

const historicalStops = [
  { ...scheduledLeg, id: 91, stop_sequence: 1, status: 'delivered', urgent_at: '2026-07-15T08:00:00Z', shipment: { id: 91, source_type: 'order', source_id: 901 }, destination_snapshot: { name: 'Snapshot First', phone: '0901', address: 'First saved address' } },
  { ...scheduledLeg, id: 92, stop_sequence: 2, status: 'delivered', urgent_at: null, shipment: { id: 92, source_type: 'order', source_id: 902 }, destination_snapshot: { name: 'Snapshot Second', phone: '0902', address: 'Second saved address' } },
];

it('renders active batches as a table and opens stop details in a modal', async () => {
  mocks.props.batches = [batchForStatus(2, 'accepted')];
  render(<Batches />);

  const desktopBatchTable = screen.getByTestId('desktop-batch-table');
  const compactBatchList = screen.getByTestId('compact-batch-list');
  expect(compactBatchList).toHaveClass('xl:hidden');
  expect(compactBatchList).toHaveTextContent('Batch #2');
  expect(desktopBatchTable).toHaveClass('hidden', 'xl:block');
  expect(screen.getByRole('table', { name: 'Active batches' })).toBeInTheDocument();
  expect(screen.getByRole('columnheader', { name: 'Batch' })).toBeInTheDocument();
  expect(screen.getByRole('row', { name: /Batch #2/ })).toBeInTheDocument();
  expect(within(compactBatchList).getByRole('button', { name: 'View details for batch 2' })).toBeInTheDocument();
  const activeRow = screen.getByRole('row', { name: /Batch #2/ });
  expect(within(activeRow).queryByRole('button', { name: 'View route 2' })).not.toBeInTheDocument();
  expect(within(activeRow).getByTitle('View details')).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Expand batch 2' })).not.toBeInTheDocument();

  const detailsTrigger = within(compactBatchList).getByRole('button', { name: 'View details for batch 2' });
  fireEvent.click(detailsTrigger);

  const details = screen.getByRole('dialog', { name: 'Batch 2 details' });
  expect(details).toBeInTheDocument();
  expect(details).toHaveClass('max-h-[100dvh]');
  expect(details).toHaveTextContent('Order #55');
  expect(screen.getByRole('button', { name: 'Close batch details' })).toBeInTheDocument();
  await waitFor(() => expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Close batch details' })));

  fireEvent.keyDown(document, { key: 'Escape' });
  await waitFor(() => expect(document.activeElement).toBe(detailsTrigger));
});

it('opens batch history from the active filter row', () => {
  mocks.props.batches = [batchForStatus(6, 'completed')];
  render(<Batches />);

  const historyButton = screen.getByRole('button', { name: 'History (1)' });
  expect(historyButton).toBeInTheDocument();
  fireEvent.click(historyButton);

  const history = screen.getByRole('dialog', { name: 'Batch history' });
  expect(history).toBeInTheDocument();
  expect(history).toHaveClass('max-h-[100dvh]');
  expect(history).toHaveTextContent('Batch #6');
  expect(within(history).queryByTitle('View summary')).not.toBeInTheDocument();
  expect(within(within(history).getByTestId('compact-batch-list')).getByTitle('View details')).toBeInTheDocument();
});

it('filters active batches by status and keeps history collapsed separately', () => {
  mocks.props.batches = [batchForStatus(1, 'draft'), batchForStatus(2, 'offered'), batchForStatus(3, 'completed')];
  render(<Batches />);
  const active = screen.getByRole('region', { name: 'Active batches' });
  expect(within(active).getByTestId('active-batch-filters')).toHaveClass('w-full', 'overflow-x-auto', 'xl:overflow-visible');
  const activeCards = within(active).getByTestId('compact-batch-list');
  expect(within(activeCards).getByText('Batch #1')).toBeInTheDocument();
  expect(within(activeCards).getByText('Batch #2')).toBeInTheDocument();
  expect(within(activeCards).queryByText('Batch #3')).not.toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'Offered (1)' }));
  expect(within(activeCards).queryByText('Batch #1')).not.toBeInTheDocument();
  expect(within(activeCards).getByText('Batch #2')).toBeInTheDocument();
  expect(screen.queryByRole('dialog', { name: 'Batch history' })).not.toBeInTheDocument();
});

it('uses status-aware primary and secondary actions', () => {
  mocks.props.batches = [
    batchForStatus(1, 'draft'), batchForStatus(2, 'offered'), batchForStatus(3, 'accepted'),
    batchForStatus(4, 'in_progress'), batchForStatus(5, 'completed'), batchForStatus(6, 'cancelled'),
  ];
  render(<Batches />);
  const activeCards = screen.getByTestId('compact-batch-list');
  expect(getCompactBatchButton('Edit batch 1')).toBeInTheDocument();
  expect(getCompactBatchButton('View offer 2')).toBeInTheDocument();
  expect(within(activeCards).queryByRole('button', { name: 'View route 3' })).not.toBeInTheDocument();
  expect(getCompactBatchButton('View details for batch 3')).toBeInTheDocument();
  expect(getCompactBatchButton('View progress 4')).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'History (2)' }));
  const history = screen.getByRole('dialog', { name: 'Batch history' });
  expect(within(history).queryByRole('button', { name: 'View summary 5' })).not.toBeInTheDocument();
  expect(within(history).queryByRole('button', { name: 'View summary 6' })).not.toBeInTheDocument();
  expect(getCompactBatchButton('View details for batch 5', history)).toBeInTheDocument();
  expect(getCompactBatchButton('View details for batch 6', history)).toBeInTheDocument();
  expect(within(activeCards).queryByLabelText('More actions for batch 4')).not.toBeInTheDocument();
});

it('opens cancelled batch details without a summary action', () => {
  mocks.props.batches = [{
    ...batchForStatus(6, 'cancelled'),
    legs: [],
    cancellation_reason: 'Customer requested another date',
    cancelled_stops: [{ ...scheduledLeg, id: 60, stop_sequence: 1 }],
  }];
  render(<Batches />);
  fireEvent.click(screen.getByRole('button', { name: 'History (1)' }));
  const history = screen.getByRole('dialog', { name: 'Batch history' });
  expect(within(history).queryByRole('button', { name: 'View summary 6' })).not.toBeInTheDocument();
  fireEvent.click(getCompactBatchButton('View details for batch 6', history));

  const details = screen.getByRole('dialog', { name: 'Batch 6 details' });
  expect(details).toHaveTextContent('Order #81');
});

it('keeps saved stops visible in a cancelled batch history card', () => {
  mocks.props.batches = [{
    ...batchForStatus(6, 'cancelled'),
    legs: [],
    cancelled_stops: [{ ...scheduledLeg, id: 60, stop_sequence: 1, urgent_at: '2026-07-15T08:00:00Z' }],
  }];
  render(<Batches />);
  fireEvent.click(screen.getByRole('button', { name: 'History (1)' }));
  const history = screen.getByRole('dialog', { name: 'Batch history' });
  fireEvent.click(getCompactBatchButton('View details for batch 6', history));
  const details = screen.getByRole('dialog', { name: 'Batch 6 details' });

  expect(details).toHaveTextContent('Order #81');
  expect(details).toHaveTextContent('Urgent');
});

it.each(['completed', 'cancelled'])('uses the immutable stop snapshot throughout %s history', (status) => {
  mocks.props.batches = [{
    ...batchForStatus(6, status),
    assigned_stop_count: 99,
    legs: [],
    stop_snapshot: historicalStops,
    cancelled_stops: [{ ...scheduledLeg, id: 60, destination_snapshot: { name: 'Mutable cancellation', address: 'Wrong address' } }],
  }];
  render(<Batches />);
  fireEvent.click(screen.getByRole('button', { name: 'History (1)' }));
  const history = screen.getByRole('dialog', { name: 'Batch history' });

  expect(history).toHaveTextContent('2/10');
  expect(history).toHaveTextContent('1 urgent');
  fireEvent.click(getCompactBatchButton('View details for batch 6', history));
  const details = screen.getByRole('dialog', { name: 'Batch 6 details' });
  const cardRows = within(details).getAllByText(/Snapshot (First|Second)/);
  expect(cardRows.map((row) => row.textContent)).toEqual(['Snapshot First', 'Snapshot Second']);
  expect(within(details).queryByText('Mutable cancellation')).not.toBeInTheDocument();

  expect(within(details).getByText('2/10 stops')).toBeInTheDocument();
});

it('uses the persisted batch capacity in history details', () => {
  mocks.props.dailyRiderCapacity = 1;
  mocks.props.batches = [{
    ...batchForStatus(5, 'completed'), capacity: 3, legs: [], stop_snapshot: historicalStops,
  }];
  render(<Batches />);
  fireEvent.click(screen.getByRole('button', { name: 'History (1)' }));
  fireEvent.click(getCompactBatchButton('View details for batch 5', screen.getByRole('dialog', { name: 'Batch history' })));
  const details = screen.getByRole('dialog', { name: 'Batch 5 details' });

  expect(within(details).getByText('2/3 stops')).toBeInTheDocument();
  expect(within(details).queryByText(/exceeds the daily rider capacity/)).not.toBeInTheDocument();
});

it('falls back from an empty snapshot to saved cancellation stops', () => {
  mocks.props.batches = [{
    ...batchForStatus(6, 'cancelled'), legs: [], stop_snapshot: [],
    cancelled_stops: [{ ...scheduledLeg, id: 60, destination_snapshot: { name: 'Saved cancellation', address: 'Saved address' } }],
  }];
  render(<Batches />);
  fireEvent.click(screen.getByRole('button', { name: 'History (1)' }));
  const history = screen.getByRole('dialog', { name: 'Batch history' });
  fireEvent.click(getCompactBatchButton('View details for batch 6', history));
  const details = screen.getByRole('dialog', { name: 'Batch 6 details' });

  expect(within(details).getByText('Saved cancellation')).toBeInTheDocument();
  expect(within(details).getByText('1/10 stops')).toBeInTheDocument();
});

it('falls back from empty persisted history to live legs', () => {
  mocks.props.batches = [{
    ...batchForStatus(5, 'completed'), stop_snapshot: null, cancelled_stops: [],
    legs: [{ ...scheduledLeg, id: 50, status: 'delivered', destination_snapshot: { name: 'Legacy live stop', address: 'Live address' } }],
  }];
  render(<Batches />);
  fireEvent.click(screen.getByRole('button', { name: 'History (1)' }));
  const history = screen.getByRole('dialog', { name: 'Batch history' });
  fireEvent.click(getCompactBatchButton('View details for batch 5', history));
  const details = screen.getByRole('dialog', { name: 'Batch 5 details' });

  expect(within(details).getByText('Legacy live stop')).toBeInTheDocument();
  expect(within(details).getByText('1/10 stops')).toBeInTheDocument();
});

it.each(['completed', 'cancelled'])('shows unavailable history details only when every %s source is empty', (status) => {
  mocks.props.batches = [{
    ...batchForStatus(6, status), legs: [], stop_snapshot: [], cancelled_stops: [],
  }];
  render(<Batches />);
  fireEvent.click(screen.getByRole('button', { name: 'History (1)' }));
  const history = screen.getByRole('dialog', { name: 'Batch history' });
  expect(within(history).queryByText('Historical stop details unavailable')).not.toBeInTheDocument();

  fireEvent.click(getCompactBatchButton('View details for batch 6', history));
  const details = screen.getByRole('dialog', { name: 'Batch 6 details' });
  expect(details).toHaveTextContent('Historical stop details unavailable');
  expect(details).toHaveTextContent('Historical stop details unavailable');
});

it.each([null, []])('falls back to live stops when cancelled_stops is %j', (cancelledStops) => {
  mocks.props.batches = [{
    ...batchForStatus(6, 'cancelled'),
    legs: [{ ...scheduledLeg, id: 60, stop_sequence: 1, urgent_at: '2026-07-15T08:00:00Z' }],
    cancelled_stops: cancelledStops,
  }];
  render(<Batches />);
  fireEvent.click(screen.getByRole('button', { name: 'History (1)' }));
  const history = screen.getByRole('dialog', { name: 'Batch history' });
  fireEvent.click(getCompactBatchButton('View details for batch 6', history));
  const details = screen.getByRole('dialog', { name: 'Batch 6 details' });

  expect(details).toHaveTextContent('Order #81');
  expect(details).toHaveTextContent('Urgent');
});

it('restores a cancelled history batch after confirmation', async () => {
  mocks.props.batches = [{
    ...batchForStatus(6, 'cancelled'),
    cancelled_stops: [{ ...scheduledLeg, id: 60, stop_sequence: 1 }],
  }];
  render(<Batches />);
  fireEvent.click(screen.getByRole('button', { name: 'History (1)' }));
  fireEvent.click(getCompactBatchButton('Restore batch 6', screen.getByRole('dialog', { name: 'Batch history' })));

  await waitFor(() => expect(mocks.restoreBatch).toHaveBeenCalledWith(6));
  expect(mocks.confirm).toHaveBeenCalledWith(expect.objectContaining({ title: 'Restore Batch #6?' }));
  expect(mocks.toast).toHaveBeenCalledWith('success', 'Batch restored to draft');
});

it('keeps active offered routes read-only without urgency controls', () => {
  mocks.props.batches = [batchForStatus(2, 'offered')];
  render(<Batches />);
  fireEvent.click(getCompactBatchButton('View offer 2'));

  expect(screen.queryByRole('button', { name: 'Move stop 1 up' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Remove stop 1' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /urgent stop/i })).not.toBeInTheDocument();
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
  fireEvent.click(screen.getByRole('button', { name: 'Show available deliveries' }));
  fireEvent.click(screen.getByRole('button', { name: 'Move stop 2 up' }));

  expect(await screen.findAllByTestId('delivery-skeleton')).toHaveLength(3);
});
