import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import Swal from 'sweetalert2';
import Shipments from '../Shipments';

const mocks = vi.hoisted(() => ({ post: vi.fn(() => Promise.resolve()), get: vi.fn(), reload: vi.fn(), props: {} as any }));

const defaultProps = () => ({
  shipments: { data: [{ id: 1, purpose: 'retail_delivery', status: 'active', source_type: 'order', source_id: 10, order_summary: {
    available: true,
    order_id: 10,
    order_number: 'ORD-LOG-1001',
    total_quantity: 5,
    variant_count: 2,
    model_count: 2,
    items: [
      { id: 101, brand: 'Nike', model: 'Air Max 90', image: 'products/air-max.jpg', color: 'Black', size: '9', quantity: 2 },
      { id: 102, brand: null, model: 'Classic Runner', image: null, color: 'White', size: '8', quantity: 3 },
    ],
  }, legs: [{
    id: 2, leg_type: 'outbound', status: 'in_transit', assignments: [{ id: 3, status: 'accepted' }], proofs: [], attempts: [],
    destination_snapshot: { name: 'Miguel Dela Rosa', address: 'Dasmariñas, Cavite', phone: '09053338826' },
  }] }], links: [], from: 1, to: 1, total: 1, current_page: 1, last_page: 1 },
  filters: { status: 'all', purpose: 'all', window: 'all', module: 'all' }, assignableRiders: [],
  canAssign: false, canUpdateStatus: false, canRecordProof: true, canApproveProof: false, riderMode: true, batches: [],
  maxDeliveryAttempts: 2,
  today: '2026-07-21',
  availableModules: ['retail'],
  showModuleFilter: false,
});

vi.mock('@inertiajs/react', () => ({
  Head: () => null, Link: ({ children }: React.PropsWithChildren) => <a>{children}</a>, router: { get: mocks.get, reload: mocks.reload },
  usePage: () => ({ props: mocks.props }),
}));
vi.mock('axios', () => ({ default: { post: mocks.post } }));
vi.mock('sweetalert2', () => ({ default: { fire: vi.fn(() => Promise.resolve({ isConfirmed: true })) } }));
vi.mock('@/layout/AppLayout_ERP', () => ({ default: ({ children }: React.PropsWithChildren) => <>{children}</> }));

beforeEach(() => {
  vi.clearAllMocks();
  mocks.props = defaultProps();
  mocks.post.mockResolvedValue(undefined);
  mocks.reload.mockImplementation((options) => options?.onFinish?.());
});

const setDispatcherLeg = (leg: Record<string, unknown>) => {
  mocks.props = defaultProps();
  mocks.props.riderMode = false;
  mocks.props.canAssign = true;
  mocks.props.canRecordProof = false;
  mocks.props.assignableRiders = [{ id: 9, name: 'Rider Nine' }];
  mocks.props.shipments.data[0].legs = [leg];
};

it('renders responsive shipment cards without a wide table', () => {
  render(<Shipments><p>Batch panel</p></Shipments>);
  expect(screen.getByText('Batch panel')).toBeInTheDocument();
  expect(screen.getByText('Miguel Dela Rosa')).toBeInTheDocument();
  expect(screen.queryByRole('table')).not.toBeInTheDocument();
  expect(screen.getByText('Dasmariñas, Cavite')).toBeInTheDocument();
});

it('expands shipment details accessibly without mutation permission', () => {
  mocks.props.canRecordProof = false;
  render(<Shipments />);

  const open = screen.getByRole('button', { name: 'Open delivery' });
  expect(open).toHaveAttribute('aria-expanded', 'false');
  fireEvent.click(open);
  expect(screen.getByRole('button', { name: 'Close delivery' })).toHaveAttribute('aria-expanded', 'true');
  expect(screen.getByRole('region', { name: 'Shipment 1 details' })).toBeInTheDocument();
});

