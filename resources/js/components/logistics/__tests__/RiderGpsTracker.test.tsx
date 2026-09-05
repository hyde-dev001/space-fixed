import { act, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import RiderGpsTracker from '../RiderGpsTracker';

const mocks = vi.hoisted(() => ({
  getPosition: vi.fn(),
  recordLocation: vi.fn(),
  watchPosition: vi.fn(),
  clearWatch: vi.fn(),
}));

vi.mock('@/utils/geolocation', () => ({
  GPS_POSITION_OPTIONS: {
    enableHighAccuracy: true,
    timeout: 15_000,
    maximumAge: 0,
  },
  getCurrentPositionWithFallback: mocks.getPosition,
}));

vi.mock('@/services/logisticsApi', () => ({
  logisticsApi: {
    recordLocation: mocks.recordLocation,
  },
}));

vi.mock('../LiveTrackingMap', () => ({
  default: ({ locations, viewer }: { locations: Array<{ destination?: { address?: string | null }; location?: { latitude?: number }; route?: { source?: string | null } }>; viewer?: string }) => (
    <div data-testid="rider-route-map">{viewer ?? 'unset'} {locations[0]?.destination?.address} {locations[0]?.route?.source ?? 'no-route'} {locations[0]?.location?.latitude ?? ''}</div>
  ),
}));

const position = (timestamp = Date.parse('2026-09-04T00:00:00.000Z')) => ({
  coords: {
    latitude: 14.3001,
    longitude: 120.9501,
    accuracy: 12.5,
    speed: 8.2,
    heading: 90,
  },
  timestamp,
}) as GeolocationPosition;

beforeEach(() => {
  vi.clearAllMocks();
  Object.defineProperty(navigator, 'geolocation', {
    configurable: true,
    value: {
      watchPosition: mocks.watchPosition,
      clearWatch: mocks.clearWatch,
    },
  });
  mocks.watchPosition.mockReturnValue(1);
  mocks.recordLocation.mockResolvedValue({ data: { accepted: true } });
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('RiderGpsTracker', () => {
  it('automatically starts tracking and sends the browser GPS reading to the assigned leg', async () => {
    mocks.getPosition.mockResolvedValue(position());

    render(<RiderGpsTracker legId={42} enabled online />);

    await waitFor(() => expect(mocks.recordLocation).toHaveBeenCalledWith(42, {
      latitude: 14.3001,
      longitude: 120.9501,
      accuracy_m: 12.5,
      speed_mps: 8.2,
      heading_deg: 90,
      recorded_at: '2026-09-04T00:00:00.000Z',
    }));
    expect(screen.getByText('GPS tracking active')).toBeVisible();
  });
  it('automatically resumes tracking after the rider refreshes the delivery page', async () => {
    mocks.getPosition.mockResolvedValue(position());

    const firstRender = render(<RiderGpsTracker legId={42} enabled online />);
    await waitFor(() => expect(mocks.recordLocation).toHaveBeenCalled());
    firstRender.unmount();
    mocks.recordLocation.mockClear();

    render(<RiderGpsTracker legId={42} enabled online />);

    await waitFor(() => expect(mocks.recordLocation).toHaveBeenCalledWith(42, expect.objectContaining({
      latitude: 14.3001,
      longitude: 120.9501,
    })));
    expect(screen.getByRole('button', { name: 'Stop GPS tracking' })).toBeVisible();
  });

  it('updates the rider marker from live GPS watch readings', async () => {
    let onPosition: PositionCallback | null = null;
    mocks.watchPosition.mockImplementation((success: PositionCallback) => {
      onPosition = success;
      return 9;
    });
    mocks.getPosition.mockResolvedValue(position());

    const view = render(
      <RiderGpsTracker
        legId={42}
        enabled
        online
        destination={{ latitude: 14.4, longitude: 121.05, address: 'Customer address' }}
      />,
    );

    await waitFor(() => expect(mocks.recordLocation).toHaveBeenCalled());

    const moved = position(Date.parse('2026-09-04T00:00:30.000Z'));
    Object.defineProperty(moved.coords, 'latitude', { value: 14.31 });
    Object.defineProperty(moved.coords, 'longitude', { value: 120.96 });
    act(() => onPosition?.(moved));

    expect(screen.getByTestId('rider-route-map')).toHaveTextContent('14.31');
    view.unmount();
    expect(mocks.clearWatch).toHaveBeenCalledWith(9);
  });

  it('uses a validated public-IP location when desktop GPS is only network-level', async () => {
    const inaccurate = position();
    Object.defineProperty(inaccurate.coords, 'accuracy', { value: 100_000 });
    mocks.getPosition.mockResolvedValue(inaccurate);
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ latitude: 14.31, longitude: 120.96 }),
    }));

    render(
      <RiderGpsTracker
        legId={42}
        enabled
        online
        destination={{ latitude: 14.4, longitude: 121.05, address: 'Customer address' }}
      />,
    );

    await waitFor(() => expect(mocks.recordLocation).toHaveBeenCalledWith(42, expect.objectContaining({
      latitude: 14.31,
      longitude: 120.96,
      recorded_at: expect.any(String),
    })));
    expect(mocks.recordLocation.mock.calls[0][1]).not.toHaveProperty('accuracy_m');
    expect(screen.getByText(/approximate public network location/i)).toBeVisible();
  });
  it('replaces an approximate IP marker with an accurate watchPosition reading', async () => {
    let onPosition: PositionCallback | null = null;
    mocks.watchPosition.mockImplementation((success: PositionCallback) => {
      onPosition = success;
      return 9;
    });
    const inaccurate = position();
    Object.defineProperty(inaccurate.coords, 'accuracy', { value: 100_000 });
    mocks.getPosition.mockResolvedValue(inaccurate);
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ latitude: 14.31, longitude: 120.96 }),
    }));

    render(
      <RiderGpsTracker
        legId={42}
        enabled
        online
        destination={{ latitude: 14.4, longitude: 121.05, address: 'Customer address' }}
      />,
    );

    await waitFor(() => expect(mocks.recordLocation).toHaveBeenCalled());

    const accurate = position(inaccurate.timestamp);
    Object.defineProperty(accurate.coords, 'latitude', { value: 14.3101 });
    Object.defineProperty(accurate.coords, 'longitude', { value: 120.9601 });
    act(() => onPosition?.(accurate));

    expect(screen.getByTestId('rider-route-map')).toHaveTextContent('14.3101');
  });

  it('does not bypass an explicit location permission denial with an IP lookup', async () => {
    mocks.getPosition.mockRejectedValue({ code: 1 });
    vi.stubGlobal('fetch', vi.fn());

    render(<RiderGpsTracker legId={42} enabled online />);

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Location permission is required to start tracking.',
    );
    expect(fetch).not.toHaveBeenCalled();
    expect(mocks.recordLocation).not.toHaveBeenCalled();
  });

  it('does not post a malformed public-IP location', async () => {
    const malformed = position();
    Object.defineProperty(malformed.coords, 'accuracy', { value: 100_000 });
    mocks.getPosition.mockResolvedValue(malformed);
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ latitude: 'not-a-coordinate', longitude: 120.96 }),
    }));

    render(<RiderGpsTracker legId={42} enabled online />);

    expect(await screen.findByRole('alert')).toHaveTextContent(/desktop network location/i);
    expect(mocks.recordLocation).not.toHaveBeenCalled();
  });

  it('explains when the rider denies location permission', async () => {
    mocks.getPosition.mockRejectedValue({ code: 1 });

    render(<RiderGpsTracker legId={42} enabled online />);

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Location permission is required to start tracking.',
    );
    expect(mocks.recordLocation).not.toHaveBeenCalled();
  });

  it('shows the customer route after the rider shares a GPS position', async () => {
    mocks.getPosition.mockResolvedValue(position());

    render(
      <RiderGpsTracker
        legId={42}
        enabled
        online
        destination={{
          name: 'Customer',
          address: 'Customer address',
          latitude: 14.4,
          longitude: 121.05,
        }}
      />,
    );

    expect(await screen.findByTestId('rider-route-map')).toHaveTextContent('Customer address');
    expect(screen.getByTestId('rider-route-map')).toHaveTextContent('rider');
    expect(screen.getByText(/Road route is unavailable right now/)).toBeVisible();
  });

  it('uses the server-provided road route for the rider map', async () => {
    mocks.getPosition.mockResolvedValue(position());
    mocks.recordLocation.mockResolvedValue({
      data: {
        accepted: true,
        route: {
          source: 'road',
          distance_m: 4200,
          duration_s: 600,
          geometry: [[14.3001, 120.9501], [14.31, 121.0]],
        },
      },
    });

    render(
      <RiderGpsTracker
        legId={42}
        enabled
        online
        destination={{ latitude: 14.4, longitude: 121.05, address: 'Customer address' }}
      />,
    );

    expect(await screen.findByTestId('rider-route-map')).toHaveTextContent('Customer address road');
  });

  it('shows a location API validation message when the server rejects a reading', async () => {
    mocks.getPosition.mockResolvedValue(position());
    mocks.recordLocation.mockRejectedValue({
      response: {
        status: 422,
        data: { errors: { accuracy_m: ['The GPS accuracy is too low for tracking.'] } },
      },
    });

    render(<RiderGpsTracker legId={42} enabled online />);

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'The GPS accuracy is too low for tracking.',
    );
  });
});
