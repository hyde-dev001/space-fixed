import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import LiveTrackingMap from '../LiveTrackingMap';

const leaflet = vi.hoisted(() => {
  const map = {
    fitBounds: vi.fn(),
    invalidateSize: vi.fn(),
    panTo: vi.fn(),
    remove: vi.fn(),
    setView: vi.fn(),
  };
  map.setView.mockReturnValue(map);

  const tile = {
    addTo: vi.fn(),
    redraw: vi.fn(),
  };

  const marker = {
    addTo: vi.fn(),
    bindTooltip: vi.fn(),
    getElement: vi.fn(() => null),
    removeFrom: vi.fn(),
    setIcon: vi.fn(),
    setLatLng: vi.fn(),
    setOpacity: vi.fn(),
    unbindTooltip: vi.fn(),
  };
  marker.addTo.mockReturnValue(marker);
  marker.bindTooltip.mockReturnValue(marker);
  marker.removeFrom.mockReturnValue(marker);
  marker.setIcon.mockReturnValue(marker);
  marker.setLatLng.mockReturnValue(marker);
  marker.setOpacity.mockReturnValue(marker);
  marker.unbindTooltip.mockReturnValue(marker);

  const destinationMarker = {
    addTo: vi.fn(),
    bindTooltip: vi.fn(),
    removeFrom: vi.fn(),
    setLatLng: vi.fn(),
    setStyle: vi.fn(),
    unbindTooltip: vi.fn(),
  };
  destinationMarker.addTo.mockReturnValue(destinationMarker);
  destinationMarker.bindTooltip.mockReturnValue(destinationMarker);
  destinationMarker.removeFrom.mockReturnValue(destinationMarker);
  destinationMarker.setLatLng.mockReturnValue(destinationMarker);
  destinationMarker.setStyle.mockReturnValue(destinationMarker);
  destinationMarker.unbindTooltip.mockReturnValue(destinationMarker);

  const route = {
    addTo: vi.fn(),
    removeFrom: vi.fn(),
    setLatLngs: vi.fn(),
    setStyle: vi.fn(),
  };
  route.addTo.mockReturnValue(route);
  route.setLatLngs.mockReturnValue(route);
  route.setStyle.mockReturnValue(route);

  return {
    marker,
    latLngBounds: vi.fn(() => ({})),
    map,
    mapFactory: vi.fn(() => map),
    markerFactory: vi.fn(() => marker),
    destinationMarker,
    destinationMarkerFactory: vi.fn(() => destinationMarker),
    divIconFactory: vi.fn((options) => options),
    polylineFactory: vi.fn(() => route),
    route,
    tile,
    tileLayer: vi.fn(() => tile),
  };
});

vi.mock('leaflet', () => ({
  circleMarker: leaflet.destinationMarkerFactory,
  divIcon: leaflet.divIconFactory,
  latLngBounds: leaflet.latLngBounds,
  map: leaflet.mapFactory,
  marker: leaflet.markerFactory,
  polyline: leaflet.polylineFactory,
  tileLayer: leaflet.tileLayer,
}));

