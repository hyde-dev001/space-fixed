import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CustomerAddressMapPicker from '../CustomerAddressMapPicker';

const leaflet = vi.hoisted(() => {
  const interaction = () => ({ disable: vi.fn(), enable: vi.fn() });
  const map = {
    boxZoom: interaction(),
    doubleClickZoom: interaction(),
    dragging: interaction(),
    invalidateSize: vi.fn(),
    keyboard: interaction(),
    on: vi.fn(),
    remove: vi.fn(),
    scrollWheelZoom: interaction(),
    setView: vi.fn(),
    touchZoom: interaction(),
    zoomControl: { addTo: vi.fn(), remove: vi.fn() },
  };
  map.setView.mockReturnValue(map);

  const marker = {
    addTo: vi.fn(),
    dragging: interaction(),
    getLatLng: vi.fn(() => ({ lat: 14.5995, lng: 120.9842 })),
    on: vi.fn(),
    remove: vi.fn(),
    setLatLng: vi.fn(),
  };
  marker.addTo.mockReturnValue(marker);

  return {
    map,
    mapFactory: vi.fn(() => map),
    marker,
    markerFactory: vi.fn(() => marker),
    tileLayer: vi.fn(() => ({ addTo: vi.fn() })),
  };
});

vi.mock('leaflet', () => ({
  Icon: { Default: { mergeOptions: vi.fn(), prototype: {} } },
  map: leaflet.mapFactory,
  marker: leaflet.markerFactory,
  tileLayer: leaflet.tileLayer,
}));

const addressResult = {
  address: {
    city: 'Manila',
    country_code: 'ph',
    postcode: '1000',
    province: 'Metro Manila',
    region: 'NCR',
    suburb: 'Ermita',
  },
  display_name: 'Ermita, Manila, Metro Manila, Philippines',
  lat: '14.5995',
  lon: '120.9842',
};

const response = (body: unknown, ok = true, status = ok ? 200 : 502, headers = new Headers()) => ({
  headers,
  json: vi.fn().mockResolvedValue(body),
  ok,
  status,
}) as unknown as Response;

