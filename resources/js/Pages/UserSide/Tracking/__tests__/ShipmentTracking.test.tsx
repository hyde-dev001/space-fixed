import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ShipmentTracking from '../ShipmentTracking';

const shipment: any = {
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
    latest_failed_attempt: null,
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
  beforeEach(() => {
    shipment.purpose = 'retail_delivery';
    shipment.source_type = 'order';
    shipment.source_summary = null;
    shipment.status = 'active';
    shipment.legs = [{
      id: 2,
      sequence: 1,
      leg_type: 'outbound',
      status: 'pending',
      scheduled_delivery_date: '2026-07-15',
      delivery_window: 'morning',
      schedule_status: 'scheduled',
      latest_failed_attempt: null,
    }];
  });

  it('shows the formatted scheduled estimate', () => {
    render(<ShipmentTracking />);

    expect(screen.getByText('Estimated delivery')).toBeInTheDocument();
    expect(screen.getByText(/July 15, 2026.*Morning/)).toBeInTheDocument();
  });

  it('shows the shop delivery method without a tracking link when courier tracking is absent', () => {
    render(<ShipmentTracking />);

    expect(screen.getByText('SHP-1')).toBeInTheDocument();
    expect(screen.getByText('Delivery Method')).toBeInTheDocument();
    expect(screen.getByText('SoleSpace Shop Logistics')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Open SoleSpace tracking' })).not.toBeInTheDocument();
  });

  it('shows repair source details and returns to repairs', () => {
    shipment.purpose = 'repair_return';
    shipment.source_type = 'repair_request';
    shipment.source_summary = {
      request_number: 'REP-2026-0042',
      customer_name: 'Mia Santos',
      shoe_summary: 'Nike Air Max 90',
    };

    render(<ShipmentTracking />);

    expect(screen.getByRole('heading', { name: 'Repair Return' })).toBeInTheDocument();
    expect(screen.getByText('REP-2026-0042')).toBeInTheDocument();
    expect(screen.getByText('Mia Santos')).toBeInTheDocument();
    expect(screen.getByText('Nike Air Max 90')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Back to repairs' })).toBeInTheDocument();
  });

  it('shows a customer-friendly status while delivery proof is being verified', () => {
    shipment.legs[0].status = 'awaiting_proof_approval';
    render(<ShipmentTracking />);

    expect(screen.getAllByText('Delivered — confirmation in progress').length).toBeGreaterThan(0);
    expect(screen.getByText('Your item was handed over and the delivery proof is being verified.')).toBeInTheDocument();
  });

  it('shows an unresolved failed attempt reason, time, and proof', () => {
    shipment.legs[0].latest_failed_attempt = {
      id: 9,
      reason: 'Recipient unavailable',
      attempted_at: '2026-07-17T10:30:00+08:00',
      proof_url: '/tracking/shipments/1/attempts/9/proof',
    };

    render(<ShipmentTracking />);

    expect(screen.getByText('Delivery Attempt Failed')).toBeInTheDocument();
    expect(screen.getByText('Recipient unavailable')).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Failed delivery attempt proof' })).toHaveAttribute('src', '/tracking/shipments/1/attempts/9/proof');
  });

  it('keeps other-leg failures historical and falls back when proof cannot load', () => {
    shipment.legs = [{
      ...shipment.legs[0], id: 1, sequence: 1,
      latest_failed_attempt: { id: 8, reason: 'Recipient refused', attempted_at: '2026-07-16T09:00:00+08:00', proof_url: '/broken-proof' },
    }, {
      ...shipment.legs[0], id: 2, sequence: 2, status: 'delivered',
      latest_failed_attempt: { id: 9, reason: 'Recipient unavailable', attempted_at: '2026-07-17T10:30:00+08:00', proof_url: null },
    }];

    render(<ShipmentTracking />);

    expect(screen.queryByText('Delivery Attempt Failed')).not.toBeInTheDocument();
    expect(screen.getAllByText('Previous delivery attempt').length).toBe(2);
    fireEvent.error(screen.getByRole('img', { name: 'Failed delivery attempt proof' }));
    expect(screen.getAllByText('Attempt photo unavailable').length).toBe(2);
  });
});