it('shows dispatcher pickup and customer arrival checks with reasons', () => {
  setDispatcherLeg({
    ...defaultProps().shipments.data[0].legs[0],
    arrivals: {
      pickup: {
        result: 'verified', distance_m: 18.2, radius_m: 100, accuracy_m: 12,
        recorded_at: '2026-07-15T02:30:00Z',
      },
      dropoff: {
        result: 'outside_geofence', distance_m: 154.6, radius_m: 100, accuracy_m: 15,
        exception_reason: 'pin_incorrect', exception_notes: 'Customer met rider at the gate.',
        recorded_at: '2026-07-15T03:45:00Z',
      },
    },
  });
  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));

  expect(screen.getByText('Pickup arrival')).toBeInTheDocument();
  expect(screen.getByText('Verified arrival')).toBeInTheDocument();
  expect(screen.getByText('18 m from pickup')).toBeInTheDocument();
  expect(screen.getByText('Customer arrival')).toBeInTheDocument();
  expect(screen.getByText('Outside geofence')).toBeInTheDocument();
  expect(screen.getByText('Pin incorrect')).toBeInTheDocument();
  expect(screen.getByText('Customer met rider at the gate.')).toBeInTheDocument();
});

it('distinguishes low GPS accuracy from unavailable location', () => {
  setDispatcherLeg({
    ...defaultProps().shipments.data[0].legs[0],
    arrivals: {
      pickup: { result: 'low_accuracy', exception_reason: 'access_restriction', recorded_at: '2026-07-15T02:30:00Z' },
      dropoff: { result: 'location_unavailable', exception_reason: 'safety_concern', recorded_at: '2026-07-15T03:45:00Z' },
    },
  });
  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));

  expect(screen.getByText('Low GPS accuracy')).toBeInTheDocument();
  expect(screen.getByText('Access restriction')).toBeInTheDocument();
  expect(screen.getByText('Location unavailable')).toBeInTheDocument();
  expect(screen.getByText('Safety concern')).toBeInTheDocument();
});

it('submits server-side search and resets pagination', () => {
  render(<Shipments />);
  fireEvent.change(screen.getByLabelText('Search shipments'), { target: { value: 'Air Max' } });
  fireEvent.submit(screen.getByRole('search'));

  expect(mocks.get).toHaveBeenCalledWith('/erp/logistics/deliveries', {
    status: 'all',
    purpose: 'all',
    window: 'all',
    module: 'all',
    search: 'Air Max',
    page: 1,
  }, {
    preserveScroll: true,
    preserveState: true,
  });
});

it('shows readable schedules and operational indicators', () => {
  vi.useFakeTimers();
  vi.setSystemTime(new Date('2026-07-21T00:00:00Z'));
  try {
    mocks.props.shipments.data[0].legs[0] = {
      ...mocks.props.shipments.data[0].legs[0],
      status: 'awaiting_proof_approval',
      scheduled_delivery_date: '2026-07-20',
      delivery_window: 'morning',
      urgent_at: '2026-07-19T01:00:00Z',
      attempts: [{ id: 7, status: 'failed', attempt_number: 1 }],
    };
    render(<Shipments />);

    expect(screen.getByText('Jul 20, 2026 · Morning')).toBeInTheDocument();
    expect(screen.getByText('Urgent')).toBeInTheDocument();
    expect(screen.getByText('Overdue')).toBeInTheDocument();
    expect(screen.getByText('Failed attempt')).toBeInTheDocument();
    expect(screen.getByText('Awaiting proof')).toBeInTheDocument();
  } finally {
    vi.useRealTimers();
  }
});

it('uses the shop-local date for overdue deliveries at the UTC boundary', () => {
  vi.useFakeTimers();
  vi.setSystemTime(new Date('2026-07-20T17:00:00Z'));
  try {
    mocks.props.shipments.data[0].legs[0].scheduled_delivery_date = '2026-07-20';
    render(<Shipments />);

    expect(screen.getByText('Overdue')).toBeInTheDocument();
  } finally {
    vi.useRealTimers();
  }
});

