import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ShipmentTrackingModal from '../ShipmentTrackingModal';

const shipment = {
  id: 12,
  purpose: 'retail_delivery',
  status: 'active',
  source_type: 'order',
  legs: [{
    id: 22,
    sequence: 1,
    leg_type: 'outbound',
    status: 'in_transit',
    origin_snapshot: { name: 'Urban Kicks Store', address: 'Cavite' },
    destination_snapshot: { name: 'Mia Santos', address: 'Cavite' },
    tracking_number: null,
    tracking_url: null,
    scheduled_delivery_date: '2026-07-18',
    delivery_window: 'morning',
    schedule_status: 'scheduled',
    latest_failed_attempt: null,
  }],
  events: [],
};

const fetchMock = vi.fn();

describe('ShipmentTrackingModal', () => {
  beforeEach(() => {
    fetchMock.mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ shipment }),
    });
    vi.stubGlobal('fetch', fetchMock);
  });

  afterEach(() => {
    vi.clearAllMocks();
    vi.unstubAllGlobals();
  });

  it('shows loading and renders the shipment after the JSON response resolves', async () => {
    render(<ShipmentTrackingModal shipmentId={12} isOpen onClose={vi.fn()} />);

    expect(screen.getByText('Loading shipment tracking...')).toBeInTheDocument();
    expect(await screen.findByText('Shipment Movement')).toBeInTheDocument();
    expect(screen.getByRole('dialog', { name: 'Shipment tracking' })).toHaveClass('userside-tracking-modal');
    expect(screen.getByText('Updates').closest('section')).toHaveClass('userside-tracking-section');
    expect(screen.getByText('SHP-12')).toBeInTheDocument();
    expect(fetchMock).toHaveBeenCalledWith(
      '/tracking/shipments/12',
      expect.objectContaining({ headers: { Accept: 'application/json' } }),
    );
  });

  it('calls onClose for the close button and Escape key', async () => {
    const onClose = vi.fn();
    render(<ShipmentTrackingModal shipmentId={12} isOpen onClose={onClose} />);

    await screen.findByText('Shipment Movement');
    fireEvent.click(screen.getByRole('button', { name: 'Close shipment tracking' }));
    fireEvent.keyDown(document, { key: 'Escape' });

    expect(onClose).toHaveBeenCalledTimes(2);
  });

  it('offers the standalone tracking page when JSON loading fails', async () => {
    fetchMock.mockResolvedValueOnce({ ok: false, status: 500 });
    render(<ShipmentTrackingModal shipmentId={12} isOpen onClose={vi.fn()} />);

    expect(await screen.findByText('Unable to load shipment tracking.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open full tracking page' }))
      .toHaveAttribute('href', '/tracking/shipments/12');
  });
});
