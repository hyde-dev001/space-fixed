import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import DispatcherLiveTracking from '../DispatcherLiveTracking';

const mocks = vi.hoisted(() => ({
  liveLocations: vi.fn(),
}));

vi.mock('@/services/logisticsApi', () => ({
  logisticsApi: {
    liveLocations: mocks.liveLocations,
  },
}));

vi.mock('../LiveTrackingMap', () => ({
  default: ({ locations }: { locations: Array<{ leg_id: number }> }) => (
    <div data-testid="live-tracking-map">{locations.length} markers</div>
  ),
}));

beforeEach(() => {
  vi.clearAllMocks();
});

it('loads scoped rider locations and shows stale status', async () => {
  mocks.liveLocations.mockResolvedValue({
    data: {
      enabled: true,
      server_time: '2026-09-04T00:00:00.000Z',
      poll_after_seconds: 10,
      locations: [{
        leg_id: 42,
        shipment_id: 7,
        shipment_reference: 'Shipment #7',
        rider: { id: 3, name: 'Rider Three' },
        status: 'in_transit',
        destination: { name: 'Customer Three', address: 'Manila' },
        location: {
          latitude: 14.5995,
          longitude: 120.9842,
          accuracy_m: 12,
          speed_mps: 4,
          heading_deg: 90,
          recorded_at: '2026-09-04T00:00:00.000Z',
          received_at: '2026-09-04T00:00:01.000Z',
        },
        stale: true,
      }],
    },
  });

  render(<DispatcherLiveTracking enabled />);

  await waitFor(() => expect(mocks.liveLocations).toHaveBeenCalledTimes(1));
  expect(await screen.findByText('Rider Three')).toBeInTheDocument();
  expect(screen.getByText('Stale location')).toBeInTheDocument();
  expect(screen.getByTestId('live-tracking-map')).toHaveTextContent('1 markers');
});

it('does not poll while the feature is disabled', () => {
  render(<DispatcherLiveTracking enabled={false} />);

  expect(mocks.liveLocations).not.toHaveBeenCalled();
  expect(screen.queryByText('Live rider tracking')).not.toBeInTheDocument();
});
