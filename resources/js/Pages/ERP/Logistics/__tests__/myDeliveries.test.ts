import { describe, expect, it } from 'vitest';
import {
  completedProgress,
  deliveryContact,
  deliveryStatusLabel,
  arrivalStatusText,
  matchesBusiness,
  nextActionableDelivery,
  orderedDeliveries,
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

  it('uses rider progress state instead of business status for active work', () => {
    expect(nextActionableDelivery([
      { id: 1, status: 'in_transit', rider_progress_state: 'proof_submitted', stop_sequence: 1 },
      { id: 2, status: 'in_transit', rider_progress_state: 'active', stop_sequence: 2 },
    ] as any)?.id).toBe(2);
    expect(nextActionableDelivery([
      { id: 1, status: 'proof_correction_required', rider_progress_state: 'proof_action_required' },
    ] as any)).toBeUndefined();
  });

  it('continues batch deliveries before a return awaiting dispatcher confirmation', () => {
    const stops = [
      { id: 1, leg_type: 'return_to_shop', status: 'in_transit', rider_progress_state: 'active', stop_sequence: 1 },
      { id: 2, leg_type: 'outbound', status: 'in_transit', rider_progress_state: 'active', stop_sequence: 2 },
      { id: 3, leg_type: 'outbound', status: 'in_transit', rider_progress_state: 'active', stop_sequence: 3 },
    ] as any;

    expect(orderedDeliveries(stops).map(({ id }) => id)).toEqual([2, 3, 1]);
    expect(nextActionableDelivery(stops)?.id).toBe(2);
    expect(nextActionableDelivery([
      stops[0],
      { ...stops[1], status: 'delivered', rider_progress_state: 'rider_released' },
      stops[2],
    ])?.id).toBe(3);
    expect(nextActionableDelivery([
      stops[0],
      { ...stops[1], status: 'delivered', rider_progress_state: 'rider_released' },
      { ...stops[2], status: 'delivered', rider_progress_state: 'rider_released' },
    ])?.id).toBe(1);
  });

  it('treats a scheduled pickup as the next rider action', () => {
    expect(nextActionableDelivery([
      { id: 1, status: 'pickup_scheduled', stop_sequence: 1 },
    ] as any)?.id).toBe(1);
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
    expect(deliveryStatusLabel('proof_correction_required')).toBe('Proof correction required');
  });

  it('formats verified and exception arrivals without relying on color', () => {
    expect(arrivalStatusText({
      result: 'verified',
      distance_m: 18,
      recorded_at: '2026-07-29T02:30:00.000Z',
    } as any)).toMatch(/Verified arrival · 18 m/);
    expect(arrivalStatusText({
      result: 'outside_geofence',
      recorded_at: '2026-07-29T02:30:00.000Z',
    } as any)).toBe('Outside service point · rider reason recorded');
  });
});
