import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import MyDeliveries from '../MyDeliveries';

const mocks = vi.hoisted(() => ({
  props: { batches: [] as any[] },
  acceptBatch: vi.fn(() => Promise.resolve()),
  rejectBatch: vi.fn(() => Promise.resolve()),
  startBatch: vi.fn(() => Promise.resolve()),
  confirmPickup: vi.fn(() => Promise.resolve()),
  outForDelivery: vi.fn(() => Promise.resolve()),
  reload: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: mocks.props }), router: { reload: mocks.reload } }));
vi.mock('@/services/logisticsApi', () => ({ logisticsApi: mocks }));
vi.mock('../Shipments', () => ({ default: ({ children }: React.PropsWithChildren) => <main data-testid="erp-layout">{children}</main> }));

const leg = (id: number, stop_sequence: number | null, status = 'assigned', snapshot: Record<string, string> = {}) => ({
  id, stop_sequence, status, leg_type: 'outbound', destination_snapshot: snapshot, proofs: [],
});

const batch = (status = 'in_progress', legs: any[] = []) => ({
  id: 1, delivery_date: '2026-07-15', delivery_window: 'morning', status, capacity: 10, assigned_stop_count: legs.length, legs,
});

beforeEach(() => {
  mocks.props.batches = [];
  vi.clearAllMocks();
});

describe('MyDeliveries', () => {
  it('sorts stops and derives progress, completion, and the next stop', () => {
    mocks.props.batches = [batch('in_progress', [
      leg(16, null),
      leg(15, 3, 'delivered'),
      leg(14, 2),
      leg(13, 2, 'awaiting_proof_approval'),
      leg(12, 1, 'assigned', { name: 'Miguel', phone: '0905', address: 'Cavite' }),
    ])];

    render(<MyDeliveries />);

    expect(screen.getByText('2 of 5 completed')).toBeInTheDocument();
    expect(screen.getByText('Next stop')).toBeInTheDocument();
    expect(screen.getByText('Proof submitted')).toBeInTheDocument();
    expect(screen.getByText('Delivered')).toBeInTheDocument();
    expect(screen.getAllByTestId('batch-stop').map((stop) => stop.dataset.legId)).toEqual(['12', '13', '14', '15', '16']);
    expect(screen.getByText('Miguel')).toBeVisible();
  });

  it('expands one non-next stop and resets it when proof advances the route', () => {
    const first = leg(1, 1, 'assigned', { name: 'First', address: 'A' });
    const second = leg(2, 2, 'assigned', { name: 'Second', address: 'B' });
    const third = leg(3, 3, 'assigned', { name: 'Third', address: 'C' });
    mocks.props.batches = [batch('in_progress', [first, second, third])];
    const view = render(<MyDeliveries />);

    fireEvent.click(screen.getByRole('button', { name: 'Open delivery for stop 2' }));
    expect(screen.getByText('Second')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Open delivery for stop 3' }));
    expect(screen.queryByText('Second')).not.toBeInTheDocument();
    expect(screen.getByText('Third')).toBeVisible();

    first.status = 'awaiting_proof_approval';
    mocks.props.batches = [batch('in_progress', [first, second, third])];
    view.rerender(<MyDeliveries />);
    expect(screen.getByText('1 of 3 completed')).toBeInTheDocument();
    expect(screen.getByText('Second')).toBeVisible();
    expect(screen.queryByText('Third')).not.toBeInTheDocument();
  });

  it('handles empty, completed, and missing contact states', () => {
    const { rerender } = render(<MyDeliveries />);
    expect(screen.queryByText('My delivery batches')).not.toBeInTheDocument();

    mocks.props.batches = [batch('in_progress', [])];
    rerender(<MyDeliveries />);
    expect(screen.getByText('No stops in this batch')).toBeInTheDocument();
    expect(screen.getByText('0 completed')).toBeInTheDocument();

    mocks.props.batches = [batch('in_progress', [leg(4, 1, 'delivered')])];
    rerender(<MyDeliveries />);
    expect(screen.getByText('All stops completed')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Open delivery for stop 1' }));
    expect(screen.getAllByText('Not provided')).toHaveLength(3);
    expect(screen.queryByRole('link', { name: 'Call' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Directions' })).not.toBeInTheDocument();
  });

  it('preserves batch and delivery workflow actions', () => {
    const pickupLeg = { ...leg(2, 1, 'assigned'), proofs: [{ id: 8, handoff_type: 'pickup' }] };
    mocks.props.batches = [batch('offered', [pickupLeg])];
    const { rerender } = render(<MyDeliveries />);
    fireEvent.click(screen.getByRole('button', { name: 'Accept' }));
    expect(mocks.acceptBatch).toHaveBeenCalledWith(1);
    expect(screen.getByRole('button', { name: 'Reject' })).toBeDisabled();

    mocks.props.batches = [batch('accepted', [pickupLeg])];
    rerender(<MyDeliveries />);
    expect(screen.getByRole('button', { name: 'Start batch' })).toBeInTheDocument();

    mocks.props.batches = [batch('in_progress', [pickupLeg, leg(3, 2, 'picked_up')])];
    rerender(<MyDeliveries />);
    expect(screen.getByRole('button', { name: 'Confirm pickup' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Open delivery for stop 2' }));
    expect(screen.getByRole('button', { name: 'Out for delivery' })).toBeInTheDocument();
  });
});
