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

  return {
    latLngBounds: vi.fn(() => ({})),
    map,
    mapFactory: vi.fn(() => map),
    tile,
    tileLayer: vi.fn(() => tile),
  };
});

vi.mock('leaflet', () => ({
  circleMarker: vi.fn(),
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
});
