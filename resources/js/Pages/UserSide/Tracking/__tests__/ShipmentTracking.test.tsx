import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import ShipmentTracking from '../ShipmentTracking';

const shipment = {
  id: 1,
  purpose: 'retail_delivery',
  status: 'active',
  legs: [{
    id: 2,
    leg_type: 'outbound',
    status: 'pending',
    scheduled_delivery_date: '2026-07-15',
    delivery_window: 'morning',
    schedule_status: 'scheduled',
  }],
  events: [],
};

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children }: { children: React.ReactNode }) => <a>{children}</a>,
  usePage: () => ({ props: { shipment } }),
}));
vi.mock('../../Shared/Navigation', () => ({ default: () => null }));

describe('ShipmentTracking', () => {
  it('shows the formatted scheduled estimate', () => {
    render(<ShipmentTracking />);

    expect(screen.getByText('Estimated delivery')).toBeInTheDocument();
    expect(screen.getByText(/July 15, 2026.*Morning/)).toBeInTheDocument();
  });

  it('shows a customer-friendly status while delivery proof is being verified', () => {
    shipment.legs[0].status = 'awaiting_proof_approval';
    render(<ShipmentTracking />);

    expect(screen.getAllByText('Delivered — confirmation in progress').length).toBeGreaterThan(0);
    expect(screen.getByText('Your item was handed over and the delivery proof is being verified.')).toBeInTheDocument();
  });
});
