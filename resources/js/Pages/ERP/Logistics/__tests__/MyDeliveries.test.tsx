import React from 'react';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MyDeliveries from '../MyDeliveries';

const mocks = vi.hoisted(() => ({
  props: {
    deliveryData: {
      offers: [],
      current: null,
      active_conflicts: [],
      has_active_conflict: false,
      up_next: null,
      list: {
        data: [],
        links: [],
        from: null,
        to: null,
        total: 0,
        current_page: 1,
        last_page: 1,
      },
      filters: { tab: 'upcoming', business: 'all', window: 'all', search: '' },
    } as any,
    canRecordProof: true,
    maxDeliveryAttempts: 2,
    today: '2026-07-29',
  },
  reload: vi.fn((options?: { onFinish?: () => void }) => options?.onFinish?.()),
  get: vi.fn(),
  post: vi.fn(() => Promise.resolve()),
  confirm: vi.fn(() => Promise.resolve({ isConfirmed: true })),
  acceptBatch: vi.fn(() => Promise.resolve()),
  rejectBatch: vi.fn(() => Promise.resolve()),
  acceptLeg: vi.fn(() => Promise.resolve()),
  rejectLeg: vi.fn(() => Promise.resolve()),
  startBatch: vi.fn(() => Promise.resolve()),
  markPickedUp: vi.fn(() => Promise.resolve()),
  confirmPickup: vi.fn(() => Promise.resolve()),
  outForDelivery: vi.fn(() => Promise.resolve()),
  markInTransit: vi.fn(() => Promise.resolve()),
  retryDelivery: vi.fn(() => Promise.resolve()),
  arrive: vi.fn(() => Promise.resolve()),
  reportIssue: vi.fn(() => Promise.resolve()),
  reportIncident: vi.fn(() => Promise.resolve()),
  recordProof: vi.fn(() => Promise.resolve({ data: { proof: { id: 17 } } })),
  confirmReturnHandoff: vi.fn(() => Promise.resolve()),
  getCurrentPosition: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: ({ title }: { title: string }) => <div data-testid="head-title">{title}</div>,
  usePage: () => ({ props: mocks.props }),
  router: { reload: mocks.reload, get: mocks.get },
}));
vi.mock('@/layout/AppLayout_ERP', () => ({
  default: ({ children }: React.PropsWithChildren) => <main data-testid="erp-layout">{children}</main>,
}));
vi.mock('axios', () => ({ default: { post: mocks.post } }));
vi.mock('@/utils/workflowFeedback', () => ({
  workflowFeedback: { confirm: mocks.confirm },
}));
vi.mock('@/services/logisticsApi', () => ({ logisticsApi: {
  acceptBatch: mocks.acceptBatch,
  rejectBatch: mocks.rejectBatch,
  acceptLeg: mocks.acceptLeg,
  rejectLeg: mocks.rejectLeg,
  startBatch: mocks.startBatch,
  markPickedUp: mocks.markPickedUp,
  confirmPickup: mocks.confirmPickup,
  outForDelivery: mocks.outForDelivery,
  markInTransit: mocks.markInTransit,
  retryDelivery: mocks.retryDelivery,
  arrive: mocks.arrive,
  reportIssue: mocks.reportIssue,
  reportIncident: mocks.reportIncident,
  recordProof: mocks.recordProof,
  confirmReturnHandoff: mocks.confirmReturnHandoff,
} }));

const leg = (
  id: number,
  stop_sequence: number | null,
  status = 'assigned',
  name = `Customer ${id}`,
) => ({
  id,
  sequence: 1,
  stop_sequence,
  status,
  leg_type: 'outbound',
  destination_snapshot: {
    name,
    phone: `0900${id}`,
    address: `Address ${id}`,
    delivery_instructions: `Instruction ${id}`,
  },
  shipment: { id: id + 100, source_type: 'order', source_id: id + 200, purpose: 'retail_delivery' },
  proofs: [],
  assignments: [],
  attempts: [],
});

const arrived = (type: 'pickup' | 'dropoff', result = 'verified') => ({
  [type]: {
    id: 90,
    arrival_type: type,
    result,
    distance_m: 18,
    radius_m: 100,
    accuracy_m: 12,
    recorded_at: '2026-07-29T02:30:00.000Z',
  },
});

const workItem = (
  kind: 'batch' | 'single',
  status: string,
  deliveries: any[],
  overrides: Record<string, unknown> = {},
) => ({
  item_type: 'work',
  key: `${kind}:${kind === 'batch' ? 7 : deliveries[0]?.id ?? 9}`,
  kind,
  id: kind === 'batch' ? 7 : deliveries[0]?.id ?? 9,
  status,
  group: 'current',
  business_types: ['retail'],
  business_label: 'Retail delivery',
  delivery_date: '2026-07-29',
  delivery_window: 'morning',
  deliveries,
  ...overrides,
});

const choosePickerOption = (label: string, option: string) => {
  fireEvent.click(screen.getByLabelText(label));
  const picker = screen.getByRole('dialog', { name: label });
  fireEvent.click(within(picker).getByRole('option', { name: option }));
};

beforeEach(() => {
  mocks.props.deliveryData = {
    offers: [],
    current: null,
    active_conflicts: [],
    has_active_conflict: false,
    up_next: null,
    list: {
      data: [],
      links: [],
      from: null,
      to: null,
      total: 0,
      current_page: 1,
      last_page: 1,
    },
    filters: { tab: 'upcoming', business: 'all', window: 'all', search: '' },
  };
  vi.clearAllMocks();
  mocks.getCurrentPosition.mockImplementation((success: PositionCallback) => success({
    coords: { latitude: 14.3, longitude: 120.95, accuracy: 15 },
    timestamp: Date.parse('2026-07-29T02:30:00.000Z'),
  } as GeolocationPosition));
  Object.defineProperty(navigator, 'geolocation', {
    configurable: true,
    value: { getCurrentPosition: mocks.getCurrentPosition },
  });
});

