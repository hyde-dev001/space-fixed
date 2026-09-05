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

  it('shows live pickup tracking for a repair shipment while the rider is going to the customer', async () => {
    shipment.purpose = 'repair_pickup';
    shipment.source_type = 'repair_request';
    shipment.legs[0] = {
      ...shipment.legs[0],
      leg_type: 'inbound',
      status: 'assigned',
      origin_snapshot: {
        type: 'customer',
        name: 'Customer Home',
        address: 'Customer pickup address',
        latitude: 14.61,
        longitude: 120.99,
      },
      destination_snapshot: {
        type: 'shop',
        name: 'Repair shop',
        address: 'Shop address',
        latitude: 14.62,
        longitude: 121,
      },
      live_tracking: {
        ...shipment.legs[0].live_tracking,
        destination: {
          type: 'customer',
          name: 'Customer Home',
          address: 'Customer pickup address',
          latitude: 14.61,
          longitude: 120.99,
        },
      },
    };

    render(<ShipmentTracking />);

    expect(screen.getByText('Live pickup location')).toBeInTheDocument();
    expect(screen.getByTestId('customer-live-map')).toHaveTextContent('1 customer marker');
    await waitFor(() => expect(mocks.get).toHaveBeenCalledWith('/tracking/shipments/1'));
  });

  it('continues live pickup tracking toward the repair shop after handoff', async () => {
    shipment.purpose = 'repair_pickup';
    shipment.source_type = 'repair_request';
    shipment.legs[0] = {
      ...shipment.legs[0],
      leg_type: 'inbound',
      status: 'in_transit',
      picked_up_at: '2026-09-04T00:05:00.000Z',
      origin_snapshot: {
        type: 'customer',
        name: 'Customer Home',
        address: 'Customer pickup address',
        latitude: 14.61,
        longitude: 120.99,
      },
      destination_snapshot: {
        type: 'shop',
        name: 'Repair shop',
        address: 'Shop address',
        latitude: 14.62,
        longitude: 121,
      },
      live_tracking: {
        ...shipment.legs[0].live_tracking,
        status: 'in_transit',
        destination: {
          type: 'shop',
          name: 'Repair shop',
          address: 'Shop address',
          latitude: 14.62,
          longitude: 121,
        },
      },
    };

    render(<ShipmentTracking />);

    expect(screen.getByText('Live pickup location')).toBeInTheDocument();
    expect(screen.getByTestId('customer-live-map')).toHaveTextContent('1 customer marker');
    await waitFor(() => expect(mocks.get).toHaveBeenCalledWith('/tracking/shipments/1'));
  });

  it('keeps polling through the pickup handoff before the rider starts the shop leg', async () => {
    shipment.purpose = 'repair_pickup';
    shipment.source_type = 'repair_request';
    shipment.legs[0] = {
      ...shipment.legs[0],
      leg_type: 'inbound',
      status: 'picked_up',
      picked_up_at: '2026-09-04T00:05:00.000Z',
      destination_snapshot: {
        type: 'shop',
        name: 'Repair shop',
        address: 'Shop address',
        latitude: 14.62,
        longitude: 121,
      },
      live_tracking: null,
    };

    render(<ShipmentTracking />);

    expect(screen.getByText('Live pickup location')).toBeInTheDocument();
    expect(screen.getByText(/Waiting for the rider's first GPS update\./)).toBeInTheDocument();
    await waitFor(() => expect(mocks.get).toHaveBeenCalledWith('/tracking/shipments/1'));
  });

  it('shows the stale state without exposing rider identity', () => {
    shipment.legs[0].live_tracking = {
      leg_id: 2,
      status: 'in_transit',
      destination: shipment.legs[0].destination_snapshot,
      location: {
        latitude: 14.5995,
        longitude: 120.9842,
        accuracy_m: 12,
        speed_mps: 4,
        heading_deg: 90,
        recorded_at: '2026-09-04T00:00:00.000Z',
        received_at: '2026-09-04T00:00:01.000Z',
      },
      route: null,
      stale: false,
    };
    shipment.legs[0].live_tracking.stale = true;

    render(<ShipmentTracking />);

    expect(screen.getByText('Location may be out of date')).toBeInTheDocument();
    expect(screen.queryByText('Rider Three')).not.toBeInTheDocument();
  });

  it('shows live tracking while a retail refund return rider collects the item', async () => {
    shipment.purpose = 'refund_return';
    shipment.source_type = 'order_refund';
    shipment.status = 'active';
    shipment.legs[0] = {
      ...shipment.legs[0],
      leg_type: 'return_to_shop',
      status: 'assigned',
      picked_up_at: null,
      origin_snapshot: {
        type: 'customer',
        name: 'Customer Home',
        address: 'Customer return address',
        latitude: 14.61,
        longitude: 120.99,
      },
      destination_snapshot: {
        type: 'shop',
        name: 'Retail Shop',
        address: 'Shop address',
        latitude: 14.62,
        longitude: 121,
      },
      live_tracking: {
        ...shipment.legs[0].live_tracking,
        status: 'assigned',
        destination: {
          type: 'customer',
          name: 'Customer Home',
          address: 'Customer return address',
          latitude: 14.61,
          longitude: 120.99,
        },
      },
    };

    render(<ShipmentTracking />);

    expect(screen.getByText('Live return location')).toBeInTheDocument();
    expect(screen.getByTestId('customer-live-map')).toHaveTextContent('1 customer marker');
    await waitFor(() => expect(mocks.get).toHaveBeenCalledWith('/tracking/shipments/1'));
  });
});
