import React from 'react';
import { fireEvent, render, screen, within } from '@testing-library/react';
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
  },
  reload: vi.fn(),
  get: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
  Head: ({ title }: { title: string }) => <div data-testid="head-title">{title}</div>,
  usePage: () => ({ props: mocks.props }),
  router: { reload: mocks.reload, get: mocks.get },
}));
vi.mock('@/layout/AppLayout_ERP', () => ({
  default: ({ children }: React.PropsWithChildren) => <main data-testid="erp-layout">{children}</main>,
}));

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

  it('shows semantic lower-list tabs and business filter', () => {
    render(<MyDeliveries />);

    for (const label of ['Upcoming', 'History', 'Issues', 'All']) {
      expect(screen.getByRole('button', { name: label })).toBeVisible();
    }
    expect(screen.getByLabelText('Business type')).toHaveValue('all');
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