it('identifies each shipment in expansion control labels', () => {
  const second = structuredClone(mocks.props.shipments.data[0]);
  second.id = 2;
  mocks.props.shipments.data.push(second);
  render(<Shipments />);

  expect(screen.getByRole('button', { name: 'Open delivery for Shipment 1' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Open delivery for Shipment 2' })).toBeInTheDocument();
});

it('distinguishes empty history from filtered results', () => {
  mocks.props.shipments = { data: [], links: [], from: null, to: null, total: 0, current_page: 1, last_page: 1 };
  const view = render(<Shipments />);
  expect(screen.getByText('No shipments yet.')).toBeInTheDocument();

  mocks.props.filters.search = 'Nike';
  view.rerender(<Shipments />);
  expect(screen.getByText('No shipments match your filters.')).toBeInTheDocument();
});

it('shows missing order fallback inside the card and shortens long addresses', () => {
  const longAddress = '123 Very Long Street, Barangay Sample, DasmariÃ±as City, Cavite, Region IV-A, 4114';
  mocks.props.shipments.data[0].order_summary = {
    available: false,
    order_id: 10,
    order_number: null,
    items: [],
    total_quantity: 0,
    variant_count: 0,
    model_count: 0,
  };
  mocks.props.shipments.data[0].legs[0].destination_snapshot.address = longAddress;

  render(<Shipments />);
  expect(screen.getByRole('article')).toBeInTheDocument();
  expect(screen.getByText('Order details unavailable')).toBeInTheDocument();
  expect(screen.getByTitle(longAddress)).toHaveTextContent('…');
});

it('shows a compact retail summary and every variant when expanded', () => {
  render(<Shipments />);

  expect(screen.getByText('Nike Air Max 90')).toBeInTheDocument();
  expect(screen.getByText(/5 pairs/)).toBeInTheDocument();
  expect(screen.getByText(/2 variants/)).toBeInTheDocument();
  expect(screen.getByText(/\+1 more/)).toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));
  expect(screen.getByText(/Black.*Size 9.*Qty 2/)).toBeInTheDocument();
  expect(screen.getByText('Classic Runner')).toBeInTheDocument();
  expect(screen.getByText(/White.*Size 8.*Qty 3/)).toBeInTheDocument();
});

it('shows repair-only purposes and retains compatible filters when changing module', () => {
  mocks.props = defaultProps();
  mocks.props.riderMode = false;
  mocks.props.canRecordProof = false;
  mocks.props.showModuleFilter = true;
  mocks.props.availableModules = ['retail', 'repair'];
  mocks.props.filters = { status: 'active', purpose: 'repair_pickup', window: 'morning', module: 'repair' };
  mocks.props.shipments.data[0] = {
    ...mocks.props.shipments.data[0],
    purpose: 'repair_pickup',
    source_type: 'repair_request',
    order_summary: null,
    source_summary: {
      request_number: 'REP-2026-0042',
      customer_name: 'Mia Santos',
      shoe_summary: 'Nike Air Max 90',
    },
  };

  render(<Shipments />);
  expect(screen.getByRole('option', { name: 'Repair Pickup' })).toBeInTheDocument();
  expect(screen.getByRole('option', { name: 'Repair Return' })).toBeInTheDocument();
  expect(screen.queryByRole('option', { name: 'Retail Delivery' })).not.toBeInTheDocument();
  expect(screen.getByText('Repair', { selector: 'span' })).toBeInTheDocument();
  expect(screen.getByText('Repair REP-2026-0042')).toBeInTheDocument();
  expect(screen.getByText('Mia Santos · Nike Air Max 90')).toBeInTheDocument();

  fireEvent.change(screen.getByLabelText('Filter shipments by module'), { target: { value: 'retail' } });
  expect(mocks.get).toHaveBeenCalledWith('/erp/logistics/shipments', {
    status: 'active',
    purpose: 'all',
    window: 'morning',
    module: 'retail',
    page: 1,
  }, {
    preserveScroll: true,
    preserveState: true,
  });
});

it('hides the redundant module selector for a single-module shop', () => {
  mocks.props = defaultProps();
  mocks.props.riderMode = false;
  render(<Shipments />);

  expect(screen.queryByLabelText('Filter shipments by module')).not.toBeInTheDocument();
});

it('shows failed-attempt filter and retryable reassignment controls', () => {
  setDispatcherLeg({ id: 2, leg_type: 'outbound', status: 'pending', scheduled_delivery_date: '2026-07-20', assignments: [], proofs: [], failed_attempt_count: 1, attempts: [{ id: 7, status: 'failed', attempt_number: 1, reason_code: 'recipient_unavailable' }] });
  render(<Shipments />);
  expect(screen.getByRole('option', { name: 'Failed attempts' })).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));
  expect(screen.getByText('Failed attempt - 1/2')).toBeInTheDocument();
  expect(screen.getByText('Recipient Unavailable')).toBeInTheDocument();
  expect(screen.getByLabelText('Choose rider for outbound leg')).toBeInTheDocument();
});