describe('MyDeliveries task-first hierarchy', () => {
  it('shows the page heading, connection status, and one current-delivery card', () => {
    mocks.props.deliveryData.current = workItem('single', 'in_transit', [leg(9, null, 'in_transit')]);

    render(<MyDeliveries />);

    expect(screen.getByRole('heading', { name: 'My Deliveries' })).toBeVisible();
    expect(screen.getByText(/Online/)).toBeVisible();
    expect(screen.getAllByRole('heading', { name: 'Current delivery' })).toHaveLength(1);
    expect(screen.getByText('Single delivery #9')).toBeVisible();
  });

  it('keeps Current delivery before new assignment offers', () => {
    mocks.props.deliveryData.current = workItem('single', 'in_transit', [
      leg(9, null, 'in_transit'),
    ]);
    mocks.props.deliveryData.offers = [
      workItem('batch', 'offered', [leg(1, 1)], { group: 'offer' }),
    ];

    render(<MyDeliveries />);

    const page = screen.getByTestId('erp-layout').textContent ?? '';
    expect(page.indexOf('Current delivery')).toBeLessThan(page.indexOf('New assignment'));
  });

  it('counts only delivered stops and expands batch sequence on demand', () => {
    mocks.props.deliveryData.current = workItem('batch', 'in_progress', [
      leg(1, 1, 'delivered', 'Completed customer'),
      leg(2, 2, 'awaiting_proof_approval', 'Waiting customer'),
      leg(3, 3, 'assigned', 'Current customer'),
    ]);

    render(<MyDeliveries />);

    expect(screen.getByText('1 of 3 completed')).toBeVisible();
    expect(screen.getByRole('progressbar', { name: 'Batch progress: 1 of 3 delivered' })).toBeVisible();
    expect(screen.getByText('Current customer')).toBeVisible();
    expect(screen.queryByText('Waiting customer')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'View all 3 deliveries' }));

    const sequence = screen.getByTestId('delivery-sequence');
    expect(sequence).toHaveTextContent('Completed customer');
    expect(sequence).toHaveTextContent('Waiting customer');
    expect(screen.getByRole('button', { name: 'Hide delivery sequence' })).toBeVisible();
  });

  it('shows one compact Up Next item', () => {
    mocks.props.deliveryData.up_next = workItem('batch', 'accepted', [leg(4, 1), leg(5, 2)], {
      key: 'batch:12',
      id: 12,
      group: 'upcoming',
      business_label: 'Repair pickup',
      business_types: ['repair'],
    });

    render(<MyDeliveries />);

    const region = screen.getByRole('region', { name: 'Up next' });
    expect(within(region).getByText('Batch #12')).toBeVisible();
    expect(region).toHaveTextContent('2 deliveries');
    expect(within(region).getByRole('button', { name: 'View details' })).toBeVisible();
  });

  it('shows and submits the return-to-shop handoff after the final failed attempt', async () => {
    const returnLeg = {
      ...leg(4, null, 'in_transit', 'SoleSpace shop'),
      leg_type: 'return_to_shop',
      assignments: [{ id: 13, status: 'accepted' }],
      shipment: {
        id: 103,
        source_type: 'repair_request',
        source_id: 203,
        purpose: 'repair_pickup',
      },
    };
    mocks.props.deliveryData.current = workItem('single', 'in_transit', [returnLeg], {
      business_label: 'Repair pickup',
      business_types: ['repair'],
    });

    render(<MyDeliveries />);

    expect(screen.getByText('Return to shop')).toBeVisible();
    expect(screen.queryByLabelText('Delivery proof')).not.toBeInTheDocument();
    const photo = new File(['return'], 'return.jpg', { type: 'image/jpeg' });
    fireEvent.change(screen.getByLabelText('Return handoff photo'), {
      target: { files: [photo] },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Confirm return handoff' }));

    await waitFor(() => expect(mocks.confirmReturnHandoff).toHaveBeenCalledWith(4, 17));
    expect(mocks.recordProof).toHaveBeenCalledWith(4, expect.any(FormData));
    expect((mocks.recordProof.mock.calls[0][1] as FormData).get('handoff_type')).toBe('receive');
  });

  it('shows semantic lower-list tabs and business filter', () => {
    render(<MyDeliveries />);

    for (const label of ['Upcoming', 'History', 'Issues', 'All']) {
      expect(screen.getByRole('button', { name: label })).toBeVisible();
    }
    const upcoming = screen.getByRole('button', { name: 'Upcoming' });
    expect(upcoming).toHaveClass('bg-slate-100', 'text-slate-950');
    expect(upcoming).not.toHaveClass('bg-slate-900');
    expect(upcoming.className).not.toContain('ring-blue');
    expect(screen.getByLabelText('Business type')).toHaveValue('all');
  });

  it('keeps mobile and tablet delivery content centered with stacked filters', () => {
    render(<MyDeliveries />);

    const page = screen.getByRole('heading', { name: 'My Deliveries' }).parentElement?.parentElement;
    const tabs = screen.getByRole('button', { name: 'Upcoming' }).parentElement;
    const filters = screen.getByLabelText('Business type').parentElement?.parentElement;

    expect(page).toHaveClass('mx-auto', 'w-full', 'max-w-xl', 'md:max-w-3xl', 'xl:max-w-3xl', 'space-y-8', 'xl:space-y-6');
    expect(page).not.toHaveClass('lg:max-w-3xl');
    expect(screen.getByRole('heading', { name: 'My Deliveries' }).parentElement).toHaveClass('text-center', 'xl:text-left');
    expect(tabs).toHaveClass('gap-2');
    expect(tabs).toHaveClass('justify-center', 'xl:justify-start');
    expect(filters).toHaveClass('xl:grid-cols-3');
    expect(filters).not.toHaveClass('lg:grid-cols-3');
    expect(filters).not.toHaveClass('sm:grid-cols-3');
    expect(screen.getByLabelText('Business type')).toHaveClass('min-h-12', 'text-base', 'xl:min-h-11', 'xl:text-sm');
  });

  it('keeps the compact arrival picker open until an explicit picker action', async () => {
    mocks.arrive.mockRejectedValueOnce({
      response: {
        status: 422,
        data: {
          errors: {
            exception_reason: ['Reason required.'],
            arrival_result: ['outside_geofence'],
          },
        },
      },
    });
    mocks.props.deliveryData.current = workItem('single', 'in_transit', [leg(10, null, 'in_transit')]);
    render(<MyDeliveries />);

    fireEvent.click(screen.getByRole('button', { name: "I've arrived" }));
    await waitFor(() => expect(screen.getByLabelText('Arrival reason')).toBeVisible());
    expect(screen.getByText(/Outside geofence/)).toBeVisible();

    fireEvent.click(screen.getByLabelText('Arrival reason'));

    const picker = screen.getByRole('dialog', { name: 'Arrival reason' });
    expect(picker).toBeVisible();
    expect(picker.parentElement).toHaveClass('z-[100001]');
    expect(screen.queryByRole('combobox', { name: 'Arrival reason' })).not.toBeInTheDocument();

    fireEvent.pointerDown(picker.parentElement as HTMLElement);
    expect(screen.getByRole('dialog', { name: 'Arrival reason' })).toBeVisible();

    fireEvent.click(within(picker).getByRole('option', { name: 'GPS location is inaccurate' }));
    expect(screen.getByLabelText('Arrival reason')).toHaveValue('gps_inaccurate');
    expect(screen.queryByRole('dialog', { name: 'Arrival reason' })).not.toBeInTheDocument();
  });

  it('opens the incident form in a modal while keeping its picker native on desktop viewports', () => {
    const previousWidth = window.innerWidth;
    Object.defineProperty(window, 'innerWidth', { configurable: true, value: 1440 });
    mocks.props.deliveryData.current = workItem('single', 'in_transit', [leg(10, null, 'in_transit')]);

    try {
      render(<MyDeliveries />);
      fireEvent.click(screen.getByRole('button', { name: 'Report incident' }));

      const incidentDialog = screen.getByRole('dialog', { name: 'Report incident' });
      expect(incidentDialog).toBeVisible();
      expect(incidentDialog.parentElement).toHaveClass('z-[100000]');
      const incidentType = screen.getByLabelText('Incident type');
      fireEvent.pointerDown(incidentType);
      expect(screen.queryByRole('dialog', { name: 'Incident type' })).not.toBeInTheDocument();
      fireEvent.change(incidentType, { target: { value: 'vehicle_problem' } });
      expect(incidentType).toHaveValue('vehicle_problem');
    } finally {
      Object.defineProperty(window, 'innerWidth', { configurable: true, value: previousWidth });
    }
  });

  it('distinguishes batch and standalone work without bulk controls', () => {
    mocks.props.deliveryData.list.data = [
      workItem('batch', 'completed', [leg(1, 1)], { group: 'history' }),
      workItem('single', 'delivered', [leg(8, null, 'delivered')], {
        key: 'single:8',
        id: 8,
        group: 'history',
      }),
    ];
    mocks.props.deliveryData.list.total = 2;

    render(<MyDeliveries />);

    expect(screen.getByText('Batch #7')).toBeVisible();
    expect(screen.getByText('Single delivery #8')).toBeVisible();
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    expect(screen.queryByText(/Mark Picked Up \(/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Leg #/)).not.toBeInTheDocument();
  });

  it('shows readable empty and active-conflict states', () => {
    const { rerender } = render(<MyDeliveries />);

    expect(screen.getByText('No delivery in progress')).toBeVisible();
    expect(screen.getByText('No upcoming deliveries')).toBeVisible();

    mocks.props.deliveryData.has_active_conflict = true;
    mocks.props.deliveryData.active_conflicts = [
      workItem('batch', 'in_progress', [leg(1, 1)], { group: 'conflict' }),
    ];
    rerender(<MyDeliveries />);

    expect(screen.getByRole('alert')).toHaveTextContent(/More than one active delivery/);
  });
});

describe('MyDeliveries rider interactions', () => {
  it('clears the selected proof when a batch advances to its next delivery', async () => {
    const uuid = '2a24f0bd-c8e6-4b34-b36c-f40c6ca14252';
    vi.spyOn(crypto, 'randomUUID').mockReturnValue(uuid);
    const first = { ...leg(5, 1, 'in_transit'), arrivals: arrived('dropoff') };
    const second = { ...leg(6, 2, 'in_transit'), arrivals: arrived('dropoff') };
    mocks.props.deliveryData.current = workItem('batch', 'in_progress', [first, second]);
    const view = render(<MyDeliveries />);
    const proof = new File(['first proof'], 'first-proof.jpg', { type: 'image/jpeg' });

    fireEvent.change(screen.getByLabelText('Delivery proof'), { target: { files: [proof] } });
    fireEvent.click(screen.getByRole('button', { name: 'Submit delivery proof' }));
    await waitFor(() => expect(mocks.post).toHaveBeenCalledWith(
      '/api/logistics/legs/5/proof',
      expect.any(FormData),
      expect.any(Object),
    ));
    expect((mocks.post.mock.calls[0][1] as FormData).get('idempotency_key')).toBe(uuid);

    mocks.post.mockClear();
    mocks.props.deliveryData.current = workItem('batch', 'in_progress', [
      { ...first, status: 'awaiting_proof_approval' },
      second,
    ]);
    view.rerender(<MyDeliveries />);

    expect(screen.getByRole('button', { name: 'Submit delivery proof' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Submit delivery proof' }));
    expect(mocks.post).not.toHaveBeenCalled();
  });

  it('confirms once and sends one request during repeated pickup taps', async () => {
    let confirmPickup: (result: { isConfirmed: boolean }) => void = () => undefined;
    mocks.confirm.mockImplementationOnce(() => new Promise((resolve) => {
      confirmPickup = resolve;
    }));
    mocks.props.deliveryData.up_next = workItem('single', 'assigned', [{
      ...leg(9, null),
      arrivals: arrived('pickup'),
    }], {
      group: 'upcoming',
    });
    render(<MyDeliveries />);

    const button = screen.getByRole('button', { name: 'Confirm pickup' });
    fireEvent.click(button);
    fireEvent.click(button);

    expect(mocks.confirm).toHaveBeenCalledTimes(1);
    expect(mocks.markPickedUp).not.toHaveBeenCalled();

    confirmPickup({ isConfirmed: true });

    await waitFor(() => expect(mocks.markPickedUp).toHaveBeenCalledTimes(1));
  });

  it('opens a batch decline modal with common reasons and an editable reason', async () => {
    mocks.props.deliveryData.offers = [
      workItem('batch', 'offered', [leg(1, 1)], { group: 'offer' }),
    ];

    render(<MyDeliveries />);

    const offerCard = screen.getByText('New assignment').closest('article');
    expect(offerCard).toHaveClass('bg-white', 'border-slate-950');
    expect(offerCard).not.toHaveClass('bg-amber-50', 'border-amber-300');

    fireEvent.click(screen.getByRole('button', { name: 'Decline batch' }));
    const dialog = screen.getByRole('dialog', { name: 'Decline batch' });
    expect(within(dialog).getByText('Common reasons')).toBeVisible();

    fireEvent.click(within(dialog).getByRole('button', { name: 'Schedule conflict' }));
    const reason = within(dialog).getByLabelText('Decline reason');
    expect(reason).toHaveValue('Schedule conflict');

    fireEvent.change(reason, { target: { value: 'Schedule conflict - route changed' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Confirm decline' }));

    await waitFor(() => expect(mocks.rejectBatch).toHaveBeenCalledWith(
      7,
      'Schedule conflict - route changed',
    ));
  });

  it('accepts or declines a standalone delivery offer through its leg endpoints', async () => {
    mocks.props.deliveryData.offers = [
      workItem('single', 'offered', [leg(9, null)], { group: 'offer' }),
    ];

    render(<MyDeliveries />);

    fireEvent.click(screen.getByRole('button', { name: 'Accept delivery' }));
    await waitFor(() => expect(mocks.acceptLeg).toHaveBeenCalledWith(9));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Accept delivery' })).toBeEnabled());

    fireEvent.click(screen.getByRole('button', { name: 'Decline delivery' }));
    const dialog = screen.getByRole('dialog', { name: 'Decline delivery' });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Safety concern' }));
    const reason = within(dialog).getByLabelText('Decline reason');
    fireEvent.change(reason, { target: { value: 'Safety concern near the pickup route' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Confirm decline' }));

    await waitFor(() => expect(mocks.rejectLeg).toHaveBeenCalledWith(
      9,
      'Safety concern near the pickup route',
    ));
  });

  it('starts an accepted batch from Up Next', async () => {
    mocks.props.deliveryData.up_next = workItem('batch', 'accepted', [leg(1, 1)], {
      group: 'upcoming',
    });

    render(<MyDeliveries />);
    fireEvent.click(screen.getByRole('button', { name: 'Start batch' }));

    await waitFor(() => expect(mocks.startBatch).toHaveBeenCalledWith(7));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Start batch' })).toBeEnabled());
  });

  it('does not start Up Next while another delivery is current', () => {
    mocks.props.deliveryData.current = workItem('single', 'in_transit', [
      leg(9, null, 'in_transit'),
    ]);
    mocks.props.deliveryData.up_next = workItem('batch', 'accepted', [leg(1, 1)], {
      group: 'upcoming',
    });

    render(<MyDeliveries />);

    expect(screen.getByRole('button', { name: 'Start batch' })).toBeDisabled();
  });

  it('confirms standalone pickup with proof when present and without proof when absent', async () => {
    const withProof = {
      ...leg(9, null, 'assigned'),
      arrivals: arrived('pickup'),
      proofs: [{ id: 44, handoff_type: 'pickup', proof_type: 'photo' }],
    };
    mocks.props.deliveryData.up_next = workItem('single', 'assigned', [withProof], {
      group: 'upcoming',
    });
    const view = render(<MyDeliveries />);

    fireEvent.click(screen.getByRole('button', { name: 'Confirm pickup' }));
    await waitFor(() => expect(mocks.confirmPickup).toHaveBeenCalledWith(9, 44));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Confirm pickup' })).toBeEnabled());

    mocks.props.deliveryData.up_next = workItem('single', 'assigned', [{
      ...leg(10, null, 'assigned'),
      arrivals: arrived('pickup'),
    }], {
      key: 'single:10',
      id: 10,
      group: 'upcoming',
    });
    view.rerender(<MyDeliveries />);
    fireEvent.click(screen.getByRole('button', { name: 'Confirm pickup' }));

    await waitFor(() => expect(mocks.markPickedUp).toHaveBeenCalledWith(10));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Confirm pickup' })).toBeEnabled());
  });

  it('shows Failed pickup only after arrival for a repair pickup', () => {
    const repairPickup = {
      ...leg(20, null, 'assigned'),
      shipment: {
        id: 120,
        source_type: 'repair_request',
        source_id: 220,
        purpose: 'repair_pickup',
      },
      assignments: [{ id: 220, status: 'accepted' }],
    };
    mocks.props.deliveryData.up_next = workItem('single', 'assigned', [{
      ...repairPickup,
      arrivals: arrived('pickup'),
    }], { group: 'upcoming', business_types: ['repair'], business_label: 'Repair pickup' });
    const view = render(<MyDeliveries />);

    expect(screen.getByRole('button', { name: 'Confirm pickup' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Failed pickup' })).toBeEnabled();

    mocks.props.deliveryData.up_next = workItem('single', 'assigned', [repairPickup], {
      group: 'upcoming',
      business_types: ['repair'],
      business_label: 'Repair pickup',
    });
    view.rerender(<MyDeliveries />);
    expect(screen.queryByRole('button', { name: 'Failed pickup' })).not.toBeInTheDocument();

    mocks.props.deliveryData.up_next = workItem('single', 'assigned', [{
      ...leg(21, null, 'assigned'),
      arrivals: arrived('pickup'),
      assignments: [{ id: 221, status: 'accepted' }],
    }], { group: 'upcoming' });
    view.rerender(<MyDeliveries />);
    expect(screen.queryByRole('button', { name: 'Failed pickup' })).not.toBeInTheDocument();
  });

  it('requires a photo for every failed pickup reason and notes only for Other', () => {
    mocks.props.deliveryData.up_next = workItem('single', 'assigned', [{
      ...leg(20, null, 'assigned'),
      shipment: {
        id: 120,
        source_type: 'repair_request',
        source_id: 220,
        purpose: 'repair_pickup',
      },
      arrivals: arrived('pickup'),
      assignments: [{ id: 220, status: 'accepted' }],
    }], { group: 'upcoming', business_types: ['repair'], business_label: 'Repair pickup' });
    render(<MyDeliveries />);
    fireEvent.click(screen.getByRole('button', { name: 'Failed pickup' }));

    const reason = screen.getByLabelText('Failed pickup reason');
    fireEvent.click(reason);
    const reasonPicker = screen.getByRole('dialog', { name: 'Failed pickup reason' });
    for (const option of [
      'Customer unavailable / not home',
      'Customer requested reschedule',
      'Customer refused pickup',
      'Item not ready or unavailable',
      'Wrong address or map pin',
      'Unsafe or inaccessible location',
      'Vehicle or rider problem',
      'Other',
    ]) {
      expect(within(reasonPicker).getByRole('option', { name: option })).toBeInTheDocument();
    }

    fireEvent.click(within(reasonPicker).getByRole('option', { name: 'Customer unavailable / not home' }));

    const photo = screen.getByLabelText('Failed pickup photo');
    expect(photo).toHaveAttribute('accept', 'image/*');
    expect(photo).toHaveAttribute('capture', 'environment');
    expect(screen.getByRole('button', { name: 'Submit failed pickup' })).toBeDisabled();
    fireEvent.change(photo, {
      target: { files: [new File(['door'], 'door.jpg', { type: 'image/jpeg' })] },
    });
    expect(screen.getByRole('button', { name: 'Submit failed pickup' })).toBeEnabled();

    choosePickerOption('Failed pickup reason', 'Other');
    expect(screen.getByRole('button', { name: 'Submit failed pickup' })).toBeDisabled();
    fireEvent.change(screen.getByLabelText('Failed pickup notes'), {
      target: { value: 'Customer asked the rider to return tomorrow.' },
    });
    expect(screen.getByRole('button', { name: 'Submit failed pickup' })).toBeEnabled();
  });

  it('submits one stable failed pickup payload and reloads the next batch stop', async () => {
    const uuid = '18ca84c2-c693-4b05-a946-e51be590371e';
    vi.spyOn(crypto, 'randomUUID').mockReturnValue(uuid);
    let finishReport: () => void = () => undefined;
    mocks.reportIssue.mockImplementationOnce(() => new Promise<void>((resolve) => {
      finishReport = resolve;
    }));
    const failedStop = {
      ...leg(20, 1, 'assigned', 'First pickup'),
      shipment: {
        id: 120,
        source_type: 'repair_request',
        source_id: 220,
        purpose: 'repair_pickup',
      },
      arrivals: arrived('pickup'),
      assignments: [{ id: 220, status: 'accepted' }],
    };
    const nextStop = {
      ...leg(21, 2, 'assigned', 'Next pickup'),
      shipment: {
        id: 121,
        source_type: 'repair_request',
        source_id: 221,
        purpose: 'repair_pickup',
      },
    };
    mocks.props.deliveryData.current = workItem('batch', 'in_progress', [failedStop, nextStop], {
      business_types: ['repair'],
      business_label: 'Repair pickup',
    });
    const view = render(<MyDeliveries />);
    fireEvent.click(screen.getByRole('button', { name: 'Failed pickup' }));
    choosePickerOption('Failed pickup reason', 'Customer unavailable / not home');
    const photo = new File(['door'], 'door.jpg', { type: 'image/jpeg' });
    fireEvent.change(screen.getByLabelText('Failed pickup photo'), { target: { files: [photo] } });
    const submit = screen.getByRole('button', { name: 'Submit failed pickup' });
    fireEvent.click(submit);
    fireEvent.click(submit);

    await waitFor(() => expect(mocks.reportIssue).toHaveBeenCalledOnce());
    const [legId, form] = mocks.reportIssue.mock.calls[0];
    expect(legId).toBe(20);
    expect(form.get('attempt_type')).toBe('pickup');
    expect(form.get('reason_code')).toBe('customer_unavailable');
    expect(form.get('proof_file')).toBe(photo);
    expect(form.get('idempotency_key')).toBe(uuid);

    mocks.props.deliveryData.current = workItem('batch', 'in_progress', [nextStop], {
      business_types: ['repair'],
      business_label: 'Repair pickup',
    });
    finishReport();
    await waitFor(() => expect(mocks.reload).toHaveBeenCalled());
    view.rerender(<MyDeliveries />);
    expect(screen.getByText('Next pickup')).toBeVisible();
  });

  it('uses a new failed pickup key after the delivery is reassigned', async () => {
    const firstUuid = '18ca84c2-c693-4b05-a946-e51be590371e';
    const secondUuid = '75a7e9f2-ef48-47b5-9490-c9d33fb4ce7e';
    vi.spyOn(crypto, 'randomUUID')
      .mockReturnValueOnce(firstUuid)
      .mockReturnValueOnce(secondUuid);
    const pickup = {
      ...leg(20, null, 'assigned', 'Retry pickup'),
      shipment: {
        id: 120,
        source_type: 'repair_request',
        source_id: 220,
        purpose: 'repair_pickup',
      },
      arrivals: arrived('pickup'),
      assignments: [{ id: 220, status: 'accepted' }],
    };
    mocks.props.deliveryData.up_next = workItem('single', 'assigned', [pickup], {
      group: 'upcoming',
      business_types: ['repair'],
      business_label: 'Repair pickup',
    });
    const view = render(<MyDeliveries />);
    fireEvent.click(screen.getByRole('button', { name: 'Failed pickup' }));
    choosePickerOption('Failed pickup reason', 'Customer unavailable / not home');
    fireEvent.change(screen.getByLabelText('Failed pickup photo'), {
      target: { files: [new File(['door'], 'door.jpg', { type: 'image/jpeg' })] },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Submit failed pickup' }));
    await waitFor(() => expect(mocks.reportIssue).toHaveBeenCalledTimes(1));

    pickup.assignments = [{ id: 221, status: 'accepted' }];
    view.rerender(<MyDeliveries />);
    fireEvent.click(screen.getByRole('button', { name: 'Submit failed pickup' }));
    await waitFor(() => expect(mocks.reportIssue).toHaveBeenCalledTimes(2));

    expect((mocks.reportIssue.mock.calls[0][1] as FormData).get('idempotency_key')).toBe(firstUuid);
    expect((mocks.reportIssue.mock.calls[1][1] as FormData).get('idempotency_key')).toBe(secondUuid);
  });

  it('keeps failed pickup details visible and disables submission while offline', () => {
    mocks.props.deliveryData.up_next = workItem('single', 'assigned', [{
      ...leg(20, null, 'assigned'),
      shipment: {
        id: 120,
        source_type: 'repair_request',
        source_id: 220,
        purpose: 'repair_pickup',
      },
      arrivals: arrived('pickup'),
      assignments: [{ id: 220, status: 'accepted' }],
    }], { group: 'upcoming', business_types: ['repair'], business_label: 'Repair pickup' });
    render(<MyDeliveries />);
    fireEvent.click(screen.getByRole('button', { name: 'Failed pickup' }));
    choosePickerOption('Failed pickup reason', 'Customer unavailable / not home');
    fireEvent.change(screen.getByLabelText('Failed pickup photo'), {
      target: { files: [new File(['door'], 'door.jpg', { type: 'image/jpeg' })] },
    });

    fireEvent(window, new Event('offline'));

    expect(screen.getByLabelText('Failed pickup reason')).toHaveValue('customer_unavailable');
    expect(screen.getByRole('button', { name: 'Submit failed pickup' })).toBeDisabled();
    expect(screen.getByText('Retry after reconnect')).toBeVisible();
  });

  it('starts batch and standalone delivery with their existing endpoints', async () => {
    mocks.props.deliveryData.current = workItem('batch', 'in_progress', [leg(2, 1, 'picked_up')]);
    const view = render(<MyDeliveries />);

    fireEvent.click(screen.getByRole('button', { name: 'Start delivery' }));
    await waitFor(() => expect(mocks.outForDelivery).toHaveBeenCalledWith(2));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Start delivery' })).toBeEnabled());

    mocks.props.deliveryData.current = workItem('single', 'picked_up', [leg(3, null, 'picked_up')]);
    view.rerender(<MyDeliveries />);
    fireEvent.click(screen.getByRole('button', { name: 'Start delivery' }));

    await waitFor(() => expect(mocks.markInTransit).toHaveBeenCalledWith(3));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Start delivery' })).toBeEnabled());
  });

  it('submits delivery proof and an existing issue payload', async () => {
    const delivery = {
      ...leg(5, 1, 'in_transit'),
      arrivals: arrived('dropoff'),
      assignments: [{ id: 55, status: 'accepted' }],
    };
    mocks.props.deliveryData.current = workItem('single', 'in_transit', [delivery]);
    render(<MyDeliveries />);

    expect(screen.getByText('No photo selected')).toBeVisible();
    expect(screen.getByRole('button', { name: 'Upload photo' })).toBeVisible();
    const proof = new File(['proof'], 'proof.jpg', { type: 'image/jpeg' });
    fireEvent.change(screen.getByLabelText('Delivery proof'), { target: { files: [proof] } });
    expect(screen.getByText('proof.jpg')).toBeVisible();
    expect(screen.getByRole('button', { name: 'Remove Delivery proof' })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Submit delivery proof' }));
    await waitFor(() => expect(mocks.post).toHaveBeenCalledWith(
      '/api/logistics/legs/5/proof',
      expect.any(FormData),
      expect.objectContaining({ headers: { 'Content-Type': 'multipart/form-data' } }),
    ));
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Submit delivery proof' })).toBeEnabled(),
    );

    fireEvent.click(screen.getByRole('button', { name: 'Report issue' }));
    choosePickerOption('Issue reason', 'Recipient unavailable');
    fireEvent.change(screen.getByLabelText('Issue photo'), { target: { files: [proof] } });
    fireEvent.click(screen.getByRole('button', { name: 'Submit issue' }));

    await waitFor(() => expect(mocks.reportIssue).toHaveBeenCalledWith(5, expect.any(FormData)));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Submit issue' })).toBeEnabled());
  });

  it('uses reason-specific issue evidence and allows unsafe reporting without a photo', async () => {
    mocks.props.deliveryData.current = workItem('single', 'in_transit', [{
      ...leg(15, null, 'in_transit'),
      arrivals: arrived('dropoff'),
      assignments: [{ id: 155, status: 'accepted' }],
    }]);
    render(<MyDeliveries />);
    fireEvent.click(screen.getByRole('button', { name: 'Report issue' }));

    expect(screen.getByRole('dialog', { name: 'Report issue' })).toBeVisible();
    expect(screen.getByLabelText('Delivery issue details')).toHaveClass('bg-white', 'border-slate-200');
    expect(screen.getByRole('button', { name: 'Report issue' })).toHaveClass('bg-white', 'text-slate-950');
    expect(screen.getByRole('button', { name: 'Report incident' })).toHaveClass('bg-white', 'text-slate-950');
    expect(screen.getByRole('article')).toHaveClass('border', 'border-slate-200');
    expect(screen.getByRole('article')).not.toHaveClass('border-2', 'border-blue-400');
    expect(screen.getByRole('link', { name: 'Directions' })).toHaveClass('border-slate-300', 'text-slate-950');
    expect(screen.getByRole('link', { name: 'Directions' })).not.toHaveClass('border-blue-300', 'text-blue-700');

    const reason = screen.getByLabelText('Issue reason');
    fireEvent.click(reason);
    const reasonPicker = screen.getByRole('dialog', { name: 'Issue reason' });
    for (const option of [
      'Recipient unavailable',
      'Wrong or incomplete address',
      'Recipient refused',
      'Item damaged',
      'Unsafe location',
      'Vehicle or delivery problem',
      'Other',
    ]) {
      expect(within(reasonPicker).getByRole('option', { name: option })).toBeInTheDocument();
    }

    const photo = screen.getByLabelText('Issue photo');
    const notes = screen.getByLabelText('Issue notes');
    const issueDetails = screen.getByLabelText('Delivery issue details');
    expect(within(issueDetails).getByText('No photo selected')).toBeVisible();
    expect(within(issueDetails).getByRole('button', { name: 'Upload photo' })).toBeVisible();
    fireEvent.click(within(reasonPicker).getByRole('option', { name: 'Recipient unavailable' }));
    expect(reason).toHaveValue('recipient_unavailable');
    expect(photo).toBeRequired();
    expect(notes).not.toBeRequired();

    choosePickerOption('Issue reason', 'Unsafe location');
    expect(photo).not.toBeRequired();
    expect(notes).toBeRequired();
    const issuePhoto = new File(['issue'], 'issue.jpg', { type: 'image/jpeg' });
    fireEvent.change(photo, { target: { files: [issuePhoto] } });
    expect(within(issueDetails).getByText('issue.jpg')).toBeVisible();
    expect(within(issueDetails).getByRole('button', { name: 'Remove Issue photo' })).toBeVisible();
    fireEvent.click(within(issueDetails).getByRole('button', { name: 'Remove Issue photo' }));
    expect(within(issueDetails).getByText('No photo selected')).toBeVisible();
    expect(within(issueDetails).queryByRole('button', { name: 'Remove Issue photo' })).not.toBeInTheDocument();
    fireEvent.change(notes, { target: { value: 'Unsafe road conditions.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Submit issue' }));

    await waitFor(() => expect(mocks.reportIssue).toHaveBeenCalled());
    const body = mocks.reportIssue.mock.calls[0][1] as FormData;
    expect(body.get('reason_code')).toBe('unsafe_location');
    expect(body.get('notes')).toBe('Unsafe road conditions.');
    expect(body.has('proof_file')).toBe(false);
  });

  it('shows dispatcher resolution instructions in standalone and batch delivery contexts', () => {
    mocks.props.deliveryData.current = workItem('single', 'assigned', [{
      ...leg(16, null, 'assigned'),
      resolution_type: 'retry',
      resolution_reason: 'Customer requested tomorrow morning.',
    }]);
    const view = render(<MyDeliveries />);
    expect(screen.getByText(
      'Dispatcher scheduled another attempt: Customer requested tomorrow morning.',
    )).toBeInTheDocument();

    mocks.props.deliveryData.current = workItem('batch', 'in_progress', [{
      ...leg(17, 1, 'assigned'),
      resolution_type: 'return_required',
      resolution_reason: 'Customer cancelled.',
    }]);
    view.rerender(<MyDeliveries />);
    expect(screen.getByText('Return item to shop: Customer cancelled.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'View all 1 deliveries' }));
    expect(screen.getAllByText('Return item to shop: Customer cancelled.')).toHaveLength(2);
  });

  it('shows a staged retry, blocks it before the scheduled date, and starts it when due', async () => {
    const retry = {
      ...leg(18, null, 'needs_resolution', 'Retry customer'),
      resolution_type: 'retry',
      resolution_reason: 'Customer requested another attempt.',
      scheduled_delivery_date: '2026-07-30',
      assignments: [{ id: 180, status: 'accepted' }],
    };
    mocks.props.deliveryData.current = workItem('single', 'needs_resolution', [retry]);

    const view = render(<MyDeliveries />);

    expect(screen.getByText('Retry scheduled for Jul 30, 2026')).toBeVisible();
    expect(screen.getByRole('button', { name: 'Start retry' })).toBeDisabled();

    mocks.props.today = '2026-07-30';
    view.rerender(<MyDeliveries />);
    expect(screen.getByRole('button', { name: 'Start retry' })).toBeEnabled();
    fireEvent.click(screen.getByRole('button', { name: 'Start retry' }));

    await waitFor(() => expect(mocks.markInTransit).toHaveBeenCalledWith(18));
  });

  it('shows no state-changing action while waiting for proof approval', () => {
    mocks.props.deliveryData.current = workItem('single', 'awaiting_proof_approval', [
      leg(6, null, 'awaiting_proof_approval'),
    ]);

    render(<MyDeliveries />);

    expect(screen.getAllByText('Waiting for proof approval')).not.toHaveLength(0);
    expect(screen.queryByRole('button', { name: 'Confirm pickup' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Start delivery' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Submit delivery proof' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Report issue' })).not.toBeInTheDocument();
  });

  it('keeps the canonical delivery actionable and explains that conflicting work is blocked', () => {
    mocks.props.deliveryData.current = workItem('single', 'picked_up', [leg(7, null, 'picked_up')]);
    mocks.props.deliveryData.has_active_conflict = true;
    mocks.props.deliveryData.active_conflicts = [
      workItem('batch', 'in_progress', [leg(8, 1, 'in_transit')], { group: 'conflict' }),
    ];

    render(<MyDeliveries />);

    expect(screen.getByRole('button', { name: 'Start delivery' })).toBeEnabled();
    expect(screen.getByRole('alert')).toHaveTextContent(
      'Continue only the Current delivery shown below.',
    );
    expect(screen.getByRole('link', { name: 'Call' })).toBeVisible();
    expect(screen.getByRole('link', { name: 'Directions' })).toBeVisible();
  });

  it('lets the rider refresh delivery data without reloading unrelated page props', () => {
    render(<MyDeliveries />);

    fireEvent.click(screen.getByRole('button', { name: 'Refresh deliveries' }));

    expect(mocks.reload).toHaveBeenCalledWith(expect.objectContaining({
      only: ['deliveryData'],
      onFinish: expect.any(Function),
    }));
  });

  it('refreshes delivery data and explains recovery after a stale action response', async () => {
    mocks.markInTransit.mockRejectedValueOnce({
      response: {
        status: 409,
        data: { message: 'This delivery changed while you were working.' },
      },
    });
    mocks.props.deliveryData.current = workItem('single', 'picked_up', [
      leg(7, null, 'picked_up'),
    ]);
    render(<MyDeliveries />);

    fireEvent.click(screen.getByRole('button', { name: 'Start delivery' }));

    await waitFor(() => expect(mocks.reload).toHaveBeenCalledWith(expect.objectContaining({
      only: ['deliveryData'],
      onFinish: expect.any(Function),
    })));
    expect(screen.getByRole('alert')).toHaveTextContent(
      'This delivery changed while you were working. Delivery list refreshed.',
    );
  });

  it('updates lower-list filters, clears them, and preserves filter state for pagination', () => {
    mocks.props.deliveryData.filters = {
      tab: 'history',
      business: 'retail',
      window: 'week',
      search: 'Miguel',
    };
    mocks.props.deliveryData.list.links = [
      { url: '/erp/logistics/deliveries?page=2', label: '2', active: false },
    ];

    render(<MyDeliveries />);

    fireEvent.click(screen.getByRole('button', { name: 'Issues' }));
    expect(mocks.get).toHaveBeenCalledWith(
      '/erp/logistics/deliveries',
      expect.objectContaining({ tab: 'issues', page: 1 }),
      expect.objectContaining({ preserveScroll: true, preserveState: true }),
    );

    fireEvent.change(screen.getByLabelText('Business type'), { target: { value: 'repair' } });
    expect(mocks.get).toHaveBeenCalledWith(
      '/erp/logistics/deliveries',
      expect.objectContaining({ business: 'repair', page: 1 }),
      expect.objectContaining({ preserveScroll: true, preserveState: true }),
    );

    fireEvent.change(screen.getByLabelText('Search deliveries'), { target: { value: 'Batch 12' } });
    fireEvent.submit(screen.getByRole('search'));
    expect(mocks.get).toHaveBeenCalledWith(
      '/erp/logistics/deliveries',
      expect.objectContaining({ search: 'Batch 12', page: 1 }),
      expect.any(Object),
    );

    fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
    expect(mocks.get).toHaveBeenCalledWith(
      '/erp/logistics/deliveries',
      expect.objectContaining({
        tab: 'upcoming',
        business: 'all',
        window: 'all',
        search: '',
        page: 1,
      }),
      expect.any(Object),
    );

    fireEvent.click(screen.getByRole('button', { name: 'Page 2' }));
    expect(mocks.get).toHaveBeenCalledWith(
      '/erp/logistics/deliveries',
      expect.objectContaining({ ...mocks.props.deliveryData.filters, page: 2 }),
      expect.any(Object),
    );
  });

  it('keeps loaded data visible and disables mutations while offline', () => {
    mocks.props.deliveryData.current = workItem('single', 'picked_up', [leg(11, null, 'picked_up')]);
    render(<MyDeliveries />);

    fireEvent(window, new Event('offline'));

    expect(screen.getByText(/Offline/)).toBeVisible();
    expect(screen.getByText('Customer 11')).toBeVisible();
    expect(screen.getByRole('button', { name: 'Start delivery' })).toBeDisabled();
  });

  it("records a fresh high-accuracy pickup arrival without confirmation", async () => {
    mocks.props.deliveryData.up_next = workItem('single', 'assigned', [leg(9, null)], {
      group: 'upcoming',
    });
    render(<MyDeliveries />);

    fireEvent.click(screen.getByRole('button', { name: "I've arrived" }));
    fireEvent.click(screen.getByRole('button', { name: "I've arrived" }));

    await waitFor(() => expect(mocks.arrive).toHaveBeenCalledTimes(1));
    expect(mocks.confirm).not.toHaveBeenCalled();
    expect(mocks.getCurrentPosition).toHaveBeenCalledWith(
      expect.any(Function),
      expect.any(Function),
      { enableHighAccuracy: true, timeout: 10_000, maximumAge: 0 },
    );
    expect(mocks.arrive).toHaveBeenCalledWith(9, {
      arrival_type: 'pickup',
      latitude: 14.3,
      longitude: 120.95,
      accuracy_m: 15,
      captured_at: '2026-07-29T02:30:00.000Z',
    });
  });

  it('gates pickup confirmation and delivery proof behind the matching arrival', () => {
    mocks.props.deliveryData.up_next = workItem('single', 'assigned', [leg(9, null)], {
      group: 'upcoming',
    });
    mocks.props.deliveryData.current = workItem('single', 'in_transit', [
      leg(10, null, 'in_transit'),
    ]);
    const view = render(<MyDeliveries />);

    expect(screen.getAllByRole('button', { name: "I've arrived" })).toHaveLength(2);
    expect(screen.queryByRole('button', { name: 'Confirm pickup' })).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Delivery proof')).not.toBeInTheDocument();

    mocks.props.deliveryData.current = workItem('single', 'in_transit', [{
      ...leg(10, null, 'in_transit'),
      arrivals: arrived('dropoff'),
    }]);
    mocks.props.deliveryData.up_next = workItem('single', 'assigned', [{
      ...leg(9, null),
      arrivals: arrived('pickup'),
    }], { group: 'upcoming' });
    view.rerender(<MyDeliveries />);

    expect(screen.getByRole('button', { name: 'Confirm pickup' })).toBeVisible();
    expect(screen.getByLabelText('Delivery proof')).toBeVisible();
    expect(screen.getAllByText(/Verified arrival/)).not.toHaveLength(0);
  });

  it('asks for a rider reason after a geofence exception and requires notes for Other', async () => {
    mocks.arrive.mockRejectedValueOnce({
      response: { status: 422, data: { errors: { exception_reason: ['Reason required.'] } } },
    });
    mocks.props.deliveryData.current = workItem('single', 'in_transit', [
      leg(10, null, 'in_transit'),
    ]);
    render(<MyDeliveries />);

    fireEvent.click(screen.getByRole('button', { name: "I've arrived" }));
    await waitFor(() => expect(screen.getByLabelText('Arrival reason')).toBeVisible());

    choosePickerOption('Arrival reason', 'Other');
    expect(screen.getByRole('button', { name: 'Continue with reason' })).toBeDisabled();
    fireEvent.change(screen.getByLabelText('Arrival notes'), { target: { value: 'Customer met at gate' } });
    fireEvent.click(screen.getByRole('button', { name: 'Continue with reason' }));

    await waitFor(() => expect(mocks.arrive).toHaveBeenLastCalledWith(10, expect.objectContaining({
      arrival_type: 'dropoff',
      latitude: 14.3,
      exception_reason: 'other',
      exception_notes: 'Customer met at gate',
    })));
    expect(mocks.reload).toHaveBeenLastCalledWith(expect.objectContaining({ only: ['deliveryData'] }));
  });

  it('allows a reason after browser location fails without claiming arrival was saved', async () => {
    mocks.getCurrentPosition.mockImplementationOnce((_success, error: PositionErrorCallback) => {
      error({ code: 1, message: 'Permission denied' } as GeolocationPositionError);
    });
    mocks.props.deliveryData.up_next = workItem('single', 'assigned', [leg(12, null)], {
      group: 'upcoming',
    });
    render(<MyDeliveries />);

    fireEvent.click(screen.getByRole('button', { name: "I've arrived" }));

    await waitFor(() => expect(screen.getByLabelText('Arrival reason')).toBeVisible());
    expect(mocks.arrive).not.toHaveBeenCalled();
  });

  it('disables arrival while offline and tells the rider what to do', () => {
    mocks.props.deliveryData.current = workItem('single', 'in_transit', [
      leg(10, null, 'in_transit'),
    ]);
    render(<MyDeliveries />);
    fireEvent(window, new Event('offline'));

    expect(screen.getByRole('button', { name: "I've arrived" })).toBeDisabled();
    expect(screen.getByText('Retry after reconnect')).toBeVisible();
  });

  it('lets a rider submit a typed incident with photo evidence', async () => {
    mocks.props.deliveryData.current = workItem('single', 'in_transit', [
      leg(10, null, 'in_transit'),
    ]);
    render(<MyDeliveries />);

    fireEvent.click(screen.getByRole('button', { name: 'Report incident' }));
    const incidentType = screen.getByLabelText('Incident type');
    fireEvent.click(incidentType);
    const incidentPicker = screen.getByRole('dialog', { name: 'Incident type' });
    fireEvent.click(within(incidentPicker).getByRole('option', { name: 'Vehicle or route problem' }));
    expect(incidentType).toHaveValue('vehicle_problem');
    fireEvent.change(screen.getByLabelText('Incident notes'), { target: { value: 'Motorcycle puncture on route.' } });
    const photo = new File(['incident'], 'incident.jpg', { type: 'image/jpeg' });
    fireEvent.change(screen.getByLabelText('Incident evidence photo'), { target: { files: [photo] } });
    expect(screen.getByText('incident.jpg')).toBeVisible();
    expect(screen.getByRole('button', { name: 'Remove Incident evidence photo' })).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Submit incident' }));

    await waitFor(() => expect(mocks.reportIncident).toHaveBeenCalledOnce());
    const [legId, form] = mocks.reportIncident.mock.calls[0];
    expect(legId).toBe(10);
    expect((form as FormData).get('type')).toBe('vehicle_problem');
    expect((form as FormData).get('notes')).toBe('Motorcycle puncture on route.');
    expect((form as FormData).get('photo_files[]')).toBe(photo);
  });
});
