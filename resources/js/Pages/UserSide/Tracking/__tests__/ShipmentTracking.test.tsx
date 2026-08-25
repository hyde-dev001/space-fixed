import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
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
  Link: ({ children, href }: { children: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
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

  it('keeps proof correction internal and customer-safe', () => {
    shipment.legs[0].status = 'proof_correction_required';
    render(<ShipmentTracking />);

    expect(screen.getAllByText('Delivered — confirmation in progress').length).toBeGreaterThan(0);
    expect(screen.queryByText('Proof correction required')).not.toBeInTheDocument();
  });

  it('shows an unresolved failed attempt reason, time, and proof', () => {
    shipment.legs[0].latest_failed_attempt = {
      id: 9,
      attempt_type: 'delivery',
      reason: 'Recipient unavailable',
      attempted_at: '2026-07-17T10:30:00+08:00',
      proof_url: '/tracking/shipments/1/attempts/9/proof',
    };

    render(<ShipmentTracking />);

    expect(screen.getByText('Delivery Attempt Failed')).toBeInTheDocument();
    expect(screen.getByText('Recipient unavailable')).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Failed delivery attempt proof' })).toHaveAttribute('src', '/tracking/shipments/1/attempts/9/proof');
  });

  it('shows failed pickup language and proof with a missing-photo fallback', () => {
    shipment.purpose = 'repair_pickup';
    shipment.source_type = 'repair_request';
    shipment.legs[0].status = 'needs_resolution';
    shipment.legs[0].latest_failed_attempt = {
      id: 9,
      attempt_type: 'pickup',
      reason: 'Customer unavailable / not home',
      attempted_at: '2026-07-29T10:30:00+08:00',
      proof_url: '/tracking/shipments/1/attempts/9/proof',
    };

    render(<ShipmentTracking />);

    expect(screen.getByText('Pickup attempt unsuccessful')).toBeInTheDocument();
    expect(screen.getByText('Customer unavailable / not home')).toBeInTheDocument();
    const proof = screen.getByAltText('Failed pickup proof');
    expect(proof).toHaveAttribute('src', '/tracking/shipments/1/attempts/9/proof');
    fireEvent.error(proof);
    expect(screen.getByText('Attempt photo unavailable')).toBeInTheDocument();
  });

  it('keeps resolved pickup failures in history', () => {
    shipment.purpose = 'repair_pickup';
    shipment.source_type = 'repair_request';
    shipment.legs[0].status = 'delivered';
    shipment.legs[0].latest_failed_attempt = {
      id: 9,
      attempt_type: 'pickup',
      reason: 'Customer requested reschedule',
      attempted_at: '2026-07-29T10:30:00+08:00',
      proof_url: null,
    };

    render(<ShipmentTracking />);

    expect(screen.getByText('Previous pickup attempt')).toBeInTheDocument();
    expect(screen.getByText('Attempt photo unavailable')).toBeInTheDocument();
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

  it('opens an accessible proof viewer with delivery details, zoom, download, and focus return', async () => {
    shipment.legs[0] = {
      ...shipment.legs[0],
      status: 'delivered',
      delivered_at: '2026-07-15T11:13:54.000Z',
      destination_snapshot: { name: 'Miguel Dela Rosa', address: 'Dasmariñas, Cavite' },
      latest_failed_attempt: {
        id: 9,
        reason: 'Recipient unavailable',
        attempted_at: '2026-07-14T10:30:00.000Z',
        proof_url: '/tracking/shipments/1/attempts/9/proof',
      },
      delivery_proof: {
        id: 17,
        available: true,
        url: '/tracking/shipments/1/proofs/17',
        delivered_at: '2026-07-15T11:13:54.000Z',
        location: 'Miguel Dela Rosa - Dasmariñas, Cavite',
        tracking_number: 'SHP-1',
        status: 'Delivered',
      },
    };

    render(<ShipmentTracking />);

    expect(screen.getByText('Previous delivery attempt')).toBeInTheDocument();
    const opener = screen.getByRole('button', { name: 'View proof of delivery' });
    expect(opener).toHaveClass('min-h-11');
    opener.focus();
    fireEvent.click(opener);

    const dialog = screen.getByRole('dialog', { name: 'Proof of delivery' });
    expect(dialog).toHaveAttribute('aria-modal', 'true');
    const close = screen.getByRole('button', { name: 'Close proof viewer' });
    expect(close).toHaveClass('min-h-11', 'min-w-11');
    expect(close).toHaveFocus();
    const image = screen.getByRole('img', { name: 'Proof of delivery for SHP-1' });
    expect(image).toHaveAttribute('src', '/tracking/shipments/1/proofs/17');
    expect(image).toHaveStyle({ transform: 'scale(1)' });
    expect(screen.getByText(new Date('2026-07-15T11:13:54.000Z').toLocaleString())).toBeInTheDocument();
    expect(screen.getAllByText('Miguel Dela Rosa - Dasmariñas, Cavite').length).toBeGreaterThan(1);
    expect(screen.getAllByText('SHP-1').length).toBeGreaterThan(1);
    expect(screen.getAllByText('Delivered').length).toBeGreaterThan(0);

    const download = screen.getByRole('link', { name: 'Download proof of delivery' });
    expect(download).toHaveAttribute('href', '/tracking/shipments/1/proofs/17?download=1');
    expect(download).toHaveClass('min-h-11');
    fireEvent.click(screen.getByRole('button', { name: 'Zoom to 150%' }));
    expect(image).toHaveStyle({ transform: 'scale(1.5)' });
    fireEvent.click(screen.getByRole('button', { name: 'Zoom to 200%' }));
    expect(image).toHaveStyle({ transform: 'scale(2)' });
    fireEvent.click(screen.getByRole('button', { name: 'Zoom to 100%' }));
    expect(image).toHaveStyle({ transform: 'scale(1)' });

    fireEvent.error(image);
    expect(screen.getByText('Proof unavailable')).toBeInTheDocument();
    expect(screen.queryByRole('img', { name: 'Proof of delivery for SHP-1' })).not.toBeInTheDocument();

    fireEvent.click(close);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    await waitFor(() => expect(opener).toHaveFocus());

    fireEvent.click(opener);
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    await waitFor(() => expect(opener).toHaveFocus());
  });

  it('shows an unavailable message without a broken proof image', () => {
    shipment.legs[0] = {
      ...shipment.legs[0],
      status: 'delivered',
      delivery_proof: {
        id: 18,
        available: false,
        url: null,
        delivered_at: '2026-07-15T11:13:54.000Z',
        location: 'Dasmariñas, Cavite',
        tracking_number: 'SHP-1',
        status: 'Delivered',
      },
    };

    render(<ShipmentTracking />);

    expect(screen.getByText('Proof unavailable')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'View proof of delivery' })).not.toBeInTheDocument();
    expect(screen.queryByRole('img', { name: /Proof of delivery for/ })).not.toBeInTheDocument();
  });

  it('does not show delivery proof controls when the payload has no eligible proof', () => {
    render(<ShipmentTracking />);

    expect(screen.queryByRole('button', { name: 'View proof of delivery' })).not.toBeInTheDocument();
    expect(screen.queryByText('Proof unavailable')).not.toBeInTheDocument();
  });
});