it('lets dispatchers review and resolve failed repair pickups', async () => {
  setDispatcherLeg({
    id: 91,
    leg_type: 'inbound',
    status: 'needs_resolution',
    resolution_type: 'pickup_failed',
    assignments: [],
    proofs: [],
    failed_attempt_count: 0,
    failed_pickup_count: 1,
    arrivals: {
      pickup: {
        result: 'verified',
        distance_m: 18,
        radius_m: 100,
        accuracy_m: 12,
        recorded_at: '2026-07-29T09:55:00Z',
      },
    },
    attempts: [{
      id: 91,
      attempt_type: 'pickup',
      status: 'failed',
      attempt_number: 1,
      reason_code: 'customer_unavailable',
      file_path: 'logistics-attempt/91/door.jpg',
      attempted_at: '2026-07-29T10:00:00Z',
    }],
  });
  mocks.props.shipments.data[0].purpose = 'repair_pickup';
  mocks.props.shipments.data[0].source_type = 'repair_request';
  (Swal.fire as any)
    .mockResolvedValueOnce({ isConfirmed: true, value: 'Customer confirmed Monday.' })
    .mockResolvedValueOnce({})
    .mockResolvedValueOnce({ isConfirmed: true, value: 'Customer cancelled.' });

  render(<Shipments />);
  expect(screen.getByRole('option', { name: 'Failed pickups' })).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));

  expect(screen.getAllByText('Failed pickup · Needs action')).toHaveLength(2);
  expect(screen.queryByText(/1\/2/)).not.toBeInTheDocument();
  expect(screen.queryByText('Subject for refund')).not.toBeInTheDocument();
  expect(screen.getByText('Customer Unavailable')).toBeInTheDocument();
  expect(screen.getByText('Pickup arrival')).toBeInTheDocument();
  expect(screen.getByRole('link', { name: 'View failed-pickup photo' }))
    .toHaveAttribute('href', '/storage/logistics-attempt/91/door.jpg');

  fireEvent.click(screen.getByRole('button', { name: 'Reschedule Pickup' }));
  await waitFor(() => expect(mocks.post).toHaveBeenCalledWith(
    '/api/logistics/legs/91/resolve/retry',
    { reason: 'Customer confirmed Monday.' },
    undefined,
  ));
  fireEvent.click(screen.getByRole('button', { name: 'Cancel Pickup' }));
  await waitFor(() => expect(mocks.post).toHaveBeenCalledWith(
    '/api/logistics/legs/91/cancel',
    { reason: 'Customer cancelled.' },
    undefined,
  ));
});

it('shows the pickup retry count without using the delivery-attempt limit', () => {
  setDispatcherLeg({
    id: 91,
    leg_type: 'inbound',
    status: 'assigned',
    resolution_type: 'retry',
    assignments: [{ id: 92, status: 'assigned', rider_profile: { name: 'Rider Nine' } }],
    proofs: [],
    failed_attempt_count: 0,
    failed_pickup_count: 1,
    attempts: [{
      id: 91,
      attempt_type: 'pickup',
      status: 'failed',
      attempt_number: 1,
      reason_code: 'customer_unavailable',
    }],
  });
  mocks.props.shipments.data[0].purpose = 'repair_pickup';
  mocks.props.shipments.data[0].source_type = 'repair_request';

  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));

  expect(screen.getAllByText('Pickup rescheduled')).toHaveLength(2);
  expect(screen.getByText('Failed pickup · 1 attempt')).toBeInTheDocument();
  expect(screen.queryByText('Failed attempt - 0/2')).not.toBeInTheDocument();
});

