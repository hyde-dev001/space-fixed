import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import ShipmentTracking from '../ShipmentTracking';

const shipment: any = {
  id: 1,
  purpose: 'retail_delivery',
  status: 'active',
  source_type: 'order',
  live_tracking_enabled: true,
  legs: [{
    id: 2,
    sequence: 1,
    leg_type: 'outbound',
    status: 'in_transit',
    destination_snapshot: { type: 'customer', name: 'Customer', address: 'Manila', latitude: 14.61, longitude: 120.99 },
    live_tracking: {
      leg_id: 2,
      status: 'in_transit',
      destination: { name: 'Customer', address: 'Manila', latitude: 14.61, longitude: 120.99 },
      location: {
        latitude: 14.5995,
        longitude: 120.9842,
        accuracy_m: 12,
        speed_mps: 4,
        heading_deg: 90,
        recorded_at: '2026-09-04T00:00:00.000Z',
        received_at: '2026-09-04T00:00:01.000Z',
      },
      stale: false,
      route: { distance_m: 3200, duration_s: 480, geometry: [[14.5995, 120.9842], [14.61, 120.99]] },
    },
    latest_failed_attempt: null,
  }],
  events: [],
};

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
}));

vi.mock('axios', () => ({ default: { get: mocks.get } }));
vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  Link: ({ children, href }: { children: React.ReactNode; href: string }) => <a href={href}>{children}</a>,
  usePage: () => ({ props: { shipment } }),
}));
vi.mock('../../Shared/Navigation', () => ({ default: () => null }));
vi.mock('@/components/logistics/LiveTrackingMap', () => ({
  default: ({ locations }: { locations: Array<{ leg_id: number }> }) => (
    <div data-testid="customer-live-map">{locations.length} customer marker</div>
  ),
}));

describe('ShipmentTracking live tracking', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.get.mockResolvedValue({ data: { shipment } });
  });

  it('shows a customer-safe map, ETA, and refreshes the existing tracking payload', async () => {
    render(<ShipmentTracking />);

    expect(screen.getByText('Live delivery location')).toBeInTheDocument();
    expect(screen.getByTestId('customer-live-map')).toHaveTextContent('1 customer marker');
    expect(screen.getByText('ETA 8 min')).toBeInTheDocument();
    expect(screen.getByText('3.2 km remaining')).toBeInTheDocument();

    await waitFor(() => expect(mocks.get).toHaveBeenCalledWith('/tracking/shipments/1'));
  });

  it('shows the stale state without exposing rider identity', () => {
    shipment.legs[0].live_tracking.stale = true;

    render(<ShipmentTracking />);

    expect(screen.getByText('Location may be out of date')).toBeInTheDocument();
    expect(screen.queryByText('Rider Three')).not.toBeInTheDocument();
  });
});