describe('CustomerAddressMapPicker', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    leaflet.marker.getLatLng.mockReturnValue({ lat: 14.5995, lng: 120.9842 });
    Object.defineProperty(navigator, 'geolocation', {
      configurable: true,
      value: { getCurrentPosition: vi.fn() },
    });
  });

  afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it('renders accessible controls, status, map, and selected coordinates', async () => {
    render(<CustomerAddressMapPicker value={{ latitude: 14.5995, longitude: 120.9842 }} onChange={vi.fn()} />);

    expect(screen.getByRole('searchbox', { name: 'Search address' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Search' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Use My Location' })).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Address map' })).toBeInTheDocument();
    expect(screen.getByText('14.599500, 120.984200')).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveAttribute('aria-live', 'polite');
    await waitFor(() => expect(leaflet.mapFactory).toHaveBeenCalled());
  });

  it('gives multiple pickers unique labeled search inputs', () => {
    render(<>
      <CustomerAddressMapPicker value={null} onChange={vi.fn()} />
      <CustomerAddressMapPicker value={null} onChange={vi.fn()} />
    </>);

    const inputs = screen.getAllByRole('searchbox', { name: 'Search address' });
    expect(inputs).toHaveLength(2);
    expect(inputs[0].id).toBeTruthy();
    expect(inputs[1].id).toBeTruthy();
    expect(inputs[0].id).not.toBe(inputs[1].id);
  });

  it('searches a Philippine address and disables only search while loading', async () => {
    let resolveFetch!: (value: Response) => void;
    const fetchMock = vi.fn(() => new Promise<Response>((resolve) => { resolveFetch = resolve; }));
    vi.stubGlobal('fetch', fetchMock);
    const onChange = vi.fn();
    render(<CustomerAddressMapPicker value={null} onChange={onChange} />);

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search address' }), { target: { value: 'Ermita Manila' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));

    expect(screen.getByRole('button', { name: 'Searching…' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Use My Location' })).toBeEnabled();
    await waitFor(() => expect(resolveFetch).toBeTypeOf('function'));
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/address/geocode?q=Ermita%20Manila',
      { signal: expect.any(AbortSignal) },
    );
    resolveFetch(response([addressResult]));

    await waitFor(() => expect(onChange).toHaveBeenCalledWith({
      barangay: 'Ermita',
      city: 'Manila',
      displayName: 'Ermita, Manila, Metro Manila, Philippines',
      latitude: 14.5995,
      longitude: 120.9842,
      postalCode: '1000',
      province: 'Metro Manila',
      region: 'NCR',
    }));
    expect(screen.getByRole('status')).toHaveTextContent(/address found/i);
  });

  it('does not submit the surrounding repair checkout form when embedded', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response([addressResult])));
    const checkoutSubmit = vi.fn((event: Event) => event.preventDefault());
    const onChange = vi.fn();
    const { container } = render(
      <form onSubmit={checkoutSubmit}>
        <CustomerAddressMapPicker embeddedInForm value={null} onChange={onChange} />
      </form>,
    );

    expect(container.querySelectorAll('form')).toHaveLength(1);
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search address' }), { target: { value: 'Ermita Manila' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));

    await waitFor(() => expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ city: 'Manila' })));
    expect(checkoutSubmit).not.toHaveBeenCalled();
  });

  it('can return coordinates from an incomplete Philippine lookup for repair checkout', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response([{
      address: {
        country_code: 'ph',
        province: 'Cavite',
        region: 'CALABARZON',
      },
      display_name: 'A location in Cavite, Philippines',
      lat: '14.309844',
      lon: '120.899874',
    }])));
    const onChange = vi.fn();
    render(<CustomerAddressMapPicker allowIncompleteAddress value={null} onChange={onChange} />);

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search address' }), { target: { value: 'Cavite' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));

    await waitFor(() => expect(onChange).toHaveBeenCalledWith({
      barangay: '',
      city: '',
      displayName: 'A location in Cavite, Philippines',
      latitude: 14.309844,
      longitude: 120.899874,
      postalCode: '',
      province: 'Cavite',
      region: 'CALABARZON',
    }));
    expect(screen.getByRole('status')).toHaveTextContent(/complete the address details/i);
  });

  it('keeps the incomplete-pin status when repair checkout echoes the coordinates', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response([{
      address: {
        country_code: 'ph',
        province: 'Cavite',
        region: 'CALABARZON',
      },
      display_name: 'A location in Cavite, Philippines',
      lat: '14.309844',
      lon: '120.899874',
    }])));
    const onChange = vi.fn();
    let rerender!: ReturnType<typeof render>['rerender'];
    onChange.mockImplementation((location) => rerender(
      <CustomerAddressMapPicker
        allowIncompleteAddress
        value={{ latitude: location.latitude, longitude: location.longitude }}
        onChange={onChange}
      />,
    ));
    ({ rerender } = render(<CustomerAddressMapPicker allowIncompleteAddress value={null} onChange={onChange} />));

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search address' }), { target: { value: 'Cavite' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));

    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent(/complete the address details/i));
  });

  it('keeps the controlled selection when the parent ignores a lookup result', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response([addressResult])));
    const onChange = vi.fn();
    render(
      <CustomerAddressMapPicker value={{ latitude: 10.3157, longitude: 123.8854 }} onChange={onChange} />,
    );
    await waitFor(() => expect(leaflet.markerFactory).toHaveBeenCalled());

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search address' }), { target: { value: 'Ermita Manila' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));

    await waitFor(() => expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ city: 'Manila' })));
    expect(screen.getByText('10.315700, 123.885400')).toBeInTheDocument();
    expect(leaflet.marker.setLatLng).toHaveBeenLastCalledWith([10.3157, 123.8854]);
    expect(screen.getByRole('status')).toHaveTextContent(/address found/i);
  });

  it('cancels a pending search when disabled', async () => {
    let resolveFetch!: (value: Response) => void;
    let requestSignal: AbortSignal | null = null;
    vi.stubGlobal('fetch', vi.fn((_input: RequestInfo | URL, init?: RequestInit) => {
      requestSignal = init?.signal ?? null;
      return new Promise<Response>((resolve) => { resolveFetch = resolve; });
    }));
    const onChange = vi.fn();
    const { rerender } = render(<CustomerAddressMapPicker value={null} onChange={onChange} />);

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search address' }), { target: { value: 'Ermita Manila' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));
    await waitFor(() => expect(requestSignal).not.toBeNull());
    rerender(<CustomerAddressMapPicker disabled value={null} onChange={onChange} />);

    expect(requestSignal?.aborted).toBe(true);
    expect(screen.queryByRole('button', { name: 'Searchingâ€¦' })).not.toBeInTheDocument();
    resolveFetch(response([addressResult]));
    await act(async () => Promise.resolve());
    expect(onChange).not.toHaveBeenCalled();
  });

  it('ignores a pending geolocation callback when disabled', async () => {
    let locate!: PositionCallback;
    const getCurrentPosition = vi.fn((success: PositionCallback) => { locate = success; });
    Object.defineProperty(navigator, 'geolocation', {
      configurable: true,
      value: { getCurrentPosition },
    });
    const fetchMock = vi.fn(() => new Promise<Response>(() => {}));
    vi.stubGlobal('fetch', fetchMock);
    const onChange = vi.fn();
    const { rerender } = render(<CustomerAddressMapPicker value={null} onChange={onChange} />);

    fireEvent.click(screen.getByRole('button', { name: 'Use My Location' }));
    rerender(<CustomerAddressMapPicker disabled value={null} onChange={onChange} />);
    act(() => locate({ coords: { latitude: 14.5995, longitude: 120.9842 } } as GeolocationPosition));

    expect(screen.queryByRole('button', { name: 'Locatingâ€¦' })).not.toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
    expect(onChange).not.toHaveBeenCalled();
  });

  it('cancels a pending dragged-pin lookup when disabled', async () => {
    let resolveFetch!: (value: Response) => void;
    let requestSignal: AbortSignal | null = null;
    vi.stubGlobal('fetch', vi.fn((_input: RequestInfo | URL, init?: RequestInit) => {
      requestSignal = init?.signal ?? null;
      return new Promise<Response>((resolve) => { resolveFetch = resolve; });
    }));
    const onChange = vi.fn();
    const { rerender } = render(
      <CustomerAddressMapPicker value={{ latitude: 10.3157, longitude: 123.8854 }} onChange={onChange} />,
    );
    await waitFor(() => expect(leaflet.marker.on).toHaveBeenCalledWith('dragend', expect.any(Function)));
    const dragStart = leaflet.marker.on.mock.calls.find(([event]) => event === 'dragstart')?.[1];
    const dragEnd = leaflet.marker.on.mock.calls.find(([event]) => event === 'dragend')?.[1];

    act(() => {
      leaflet.marker.getLatLng.mockReturnValue({ lat: 10.3157, lng: 123.8854 });
      dragStart();
      leaflet.marker.getLatLng.mockReturnValue({ lat: 14.5, lng: 121 });
      dragEnd();
    });
    await waitFor(() => expect(requestSignal).not.toBeNull());
    rerender(
      <CustomerAddressMapPicker disabled value={{ latitude: 10.3157, longitude: 123.8854 }} onChange={onChange} />,
    );

    expect(requestSignal?.aborted).toBe(true);
    expect(leaflet.marker.setLatLng).toHaveBeenLastCalledWith([10.3157, 123.8854]);
    resolveFetch(response(addressResult));
    await act(async () => Promise.resolve());
    expect(onChange).not.toHaveBeenCalled();
  });

  it('ignores a pending search after the controlled value is cleared', async () => {
    let resolveFetch!: (value: Response) => void;
    vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>((resolve) => { resolveFetch = resolve; })));
    const onChange = vi.fn();
    const { rerender } = render(
      <CustomerAddressMapPicker value={{ latitude: 10.3157, longitude: 123.8854 }} onChange={onChange} />,
    );
    await waitFor(() => expect(leaflet.markerFactory).toHaveBeenCalledTimes(1));

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search address' }), { target: { value: 'Ermita Manila' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));
    await waitFor(() => expect(resolveFetch).toBeTypeOf('function'));
    rerender(<CustomerAddressMapPicker value={null} onChange={onChange} />);
    resolveFetch(response([addressResult]));

    await act(async () => Promise.resolve());
    expect(onChange).not.toHaveBeenCalled();
    expect(screen.getByText('None selected')).toBeInTheDocument();
    expect(leaflet.markerFactory).toHaveBeenCalledTimes(1);
  });

  it('ignores a pending reverse lookup after the controlled value changes', async () => {
    let resolveFetch!: (value: Response) => void;
    vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>((resolve) => { resolveFetch = resolve; })));
    const onChange = vi.fn();
    const { rerender } = render(
      <CustomerAddressMapPicker value={{ latitude: 10.3157, longitude: 123.8854 }} onChange={onChange} />,
    );
    await waitFor(() => expect(leaflet.map.on).toHaveBeenCalledWith('click', expect.any(Function)));
    const click = leaflet.map.on.mock.calls.find(([event]) => event === 'click')?.[1];

    act(() => click({ latlng: { lat: 14.5995, lng: 120.9842 } }));
    await waitFor(() => expect(resolveFetch).toBeTypeOf('function'));
    rerender(
      <CustomerAddressMapPicker value={{ latitude: 11.2441, longitude: 125.0039 }} onChange={onChange} />,
    );
    resolveFetch(response(addressResult));

    await act(async () => Promise.resolve());
    expect(onChange).not.toHaveBeenCalled();
    expect(screen.getByText('11.244100, 125.003900')).toBeInTheDocument();
    expect(leaflet.marker.setLatLng).toHaveBeenLastCalledWith([11.2441, 125.0039]);
  });

  it('keeps a pending search when controlled coordinates are numerically unchanged', async () => {
    let resolveFetch!: (value: Response) => void;
    let requestSignal: AbortSignal | null = null;
    vi.stubGlobal('fetch', vi.fn((_input: RequestInfo | URL, init?: RequestInit) => {
      requestSignal = init?.signal ?? null;
      return new Promise<Response>((resolve) => { resolveFetch = resolve; });
    }));
    const onChange = vi.fn();
    const { rerender } = render(
      <CustomerAddressMapPicker value={{ latitude: 10.3157, longitude: 123.8854 }} onChange={onChange} />,
    );

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search address' }), { target: { value: 'Ermita Manila' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));
    await waitFor(() => expect(requestSignal).not.toBeNull());
    rerender(
      <CustomerAddressMapPicker value={{ latitude: 10.3157, longitude: 123.8854 }} onChange={onChange} />,
    );

    expect(requestSignal?.aborted).toBe(false);
    resolveFetch(response([addressResult]));
    await waitFor(() => expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ city: 'Manila' })));
    expect(screen.getByRole('button', { name: 'Search' })).toBeEnabled();
    expect(screen.getByRole('status')).toHaveTextContent(/address found/i);
  });

  it('finishes loading when the parent echoes a successful search result', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response([addressResult])));
    const onChange = vi.fn();
    let rerender!: ReturnType<typeof render>['rerender'];
    onChange.mockImplementation((location) => rerender(
      <CustomerAddressMapPicker
        value={{ latitude: location.latitude, longitude: location.longitude }}
        onChange={onChange}
      />,
    ));
    ({ rerender } = render(<CustomerAddressMapPicker value={null} onChange={onChange} />));

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search address' }), { target: { value: 'Ermita Manila' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));

    await waitFor(() => expect(screen.getByRole('button', { name: 'Search' })).toBeEnabled());
    expect(onChange).toHaveBeenCalledTimes(1);
    expect(screen.getByText('14.599500, 120.984200')).toBeInTheDocument();
  });

  it('uses GPS and reverse geocodes the location', async () => {
    const fetchMock = vi.fn().mockResolvedValue(response(addressResult));
    vi.stubGlobal('fetch', fetchMock);
    const getCurrentPosition = vi.fn((success: PositionCallback) => success({
      coords: { latitude: 14.5995, longitude: 120.9842 },
    } as GeolocationPosition));
    Object.defineProperty(navigator, 'geolocation', {
      configurable: true,
      value: { getCurrentPosition },
    });
    const onChange = vi.fn();
    render(<CustomerAddressMapPicker value={null} onChange={onChange} />);

    fireEvent.click(screen.getByRole('button', { name: 'Use My Location' }));

    await waitFor(() => expect(onChange).toHaveBeenCalledWith(expect.objectContaining({
      latitude: 14.5995,
      longitude: 120.9842,
    })));
    expect(fetchMock).toHaveBeenCalledWith(
      '/api/address/geocode?latitude=14.5995&longitude=120.9842',
      { signal: expect.any(AbortSignal) },
    );
    expect(getCurrentPosition).toHaveBeenCalledWith(expect.any(Function), expect.any(Function), {
      enableHighAccuracy: true,
      timeout: 15_000,
      maximumAge: 0,
    });
  });

  it('stops locating when the browser never returns a GPS callback', async () => {
    vi.useFakeTimers();
    const getCurrentPosition = vi.fn();
    Object.defineProperty(navigator, 'geolocation', {
      configurable: true,
      value: { getCurrentPosition },
    });
    render(<CustomerAddressMapPicker value={null} onChange={vi.fn()} />);

    fireEvent.click(screen.getByRole('button', { name: 'Use My Location' }));
    expect(screen.getByRole('button', { name: 'Locating…' })).toBeDisabled();

    await act(async () => {
      await vi.advanceTimersByTimeAsync(15_000);
    });

    expect(screen.getByRole('button', { name: 'Use My Location' })).toBeEnabled();
    expect(screen.getByRole('status')).toHaveTextContent(/could not get your location/i);
  });

  it('keeps the location action readable on the light address form', () => {
    render(<CustomerAddressMapPicker value={null} onChange={vi.fn()} />);

    expect(screen.getByRole('button', { name: 'Search' })).toHaveClass(
      'rounded-xl',
      'bg-blue-600',
      'hover:bg-blue-700',
    );
    expect(screen.getByRole('button', { name: 'Use My Location' })).toHaveClass(
      'border-slate-300',
      'bg-white',
      'text-slate-900',
    );
  });

  it('reverse geocodes map clicks', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response(addressResult)));
    const onChange = vi.fn();
    render(<CustomerAddressMapPicker value={null} onChange={onChange} />);
    await waitFor(() => expect(leaflet.map.on).toHaveBeenCalledWith('click', expect.any(Function)));
    const click = leaflet.map.on.mock.calls.find(([event]) => event === 'click')?.[1];

    await act(async () => click({ latlng: { lat: 14.5995, lng: 120.9842 } }));

    await waitFor(() => expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ city: 'Manila' })));
  });

  it.each([
    ['search', async () => {
      fireEvent.change(screen.getByRole('searchbox', { name: 'Search address' }), { target: { value: 'Nowhere' } });
      fireEvent.click(screen.getByRole('button', { name: 'Search' }));
    }],
    ['reverse lookup', async () => {
      await waitFor(() => expect(leaflet.map.on).toHaveBeenCalledWith('click', expect.any(Function)));
      const click = leaflet.map.on.mock.calls.find(([event]) => event === 'click')?.[1];
      click({ latlng: { lat: 14.5, lng: 121 } });
    }],
  ])('keeps the previous value when %s fails', async (_name, trigger) => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));
    const onChange = vi.fn();
    render(<CustomerAddressMapPicker value={{ latitude: 10.3157, longitude: 123.8854 }} onChange={onChange} />);

    await act(trigger);

    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent(/try again/i));
    expect(screen.getByText('10.315700, 123.885400')).toBeInTheDocument();
    expect(onChange).not.toHaveBeenCalled();
  });

  it.each([
    ['search', async () => {
      fireEvent.change(screen.getByRole('searchbox', { name: 'Search address' }), { target: { value: 'Manila' } });
      fireEvent.click(screen.getByRole('button', { name: 'Search' }));
    }],
    ['reverse lookup', async () => {
      await waitFor(() => expect(leaflet.map.on).toHaveBeenCalledWith('click', expect.any(Function)));
      const click = leaflet.map.on.mock.calls.find(([event]) => event === 'click')?.[1];
      click({ latlng: { lat: 14.5, lng: 121 } });
    }],
  ])('shows the busy message without retrying when %s receives 429', async (_name, trigger) => {
    const fetchMock = vi.fn().mockResolvedValue(response(
      { message: 'Address lookup is busy. Please try again shortly.', retry_after: 1 },
      false,
      429,
      new Headers({ 'Retry-After': '1' }),
    ));
    vi.stubGlobal('fetch', fetchMock);
    render(<CustomerAddressMapPicker value={null} onChange={vi.fn()} />);

    await act(trigger);

    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent(
      'Address lookup is busy. Please try again shortly.',
    ));
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('restores the previous pin when reverse geocoding a drag fails', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));
    render(<CustomerAddressMapPicker value={{ latitude: 10.3157, longitude: 123.8854 }} onChange={vi.fn()} />);
    await waitFor(() => {
      expect(leaflet.marker.on).toHaveBeenCalledWith('dragstart', expect.any(Function));
      expect(leaflet.marker.on).toHaveBeenCalledWith('dragend', expect.any(Function));
    });
    const dragStart = leaflet.marker.on.mock.calls.find(([event]) => event === 'dragstart')?.[1];
    const dragEnd = leaflet.marker.on.mock.calls.find(([event]) => event === 'dragend')?.[1];

    await act(async () => {
      leaflet.marker.getLatLng.mockReturnValue({ lat: 10.3157, lng: 123.8854 });
      dragStart();
      leaflet.marker.getLatLng.mockReturnValue({ lat: 14.5, lng: 121 });
      dragEnd();
    });

    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent(/try again/i));
    expect(leaflet.marker.setLatLng).toHaveBeenLastCalledWith([10.3157, 123.8854]);
  });

  it('aborts the first reverse lookup when a second drag starts', async () => {
    const signals: AbortSignal[] = [];
    vi.stubGlobal('fetch', vi.fn((_input: RequestInfo | URL, init?: RequestInit) => {
      signals.push(init?.signal as AbortSignal);
      return new Promise<Response>(() => {});
    }));
    render(
      <CustomerAddressMapPicker value={{ latitude: 10.3157, longitude: 123.8854 }} onChange={vi.fn()} />,
    );
    await waitFor(() => expect(leaflet.marker.on).toHaveBeenCalledWith('dragend', expect.any(Function)));
    const dragStart = leaflet.marker.on.mock.calls.find(([event]) => event === 'dragstart')?.[1];
    const dragEnd = leaflet.marker.on.mock.calls.find(([event]) => event === 'dragend')?.[1];

    act(() => {
      leaflet.marker.getLatLng.mockReturnValue({ lat: 10.3157, lng: 123.8854 });
      dragStart();
      leaflet.marker.getLatLng.mockReturnValue({ lat: 14.5, lng: 121 });
      dragEnd();
    });
    await waitFor(() => expect(signals).toHaveLength(1));
    act(() => {
      leaflet.marker.getLatLng.mockReturnValue({ lat: 10.3157, lng: 123.8854 });
      dragStart();
    });

    expect(signals[0].aborted).toBe(true);
    expect(leaflet.marker.setLatLng).toHaveBeenLastCalledWith([10.3157, 123.8854]);
  });

  it('disables Leaflet marker and map interactions when initially disabled', async () => {
    render(<CustomerAddressMapPicker disabled value={{ latitude: 14.5995, longitude: 120.9842 }} onChange={vi.fn()} />);

    await waitFor(() => expect(leaflet.mapFactory).toHaveBeenCalledWith(
      expect.any(HTMLElement),
      { zoomControl: true },
    ));
    await waitFor(() => expect(leaflet.markerFactory).toHaveBeenCalledWith(
      [14.5995, 120.9842],
      { autoPanOnFocus: false, draggable: false, keyboard: false },
    ));
    for (const handler of ['boxZoom', 'doubleClickZoom', 'dragging', 'keyboard', 'scrollWheelZoom', 'touchZoom'] as const) {
      expect(leaflet.map[handler].disable).toHaveBeenCalled();
    }
    expect(leaflet.marker.dragging.disable).toHaveBeenCalled();
    expect(leaflet.map.zoomControl.remove).toHaveBeenCalled();
    expect(screen.getByRole('region', { name: 'Address map' })).toHaveClass('pointer-events-none');
  });

  it('updates Leaflet interactions when disabled changes', async () => {
    const { rerender } = render(
      <CustomerAddressMapPicker value={{ latitude: 14.5995, longitude: 120.9842 }} onChange={vi.fn()} />,
    );
    await waitFor(() => expect(leaflet.markerFactory).toHaveBeenCalled());

    rerender(<CustomerAddressMapPicker disabled value={{ latitude: 14.5995, longitude: 120.9842 }} onChange={vi.fn()} />);
    await waitFor(() => expect(leaflet.map.keyboard.disable).toHaveBeenCalled());
    expect(leaflet.marker.dragging.disable).toHaveBeenCalled();
    expect(leaflet.map.zoomControl.remove).toHaveBeenCalled();
    expect(leaflet.markerFactory).toHaveBeenLastCalledWith(
      [14.5995, 120.9842],
      { autoPanOnFocus: false, draggable: false, keyboard: false },
    );

    rerender(<CustomerAddressMapPicker value={{ latitude: 14.5995, longitude: 120.9842 }} onChange={vi.fn()} />);
    await waitFor(() => expect(leaflet.map.keyboard.enable).toHaveBeenCalled());
    expect(leaflet.marker.dragging.enable).toHaveBeenCalled();
    expect(leaflet.map.zoomControl.addTo).toHaveBeenCalledWith(leaflet.map);
    expect(leaflet.markerFactory).toHaveBeenLastCalledWith(
      [14.5995, 120.9842],
      { autoPanOnFocus: true, draggable: true, keyboard: true },
    );
  });

  it('keeps status disabled-aware when the controlled value changes while disabled', () => {
    const { rerender } = render(
      <CustomerAddressMapPicker disabled value={{ latitude: 10.3157, longitude: 123.8854 }} onChange={vi.fn()} />,
    );

    rerender(
      <CustomerAddressMapPicker disabled value={{ latitude: 14.5995, longitude: 120.9842 }} onChange={vi.fn()} />,
    );

    expect(screen.getByRole('status')).toHaveTextContent(
      'Address selection is disabled. Selected coordinates shown.',
    );
    expect(screen.getByText('14.599500, 120.984200')).toBeInTheDocument();
  });

  it('removes the marker when the controlled value becomes null', async () => {
    const { rerender } = render(
      <CustomerAddressMapPicker value={{ latitude: 14.5995, longitude: 120.9842 }} onChange={vi.fn()} />,
    );
    await waitFor(() => expect(leaflet.markerFactory).toHaveBeenCalled());

    rerender(<CustomerAddressMapPicker value={null} onChange={vi.fn()} />);

    await waitFor(() => expect(leaflet.marker.remove).toHaveBeenCalled());
    expect(screen.getByText('None selected')).toBeInTheDocument();
  });

  it('does not create a marker until a location is selected', async () => {
    render(<CustomerAddressMapPicker value={null} onChange={vi.fn()} />);

    await waitFor(() => expect(leaflet.mapFactory).toHaveBeenCalled());
    expect(leaflet.markerFactory).not.toHaveBeenCalled();
  });

  it('uses the latest value and disabled state when Leaflet finishes loading', async () => {
    const { rerender } = render(
      <CustomerAddressMapPicker value={{ latitude: 10.3157, longitude: 123.8854 }} onChange={vi.fn()} />,
    );
    rerender(
      <CustomerAddressMapPicker disabled value={{ latitude: 14.5995, longitude: 120.9842 }} onChange={vi.fn()} />,
    );

    await waitFor(() => expect(leaflet.markerFactory).toHaveBeenCalledWith(
      [14.5995, 120.9842],
      { autoPanOnFocus: false, draggable: false, keyboard: false },
    ));
    expect(leaflet.map.setView).not.toHaveBeenCalledWith([10.3157, 123.8854], 16);
  });

  it('gives Leaflet zoom controls 44px touch targets', () => {
    render(<CustomerAddressMapPicker value={null} onChange={vi.fn()} />);

    expect(screen.getByRole('region', { name: 'Address map' })).toHaveClass(
      '[&_.leaflet-control-zoom_a]:!h-11',
      '[&_.leaflet-control-zoom_a]:!w-11',
    );
  });

  it('uses the policy-compliant OpenStreetMap tile endpoint', async () => {
    render(<CustomerAddressMapPicker value={null} onChange={vi.fn()} />);

    await waitFor(() => expect(leaflet.tileLayer).toHaveBeenCalledWith(
      'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
      { attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' },
    ));
  });

  it('leaves concurrent request limiting to the server', async () => {
    const fetchMock = vi.fn().mockResolvedValue(response([addressResult]));
    vi.stubGlobal('fetch', fetchMock);
    render(<>
      <CustomerAddressMapPicker value={null} onChange={vi.fn()} />
      <CustomerAddressMapPicker value={null} onChange={vi.fn()} />
    </>);
    const inputs = screen.getAllByPlaceholderText('Street, barangay, city');
    const buttons = screen.getAllByRole('button', { name: 'Search' });

    fireEvent.change(inputs[0], { target: { value: 'First Manila' } });
    fireEvent.change(inputs[1], { target: { value: 'Second Manila' } });
    fireEvent.click(buttons[0]);
    fireEvent.click(buttons[1]);

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2), { timeout: 250 });
    expect(fetchMock).toHaveBeenNthCalledWith(1,
      '/api/address/geocode?q=First%20Manila',
      { signal: expect.any(AbortSignal) },
    );
    expect(fetchMock).toHaveBeenNthCalledWith(2,
      '/api/address/geocode?q=Second%20Manila',
      { signal: expect.any(AbortSignal) },
    );
  });

  it('leaves repeated request caching to the server', async () => {
    const fetchMock = vi.fn().mockResolvedValue(response([addressResult]));
    vi.stubGlobal('fetch', fetchMock);
    const onChange = vi.fn();
    render(<CustomerAddressMapPicker value={null} onChange={onChange} />);
    const input = screen.getByRole('searchbox', { name: 'Search address' });

    fireEvent.change(input, { target: { value: 'Cached Manila' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));
    await waitFor(() => expect(onChange).toHaveBeenCalledTimes(1));
    fireEvent.change(input, { target: { value: 'Cached Manila' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));
    await waitFor(() => expect(onChange).toHaveBeenCalledTimes(2));

    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock).toHaveBeenLastCalledWith(
      '/api/address/geocode?q=Cached%20Manila',
      { signal: expect.any(AbortSignal) },
    );
  });

  it('keeps a real zoom control available when re-enabled from initially disabled', async () => {
    const { rerender } = render(
      <CustomerAddressMapPicker disabled value={null} onChange={vi.fn()} />,
    );
    await waitFor(() => expect(leaflet.map.zoomControl.remove).toHaveBeenCalled());

    expect(() => rerender(<CustomerAddressMapPicker value={null} onChange={vi.fn()} />)).not.toThrow();
    await waitFor(() => expect(leaflet.map.zoomControl.addTo).toHaveBeenCalledWith(leaflet.map));
  });

  it('restores a dragged pin before a failing search supersedes its lookup', async () => {
    vi.stubGlobal('fetch', vi.fn()
      .mockImplementationOnce(() => new Promise<Response>(() => {}))
      .mockRejectedValueOnce(new Error('offline')));
    render(<CustomerAddressMapPicker value={{ latitude: 10.3157, longitude: 123.8854 }} onChange={vi.fn()} />);
    await waitFor(() => expect(leaflet.marker.on).toHaveBeenCalledWith('dragend', expect.any(Function)));
    const dragStart = leaflet.marker.on.mock.calls.find(([event]) => event === 'dragstart')?.[1];
    const dragEnd = leaflet.marker.on.mock.calls.find(([event]) => event === 'dragend')?.[1];

    await act(async () => {
      leaflet.marker.getLatLng.mockReturnValue({ lat: 10.3157, lng: 123.8854 });
      dragStart();
      leaflet.marker.getLatLng.mockReturnValue({ lat: 14.5, lng: 121 });
      dragEnd();
    });
    fireEvent.change(screen.getByRole('searchbox', { name: 'Search address' }), { target: { value: 'Nowhere' } });
    fireEvent.click(screen.getByRole('button', { name: 'Search' }));

    expect(leaflet.marker.setLatLng).toHaveBeenLastCalledWith([10.3157, 123.8854]);
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent(/try again/i));
  });

  it('updates status when the controlled value is externally cleared', async () => {
    const { rerender } = render(
      <CustomerAddressMapPicker value={{ latitude: 14.5995, longitude: 120.9842 }} onChange={vi.fn()} />,
    );

    rerender(<CustomerAddressMapPicker value={null} onChange={vi.fn()} />);

    expect(screen.getByRole('status')).toHaveTextContent(/search for an address or choose a point/i);
    expect(screen.getByRole('status')).not.toHaveTextContent('Address selected.');
  });
});