it('hides failed pickup actions without dispatcher assignment permission', () => {
  setDispatcherLeg({
    id: 91,
    leg_type: 'inbound',
    status: 'needs_resolution',
    resolution_type: 'pickup_failed',
    failed_pickup_count: 1,
    assignments: [],
    proofs: [],
    attempts: [{ id: 91, attempt_type: 'pickup', status: 'failed' }],
  });
  mocks.props.shipments.data[0].purpose = 'repair_pickup';
  mocks.props.shipments.data[0].source_type = 'repair_request';
  mocks.props.canAssign = false;

  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));
  expect(screen.queryByRole('button', { name: 'Reschedule Pickup' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Cancel Pickup' })).not.toBeInTheDocument();
});

it('lets dispatcher reject pending delivery proof with a reason', async () => {
  setDispatcherLeg({
    id: 2,
    leg_type: 'outbound',
    status: 'awaiting_proof_approval',
    assignments: [{ id: 3, status: 'accepted' }],
    proofs: [{ id: 17, handoff_type: 'delivery', review_status: 'pending', file_path: 'proof.jpg' }],
    attempts: [],
  });
  mocks.props.canApproveProof = true;
  (Swal.fire as any).mockResolvedValueOnce({ isConfirmed: true, value: 'Photo does not show the recipient.' });

  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));
  expect(screen.getByRole('link', { name: 'Open uploaded delivery proof' }))
    .toHaveAttribute('href', '/api/logistics/proofs/17/file');
  fireEvent.click(screen.getByRole('button', { name: 'Reject proof' }));

  await waitFor(() => expect(mocks.post).toHaveBeenCalledWith(
    '/api/logistics/proofs/17/reject',
    { rejection_reason: 'Photo does not show the recipient.' },
    undefined,
  ));
});

it('shows subject for refund without reassignment at maximum attempts', () => {
  setDispatcherLeg({ id: 2, leg_type: 'outbound', status: 'needs_resolution', assignments: [], proofs: [], failed_attempt_count: 2, attempts: [{ id: 8, status: 'failed', attempt_number: 2, reason_code: 'recipient_refused' }] });
  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));
  expect(screen.getByText('Failed attempt - 2/2')).toBeInTheDocument();
  expect(screen.getByText('Subject for refund')).toBeInTheDocument();
  expect(screen.queryByLabelText('Choose rider for outbound leg')).not.toBeInTheDocument();
});

it('shows the dispatcher resolution selected for a delivery', () => {
  setDispatcherLeg({
    id: 2,
    leg_type: 'outbound',
    status: 'pending',
    resolution_type: 'retry',
    resolution_reason: 'Customer requested tomorrow morning.',
    assignments: [],
    proofs: [],
    attempts: [],
  });
  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));

  expect(screen.getByText(
    'Dispatcher scheduled another attempt: Customer requested tomorrow morning.',
  )).toBeInTheDocument();
});

it('keeps the legacy rider issue form aligned with the evidence matrix', () => {
  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));
  fireEvent.click(screen.getByRole('button', { name: "Couldn't deliver" }));

  for (const option of ['Item damaged', 'Unsafe location']) {
    expect(screen.getByRole('option', { name: option })).toBeInTheDocument();
  }

  const reason = screen.getByLabelText('Issue reason');
  const photo = screen.getByLabelText('Issue photo');
  const notes = screen.getByPlaceholderText('Optional note');
  fireEvent.change(reason, { target: { value: 'item_damaged' } });
  expect(photo).toBeRequired();
  expect(notes).not.toBeRequired();
  fireEvent.change(reason, { target: { value: 'unsafe_location' } });
  expect(photo).not.toBeRequired();
  expect(notes).toBeRequired();
});

