import { describe, expect, it } from 'vitest';
import {
  completedProgress,
  deliveryContact,
  deliveryStatusLabel,
  matchesBusiness,
  nextActionableDelivery,
} from '../riderDeliveryPresentation';

describe('rider delivery presentation rules', () => {
  it('counts only delivered stops as complete', () => {
    expect(completedProgress([
      { id: 1, status: 'delivered' },
      { id: 2, status: 'awaiting_proof_approval' },
    ] as any)).toEqual({ completed: 1, total: 2, percent: 50 });
  });

  it('skips proof-pending and issue stops when selecting an action', () => {
    expect(nextActionableDelivery([
      { id: 1, status: 'awaiting_proof_approval', stop_sequence: 1 },
      { id: 2, status: 'delivery_attempted', stop_sequence: 2 },
      { id: 3, status: 'picked_up', stop_sequence: 3 },
    ] as any)?.id).toBe(3);
  });

  it('matches mixed work in either business filter', () => {
    const item = { business_types: ['repair', 'retail'] } as any;

    expect(matchesBusiness(item, 'repair')).toBe(true);
    expect(matchesBusiness(item, 'retail')).toBe(true);
  });

  it('uses the origin contact for inbound deliveries', () => {
    expect(deliveryContact({
      id: 1,
      leg_type: 'inbound',
      origin_snapshot: { name: 'Pickup merchant', address: 'Pickup address' },
      destination_snapshot: { name: 'Shop', address: 'Shop address' },
    } as any)).toMatchObject({ name: 'Pickup merchant', address: 'Pickup address' });
  });

  it('formats system statuses as rider-friendly text', () => {
    expect(deliveryStatusLabel('awaiting_proof_approval')).toBe('Waiting for proof approval');
  });
});
