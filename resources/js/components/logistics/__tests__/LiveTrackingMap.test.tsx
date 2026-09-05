import { cleanup, render, waitFor } from '@testing-library/react';
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
    setLatLng: vi.fn(),
    setStyle: vi.fn(),
    unbindTooltip: vi.fn(),
  };
  marker.addTo.mockReturnValue(marker);
  marker.bindTooltip.mockReturnValue(marker);
  marker.setLatLng.mockReturnValue(marker);
  marker.setStyle.mockReturnValue(marker);
  marker.unbindTooltip.mockReturnValue(marker);

  return {
    marker,
    latLngBounds: vi.fn(() => ({})),
    map,
    mapFactory: vi.fn(() => map),
    markerFactory: vi.fn(() => marker),
    tile,
    tileLayer: vi.fn(() => tile),
  };
});

vi.mock('leaflet', () => ({
  circleMarker: leaflet.markerFactory,
  latLngBounds: leaflet.latLngBounds,
  map: leaflet.mapFactory,
  polyline: vi.fn(),
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
    expect(leaflet.marker.bindTooltip).toHaveBeenCalledWith('Rider');
  });
});