it('shows one delivery outcome workflow at a time and clears hidden state', () => {
  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));
  expect(screen.queryByLabelText('Delivery proof photo')).not.toBeInTheDocument();
  expect(screen.queryByLabelText('Issue photo')).not.toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'Delivered successfully' }));
  const deliveryPhoto = screen.getByLabelText('Delivery proof photo');
  fireEvent.change(deliveryPhoto, { target: { files: [new File(['proof'], 'proof.jpg', { type: 'image/jpeg' })] } });
  expect(screen.getByRole('button', { name: 'Submit proof' })).toBeInTheDocument();
  expect(screen.queryByLabelText('Issue reason')).not.toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: "Couldn't deliver" }));
  expect(screen.queryByLabelText('Delivery proof photo')).not.toBeInTheDocument();
  const issueReason = screen.getByLabelText('Issue reason');
  const issuePhoto = screen.getByLabelText('Issue photo');
  const issueNote = screen.getByPlaceholderText('Optional note');
  const reportIssue = screen.getByRole('button', { name: 'Report issue' });
  expect(reportIssue).toBeDisabled();
  fireEvent.change(issueReason, { target: { value: 'recipient_refused' } });
  fireEvent.change(issuePhoto, { target: { files: [new File(['attempt'], 'attempt.jpg', { type: 'image/jpeg' })] } });
  fireEvent.change(issueNote, { target: { value: 'Customer declined.' } });
  expect(reportIssue).toBeEnabled();

  fireEvent.click(screen.getByRole('button', { name: 'Delivered successfully' }));
  fireEvent.click(screen.getByRole('button', { name: 'Submit proof' }));
  expect(mocks.post).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button', { name: "Couldn't deliver" }));
  expect(screen.getByLabelText('Issue reason')).toHaveValue('');
  expect(screen.getByPlaceholderText('Optional note')).toHaveValue('');
  expect(screen.getByRole('button', { name: 'Report issue' })).toBeDisabled();
});

it('submits required issue evidence only to the failed-attempt endpoint', async () => {
  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));
  fireEvent.click(screen.getByRole('button', { name: "Couldn't deliver" }));
  const reportIssue = screen.getByRole('button', { name: 'Report issue' });
  const attemptPhoto = new File(['attempt'], 'attempt.jpg', { type: 'image/jpeg' });

  expect(reportIssue).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Issue reason'), { target: { value: 'recipient_unavailable' } });
  expect(reportIssue).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Issue photo'), { target: { files: [attemptPhoto] } });
  expect(reportIssue).toBeEnabled();
  fireEvent.click(reportIssue);

  await waitFor(() => expect(mocks.post).toHaveBeenCalled());
  const [url, body] = mocks.post.mock.calls[0];
  expect(url).toBe('/api/logistics/legs/2/report-issue');
  expect(body).toBeInstanceOf(FormData);
  expect((body as FormData).get('reason_code')).toBe('recipient_unavailable');
  expect((body as FormData).get('delivery_assignment_id')).toBe('3');
  expect((body as FormData).get('proof_file')).toBe(attemptPhoto);
  expect(mocks.post.mock.calls.some(([requestUrl]) => requestUrl === '/api/logistics/legs/2/proof')).toBe(false);
  expect(mocks.reload).toHaveBeenCalledWith({ only: ['shipments', 'batches'] });
});

it('schedules an eligible pending leg before assigning its rider', async () => {
  setDispatcherLeg({ id: 2, leg_type: 'outbound', status: 'pending', delivery_batch_id: null, assignments: [], proofs: [], attempts: [] });
  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));

  const submit = screen.getByRole('button', { name: 'Schedule & assign rider' });
  expect(screen.getByLabelText('Delivery window')).toHaveValue('morning');
  expect(submit).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Delivery date'), { target: { value: '2026-07-20' } });
  fireEvent.change(screen.getByLabelText('Delivery window'), { target: { value: 'morning' } });
  fireEvent.change(screen.getByLabelText('Choose rider for outbound leg'), { target: { value: '9' } });
  expect(submit).toBeEnabled();
  fireEvent.click(submit);

  await waitFor(() => expect(mocks.post).toHaveBeenCalledTimes(2));
  expect(mocks.post.mock.calls[0]).toEqual(['/api/logistics/legs/schedule', {
    delivery_date: '2026-07-20', delivery_window: 'morning', leg_ids: [2],
  }]);
  expect(mocks.post.mock.calls[1]).toEqual(['/api/logistics/legs/2/assign', {
    assignment_type: 'internal_rider', rider_profile_id: 9,
  }]);
});