describe('LiveTrackingMap', () => {
  const resizeObserver = {
    disconnect: vi.fn(),
    observe: vi.fn(),
  };
  let resizeCallback: ResizeObserverCallback | undefined;

  beforeEach(() => {
    vi.clearAllMocks();
    resizeCallback = undefined;
    vi.stubGlobal('ResizeObserver', vi.fn((callback: ResizeObserverCallback) => {
      resizeCallback = callback;
      return resizeObserver;
    }));
  });

  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it('recalculates the map without clearing already loaded map tiles', async () => {
    render(<LiveTrackingMap locations={[]} />);

    expect(screen.getByLabelText('Live rider map')).toHaveClass('h-[30rem]', 'sm:h-[38rem]', 'lg:h-[44rem]', 'bg-white', '[&_.leaflet-tile]:!mix-blend-normal');
    expect(screen.getByRole('button', { name: 'View map full screen' })).toHaveClass('min-h-11', 'min-w-11', 'sm:hidden');
    await waitFor(() => expect(leaflet.mapFactory).toHaveBeenCalled());
    expect(resizeObserver.observe).toHaveBeenCalled();
    expect(resizeCallback).toBeTypeOf('function');

    resizeCallback?.([], resizeObserver as unknown as ResizeObserver);

    expect(leaflet.map.invalidateSize).toHaveBeenCalledWith({
      pan: false,
      debounceMoveend: true,
    });
    expect(leaflet.tile.redraw).not.toHaveBeenCalled();

  });

  it('labels an anonymous rider marker as Rider', async () => {
    render(
      <LiveTrackingMap
        locations={[{
          leg_id: 1,
          shipment_id: 1,
          shipment_reference: 'SHP-1',
          delivery_type: 'repair_pickup',
          delivery_label: 'Repair Pickup',
          rider: { id: null, name: null },
          status: 'active',
          destination: {},
          location: {
            latitude: 14.6,
            longitude: 120.98,
            accuracy_m: null,
            speed_mps: null,
            heading_deg: null,
            recorded_at: null,
            received_at: null,
          },
          stale: false,
        }]}
      />,
    );

    await waitFor(() => expect(leaflet.markerFactory).toHaveBeenCalled());
    expect(leaflet.marker.bindTooltip).toHaveBeenCalledWith('Rider · Repair Pickup');
    expect(leaflet.divIconFactory).toHaveBeenCalledWith(expect.objectContaining({
      html: expect.stringContaining('bikers.png'),
      iconSize: [40, 40],
    }));
    expect(leaflet.divIconFactory.mock.calls[0][0].html).toContain('width="40" height="40"');
  });

  it('toggles the mobile map fullscreen control and resizes the map', async () => {
    const requestFullscreen = vi.fn().mockResolvedValue(undefined);
    const exitFullscreen = vi.fn().mockResolvedValue(undefined);
    const originalRequestFullscreen = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'requestFullscreen');
    const originalExitFullscreen = Object.getOwnPropertyDescriptor(document, 'exitFullscreen');
    const originalFullscreenElement = Object.getOwnPropertyDescriptor(document, 'fullscreenElement');
    let fullscreenElement: Element | null = null;

    Object.defineProperty(HTMLElement.prototype, 'requestFullscreen', {
      configurable: true,
      value: requestFullscreen,
    });
    Object.defineProperty(document, 'exitFullscreen', {
      configurable: true,
      value: exitFullscreen,
    });
    Object.defineProperty(document, 'fullscreenElement', {
      configurable: true,
      get: () => fullscreenElement,
    });

    try {
      render(<LiveTrackingMap locations={[]} />);

      const button = screen.getByRole('button', { name: 'View map full screen' });
      const shell = button.parentElement;
      expect(shell).not.toBeNull();
      await waitFor(() => expect(leaflet.mapFactory).toHaveBeenCalled());

      fireEvent.click(button);
      expect(requestFullscreen).toHaveBeenCalledTimes(1);

      fullscreenElement = shell;
      fireEvent(document, new Event('fullscreenchange'));
      await waitFor(() => expect(screen.getByRole('button', { name: 'Exit full screen map' })).toBeInTheDocument());
      expect(leaflet.map.invalidateSize).toHaveBeenCalledWith({
        pan: false,
        debounceMoveend: true,
      });
      expect(leaflet.tile.redraw).not.toHaveBeenCalled();

      fireEvent.click(screen.getByRole('button', { name: 'Exit full screen map' }));
      expect(exitFullscreen).toHaveBeenCalledTimes(1);

      fullscreenElement = null;
      fireEvent(document, new Event('fullscreenchange'));
      await waitFor(() => expect(screen.getByRole('button', { name: 'View map full screen' })).toBeInTheDocument());
    } finally {
      if (originalRequestFullscreen) {
        Object.defineProperty(HTMLElement.prototype, 'requestFullscreen', originalRequestFullscreen);
      } else {
        Reflect.deleteProperty(HTMLElement.prototype, 'requestFullscreen');
      }
      if (originalExitFullscreen) {
        Object.defineProperty(document, 'exitFullscreen', originalExitFullscreen);
      } else {
        Reflect.deleteProperty(document, 'exitFullscreen');
      }
      if (originalFullscreenElement) {
        Object.defineProperty(document, 'fullscreenElement', originalFullscreenElement);
      } else {
        Reflect.deleteProperty(document, 'fullscreenElement');
      }
    }
  });

  it('anchors a cached road route to the latest rider position', async () => {
    const route = {
      source: 'road' as const,
      distance_m: 4200,
      duration_s: 600,
      geometry: [[14.6, 120.98], [14.62, 121.0], [14.7, 121.05]] as [number, number][],
    };
    const firstLocation = {
      leg_id: 1,
      shipment_id: 1,
      shipment_reference: 'SHP-1',
      rider: { id: 1, name: 'Rider' },
      status: 'active',
      destination: { latitude: 14.7, longitude: 121.05 },
      location: {
        latitude: 14.6,
        longitude: 120.98,
        accuracy_m: null,
        speed_mps: null,
        heading_deg: null,
        recorded_at: null,
        received_at: null,
      },
      stale: false,
      route,
    };
    const view = render(<LiveTrackingMap locations={[firstLocation]} />);

    await waitFor(() => expect(leaflet.polylineFactory).toHaveBeenCalled());

    view.rerender(
      <LiveTrackingMap
        locations={[{
          ...firstLocation,
          location: { ...firstLocation.location, latitude: 14.61, longitude: 120.99 },
        }]}
      />,
    );

    await waitFor(() => expect(leaflet.route.setLatLngs).toHaveBeenLastCalledWith([
      [14.61, 120.99],
      [14.62, 121.0],
      [14.7, 121.05],
    ]));
  });

  it('animates a rider marker between accepted location samples', async () => {
    const frames: FrameRequestCallback[] = [];
    vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => {
      frames.push(callback);
      return frames.length;
    });

    const firstLocation = {
      leg_id: 9,
      shipment_id: 1,
      shipment_reference: 'SHP-1',
      rider: { id: 1, name: 'Rider' },
      status: 'active',
      destination: {},
      location: {
        latitude: 14.6,
        longitude: 120.98,
        accuracy_m: null,
        speed_mps: null,
        heading_deg: null,
        recorded_at: null,
        received_at: null,
      },
      stale: false,
    };
    const view = render(<LiveTrackingMap locations={[firstLocation]} />);

    await waitFor(() => expect(leaflet.markerFactory).toHaveBeenCalled());
    frames.length = 0;
    view.rerender(
      <LiveTrackingMap
        locations={[{
          ...firstLocation,
          location: { ...firstLocation.location, latitude: 14.61, longitude: 120.99 },
        }]}
      />,
    );

    await waitFor(() => expect(frames.length).toBeGreaterThan(0));
    expect(leaflet.marker.setLatLng).not.toHaveBeenLastCalledWith([14.61, 120.99]);
  });

  it('does not replace a newer canonical route with an older route response', async () => {
    const newerRoute = {
      source: 'road' as const,
      distance_m: 1000,
      duration_s: 120,
      route_version: 2,
      updated_at: '2026-09-09T00:00:02.000Z',
      geometry: [[14.6, 120.98], [14.65, 121.0]] as [number, number][],
    };
    const olderRoute = {
      ...newerRoute,
      distance_m: 2000,
      route_version: 1,
      updated_at: '2026-09-09T00:00:01.000Z',
      geometry: [[14.6, 120.98], [14.7, 121.05]] as [number, number][],
    };
    const location = {
      leg_id: 10,
      shipment_id: 1,
      shipment_reference: 'SHP-1',
      rider: { id: 1, name: 'Rider' },
      status: 'active',
      destination: {},
      location: {
        latitude: 14.6,
        longitude: 120.98,
        accuracy_m: null,
        speed_mps: null,
        heading_deg: null,
        recorded_at: null,
        received_at: null,
      },
      stale: false,
      route: newerRoute,
    };
    const view = render(<LiveTrackingMap locations={[location]} />);

    await waitFor(() => expect(leaflet.polylineFactory).toHaveBeenCalled());
    leaflet.route.setLatLngs.mockClear();
    view.rerender(<LiveTrackingMap locations={[{ ...location, route: olderRoute }]} />);

    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(leaflet.route.setLatLngs).not.toHaveBeenCalledWith([
      [14.6, 120.98],
      [14.7, 121.05],
    ]);
  });
});