it('saves the schedule without assigning an already assigned leg', async () => {
  setDispatcherLeg({ id: 2, leg_type: 'outbound', status: 'assigned', delivery_batch_id: null, assignments: [{ id: 3, status: 'assigned', rider_profile: { id: 9, name: 'Rider Nine' } }], proofs: [], attempts: [] });
  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));
  fireEvent.change(screen.getByLabelText('Delivery date'), { target: { value: '2026-07-20' } });
  fireEvent.change(screen.getByLabelText('Delivery window'), { target: { value: 'afternoon' } });
  fireEvent.click(screen.getByRole('button', { name: 'Save schedule' }));

  await waitFor(() => expect(mocks.post).toHaveBeenCalledTimes(1));
  expect(mocks.post).toHaveBeenCalledWith('/api/logistics/legs/schedule', {
    delivery_date: '2026-07-20', delivery_window: 'afternoon', leg_ids: [2],
  });
});

it('hides scheduling controls without assign permission', () => {
  setDispatcherLeg({ id: 2, leg_type: 'outbound', status: 'pending', delivery_batch_id: null, assignments: [], proofs: [], attempts: [] });
  mocks.props.canAssign = false;
  mocks.props.canApproveProof = true;
  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));
  expect(screen.queryByLabelText('Delivery date')).not.toBeInTheDocument();
  expect(screen.queryByLabelText('Delivery window')).not.toBeInTheDocument();
});

it('hides scheduling controls for a leg already in a delivery batch', () => {
  setDispatcherLeg({ id: 2, leg_type: 'outbound', status: 'pending', delivery_batch_id: 7, assignments: [], proofs: [], attempts: [] });
  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));
  expect(screen.queryByLabelText('Delivery date')).not.toBeInTheDocument();
  expect(screen.queryByLabelText('Delivery window')).not.toBeInTheDocument();
});

it('retries only assignment after scheduling succeeded and refreshed props show the schedule', async () => {
  setDispatcherLeg({ id: 2, leg_type: 'outbound', status: 'pending', delivery_batch_id: null, assignments: [], proofs: [], attempts: [] });
  mocks.post.mockResolvedValueOnce(undefined).mockRejectedValueOnce({ response: { data: { message: 'Rider unavailable.' } } });
  const view = render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));
  fireEvent.change(screen.getByLabelText('Delivery date'), { target: { value: '2026-07-20' } });
  fireEvent.change(screen.getByLabelText('Delivery window'), { target: { value: 'morning' } });
  fireEvent.change(screen.getByLabelText('Choose rider for outbound leg'), { target: { value: '9' } });
  fireEvent.click(screen.getByRole('button', { name: 'Schedule & assign rider' }));
  await waitFor(() => expect(mocks.reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['shipments', 'assignableRiders'] })));

  mocks.props.shipments.data[0].legs[0] = { ...mocks.props.shipments.data[0].legs[0], scheduled_delivery_date: '2026-07-20', delivery_window: 'morning' };
  mocks.post.mockResolvedValue(undefined);
  view.rerender(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Assign' }));

  await waitFor(() => expect(mocks.post).toHaveBeenCalledTimes(3));
  expect(mocks.post.mock.calls.filter(([url]) => url === '/api/logistics/legs/schedule')).toHaveLength(1);
  expect(mocks.post.mock.calls[2][0]).toBe('/api/logistics/legs/2/assign');
});

it('keeps scheduling locked until refreshed shipment props finish loading', async () => {
  setDispatcherLeg({ id: 2, leg_type: 'outbound', status: 'pending', delivery_batch_id: null, assignments: [], proofs: [], attempts: [] });
  mocks.reload.mockImplementation(() => undefined);
  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));
  fireEvent.change(screen.getByLabelText('Delivery date'), { target: { value: '2026-07-20' } });
  fireEvent.change(screen.getByLabelText('Delivery window'), { target: { value: 'morning' } });
  fireEvent.change(screen.getByLabelText('Choose rider for outbound leg'), { target: { value: '9' } });
  const submit = screen.getByRole('button', { name: 'Schedule & assign rider' });
  fireEvent.click(submit);
  await waitFor(() => expect(mocks.reload).toHaveBeenCalled());

  expect(submit).toBeDisabled();
  fireEvent.click(submit);
  await Promise.resolve();
  expect(mocks.post.mock.calls.filter(([url]) => url === '/api/logistics/legs/schedule')).toHaveLength(1);
});

it.each(['assigned', 'picked_up'])('hides failed-attempt controls while a leg is %s', (status) => {
  mocks.props = defaultProps();
  mocks.props.shipments.data[0].legs[0].status = status;
  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));
  expect(screen.queryByText("Couldn't deliver")).not.toBeInTheDocument();
  expect(screen.queryByLabelText('Issue reason')).not.toBeInTheDocument();
});

it('shows failed-attempt controls for an in-transit leg', () => {
  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));
  fireEvent.click(screen.getByRole('button', { name: "Couldn't deliver" }));
  expect(screen.getByLabelText('Issue reason')).toBeInTheDocument();
});

it('shows failed-attempt controls for a delivery-attempted leg', () => {
  mocks.props = defaultProps();
  mocks.props.shipments.data[0].legs[0].status = 'delivery_attempted';
  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));
  expect(screen.getByLabelText('Issue reason')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Request cancellation' })).toBeInTheDocument();
});

it('uses return handoff controls instead of customer delivery outcomes', async () => {
  mocks.props = defaultProps();
  mocks.props.shipments.data[0].legs[0] = {
    ...mocks.props.shipments.data[0].legs[0],
    leg_type: 'return_to_shop',
  };
  mocks.post
    .mockResolvedValueOnce({ data: { proof: { id: 17 } } })
    .mockResolvedValueOnce({ data: { leg: { id: 2 } } });

  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));

  expect(screen.queryByText('Delivered successfully')).not.toBeInTheDocument();
  expect(screen.queryByText("Couldn't deliver")).not.toBeInTheDocument();
  const photo = new File(['return'], 'return.jpg', { type: 'image/jpeg' });
  fireEvent.change(screen.getByLabelText('Return handoff photo'), { target: { files: [photo] } });
  fireEvent.click(screen.getByRole('button', { name: 'Confirm return handoff' }));

  await waitFor(() => expect(mocks.post).toHaveBeenCalledTimes(2));
  const [proofUrl, proofBody] = mocks.post.mock.calls[0];
  expect(proofUrl).toBe('/api/logistics/legs/2/proof');
  expect((proofBody as FormData).get('handoff_type')).toBe('receive');
  expect((proofBody as FormData).get('proof_file')).toBe(photo);
  expect(mocks.post.mock.calls[1][0]).toBe('/api/logistics/legs/2/return-proofs/17/handoff');
});

it('lets staff confirm a rider-confirmed return receipt', async () => {
  setDispatcherLeg({
    id: 2,
    leg_type: 'return_to_shop',
    status: 'in_transit',
    assignments: [{ id: 3, status: 'accepted' }],
    attempts: [],
    proofs: [{ id: 17, handoff_type: 'receive', review_status: 'rider_confirmed', file_path: 'return.jpg' }],
  });
  mocks.props.canApproveProof = true;

  render(<Shipments />);
  fireEvent.click(screen.getByRole('button', { name: 'Open delivery' }));

  fireEvent.click(screen.getByRole('button', { name: 'Confirm return received' }));
  await waitFor(() => expect(mocks.post).toHaveBeenCalledWith('/api/logistics/legs/2/return-proofs/17/receipt', undefined, undefined));
});
