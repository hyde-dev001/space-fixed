import { FormEvent, useEffect, useId, useRef, useState } from 'react';
import 'leaflet/dist/leaflet.css';
import {
  parsePhilippineAddress,
  type RegistrationAddress,
} from '../../Pages/UserSide/Auth/registrationAddress';
import { GPS_POSITION_OPTIONS, getCurrentPositionWithTimeout } from '../../utils/geolocation';

export type CoordinateValue = { latitude: number; longitude: number } | null;

export type CustomerAddressMapPickerProps = {
  value: CoordinateValue;
  onChange: (location: RegistrationAddress) => void;
  disabled?: boolean;
};

const PHILIPPINES_CENTER: [number, number] = [12.8797, 121.774];
const EMPTY_STATUS = 'Search for an address or choose a point on the map.';
type GeocodeRequestError = Error & { status?: number; retryAfter?: string | null };

const requestGeocode = async <T,>(url: string, signal: AbortSignal): Promise<T> => {
  const response = await fetch(url, { signal });
  if (!response.ok) {
    const body = await response.json().catch(() => null) as { error?: unknown; message?: unknown } | null;
    const detail = body && [body.message, body.error]
      .find((value) => typeof value === 'string' && value.length <= 200);
    throw Object.assign(
      new Error(typeof detail === 'string' ? detail : 'Address geocoding request failed'),
      { status: response.status, retryAfter: response.headers.get('Retry-After') },
    ) as GeocodeRequestError;
  }

  return response.json() as Promise<T>;
};

const sameCoordinates = (left: CoordinateValue, right: CoordinateValue) => (
  left === right
  || Boolean(left && right && left.latitude === right.latitude && left.longitude === right.longitude)
);

export default function CustomerAddressMapPicker({
  value,
  onChange,
  disabled = false,
}: CustomerAddressMapPickerProps) {
  const searchId = useId();
  const mapElementRef = useRef<HTMLDivElement>(null);
  const leafletRef = useRef<typeof import('leaflet') | null>(null);
  const mapRef = useRef<import('leaflet').Map | null>(null);
  const zoomControlRef = useRef<import('leaflet').Control.Zoom | null>(null);
  const markerRef = useRef<import('leaflet').Marker | null>(null);
  const selectedRef = useRef<CoordinateValue>(value);
  const proposedRef = useRef<CoordinateValue>(null);
  const dragOriginRef = useRef<CoordinateValue>(value);
  const pendingRollbackRef = useRef<[number, number] | null>(null);
  const mountedRef = useRef(true);
  const disabledRef = useRef(disabled);
  const onChangeRef = useRef(onChange);
  const requestRef = useRef(0);
  const abortRef = useRef<AbortController | null>(null);
  const [query, setQuery] = useState('');
  const [selected, setSelected] = useState<CoordinateValue>(value);
  const [searching, setSearching] = useState(false);
  const [locating, setLocating] = useState(false);
  const [status, setStatus] = useState(value ? 'Selected coordinates shown.' : 'Search for an address or choose a point on the map.');

  disabledRef.current = disabled;
  onChangeRef.current = onChange;

  const restorePendingRollback = () => {
    if (pendingRollbackRef.current) markerRef.current?.setLatLng(pendingRollbackRef.current);
    pendingRollbackRef.current = null;
  };

  const cancelPendingRequest = () => {
    requestRef.current += 1;
    abortRef.current?.abort();
    abortRef.current = null;
    restorePendingRollback();
    setSearching(false);
    setLocating(false);
  };

  const syncInteractions = (isDisabled: boolean, updateZoomControl = true) => {
    const map = mapRef.current;
    if (!map) return;
    const method = isDisabled ? 'disable' : 'enable';
    [map.boxZoom, map.doubleClickZoom, map.dragging, map.keyboard, map.scrollWheelZoom, map.touchZoom]
      .forEach((handler) => handler[method]());
    markerRef.current?.dragging?.[method]();
    if (updateZoomControl) {
      if (isDisabled) zoomControlRef.current?.remove();
      else zoomControlRef.current?.addTo(map);
    }
  };

  const ensureMarker = (coordinates: { latitude: number; longitude: number }) => {
    const point: [number, number] = [coordinates.latitude, coordinates.longitude];
    if (markerRef.current) {
      markerRef.current.setLatLng(point);
      markerRef.current.dragging?.[disabledRef.current ? 'disable' : 'enable']();
      return;
    }
    if (!leafletRef.current || !mapRef.current) return;

    const marker = leafletRef.current.marker(point, {
      autoPanOnFocus: !disabledRef.current,
      draggable: !disabledRef.current,
      keyboard: !disabledRef.current,
    }).addTo(mapRef.current);
    marker.on('dragstart', () => {
      cancelPendingRequest();
      const prior = marker.getLatLng();
      dragOriginRef.current = { latitude: prior.lat, longitude: prior.lng };
    });
    marker.on('dragend', () => {
      const next = marker.getLatLng();
      const prior = dragOriginRef.current;
      chooseMapPoint(next.lat, next.lng, prior ? [prior.latitude, prior.longitude] : null);
    });
    markerRef.current = marker;
    marker.dragging?.[disabledRef.current ? 'disable' : 'enable']();
  };

  const showControlledSelection = () => {
    const coordinates = selectedRef.current;
    setSelected(coordinates);
    if (!coordinates) {
      markerRef.current?.remove();
      markerRef.current = null;
      return;
    }
    mapRef.current?.setView([coordinates.latitude, coordinates.longitude], 16);
    ensureMarker(coordinates);
  };

  const applyResult = (result: unknown) => {
    const location = parsePhilippineAddress(result);
    if (!location) return false;

    const coordinates = { latitude: location.latitude, longitude: location.longitude };
    pendingRollbackRef.current = null;
    proposedRef.current = coordinates;
    setQuery(location.displayName);
    setStatus(sameCoordinates(selectedRef.current, coordinates)
      ? 'Address selected.'
      : 'Address found. Waiting for confirmation.');
    onChangeRef.current(location);
    showControlledSelection();
    return true;
  };

  const reverseGeocode = async (
    latitude: number,
    longitude: number,
    requestId: number,
    source: 'gps' | 'map',
  ) => {
    abortRef.current = new AbortController();
    try {
      const result = await requestGeocode<unknown>(
        `/api/address/geocode?latitude=${latitude}&longitude=${longitude}`,
        abortRef.current.signal,
      );
      if (!mountedRef.current || requestId !== requestRef.current) return;
      if (!applyResult(result)) {
        restorePendingRollback();
        setStatus('Choose a complete Philippine address and try again.');
      }
    } catch (error) {
      if (mountedRef.current && requestId === requestRef.current && (error as Error).name !== 'AbortError') {
        restorePendingRollback();
        setStatus((error as GeocodeRequestError).status === 429
          ? 'Address lookup is busy. Please try again shortly.'
          : source === 'gps'
            ? 'Could not identify your location. Please try searching instead.'
            : 'Could not identify this location. Please try again.');
      }
    } finally {
      if (mountedRef.current && source === 'gps' && requestId === requestRef.current) setLocating(false);
    }
  };

  const chooseMapPoint = (latitude: number, longitude: number, rollback: [number, number] | null = null) => {
    if (disabledRef.current) return;
    restorePendingRollback();
    abortRef.current?.abort();
    pendingRollbackRef.current = rollback;
    const requestId = ++requestRef.current;
    setSearching(false);
    setLocating(false);
    setStatus('Finding the address for this point…');
    void reverseGeocode(latitude, longitude, requestId, 'map');
  };

  useEffect(() => {
    cancelPendingRequest();
    const acceptedProposal = sameCoordinates(value, proposedRef.current);
    proposedRef.current = null;
    selectedRef.current = value;
    showControlledSelection();
    setStatus(disabledRef.current
      ? (value ? 'Address selection is disabled. Selected coordinates shown.' : 'Address selection is disabled.')
      : value
        ? (acceptedProposal ? 'Address selected.' : 'Selected coordinates shown.')
        : EMPTY_STATUS);
  }, [value?.latitude, value?.longitude]);

  useEffect(() => {
    mountedRef.current = true;
    const element = mapElementRef.current;
    let active = true;
    let resizeTimer: number | undefined;

    if (element) {
      import('leaflet').then((L) => {
        if (!active || !mountedRef.current) return;
        leafletRef.current = L;

        delete (L.Icon.Default.prototype as { _getIconUrl?: unknown })._getIconUrl;
        L.Icon.Default.mergeOptions({
          iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
          iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
          shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
        });

        const latest = selectedRef.current;
        const initial: [number, number] = latest
          ? [latest.latitude, latest.longitude]
          : PHILIPPINES_CENTER;
        const map = L.map(element, { zoomControl: true }).setView(initial, latest ? 16 : 5);
        zoomControlRef.current = map.zoomControl;
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
          attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        }).addTo(map);
        map.on('click', (event: import('leaflet').LeafletMouseEvent) => {
          chooseMapPoint(event.latlng.lat, event.latlng.lng);
        });
        mapRef.current = map;
        if (latest) ensureMarker(latest);
        syncInteractions(disabledRef.current);
        resizeTimer = window.setTimeout(() => map.invalidateSize(), 0);
      }).catch(() => {
        if (active && mountedRef.current) setStatus('The map could not load. Please try again.');
      });
    }

    return () => {
      active = false;
      mountedRef.current = false;
      requestRef.current += 1;
      abortRef.current?.abort();
      if (resizeTimer !== undefined) window.clearTimeout(resizeTimer);
      mapRef.current?.remove();
      leafletRef.current = null;
      mapRef.current = null;
      zoomControlRef.current = null;
      markerRef.current = null;
    };
  }, []);

  useEffect(() => {
    if (disabled) cancelPendingRequest();
    setStatus(selectedRef.current
      ? (disabled ? 'Address selection is disabled. Selected coordinates shown.' : 'Selected coordinates shown.')
      : (disabled ? 'Address selection is disabled.' : EMPTY_STATUS));
    const coordinates = selectedRef.current;
    if (markerRef.current && coordinates) {
      markerRef.current.remove();
      markerRef.current = null;
      ensureMarker(coordinates);
    }
    syncInteractions(disabled);
  }, [disabled]);

  const search = async (event: FormEvent) => {
    event.preventDefault();
    const address = query.trim();
    if (!address) {
      setStatus('Enter an address to search.');
      return;
    }

    restorePendingRollback();
    abortRef.current?.abort();
    abortRef.current = new AbortController();
    const requestId = ++requestRef.current;
    setLocating(false);
    setSearching(true);
    setStatus('Searching for that address…');
    try {
      const [result] = await requestGeocode<unknown[]>(
        `/api/address/geocode?q=${encodeURIComponent(address)}`,
        abortRef.current.signal,
      );
      if (!mountedRef.current || requestId !== requestRef.current) return;
      if (!result) setStatus('No Philippine address found. Try a more specific search.');
      else if (!applyResult(result)) setStatus('Choose a complete Philippine address and try again.');
    } catch (error) {
      if (mountedRef.current && requestId === requestRef.current && (error as Error).name !== 'AbortError') {
        setStatus((error as GeocodeRequestError).status === 429
          ? 'Address lookup is busy. Please try again shortly.'
          : 'Address search is unavailable. Please try again.');
      }
    } finally {
      if (mountedRef.current && requestId === requestRef.current) setSearching(false);
    }
  };

  const useMyLocation = () => {
    if (!navigator.geolocation) {
      setStatus('Location access is not supported by this browser. Try searching instead.');
      return;
    }

    restorePendingRollback();
    abortRef.current?.abort();
    const requestId = ++requestRef.current;
    setSearching(false);
    setLocating(true);
    setStatus('Getting your location…');
    void getCurrentPositionWithTimeout(GPS_POSITION_OPTIONS)
      .then(({ coords }) => {
        if (!mountedRef.current || requestId !== requestRef.current) return;
        setLocating(false);
        void reverseGeocode(coords.latitude, coords.longitude, requestId, 'gps');
      })
      .catch(() => {
        if (mountedRef.current && requestId === requestRef.current) {
          setLocating(false);
          setStatus('Could not get your location. Allow location access and try again.');
        }
      });
  };

  return (
    <div className="space-y-3">
      <form className="space-y-2 sm:flex sm:items-end sm:gap-2 sm:space-y-0" onSubmit={search}>
        <div className="min-w-0 flex-1">
          <label className="mb-1 block text-sm font-medium text-gray-700" htmlFor={searchId}>
            Search address
          </label>
          <input
            className="min-h-11 w-full rounded-lg border border-gray-300 px-3 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
            disabled={disabled}
            id={searchId}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="Street, barangay, city"
            type="search"
            value={query}
          />
        </div>
        <button
          className="min-h-11 rounded-xl bg-blue-600 px-4 text-white transition-colors hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
          disabled={disabled || searching}
          type="submit"
        >
          {searching ? 'Searching…' : 'Search'}
        </button>
        <button
          className="min-h-11 rounded-lg border border-slate-300 bg-white px-4 font-medium text-slate-900 shadow-sm transition-colors hover:border-slate-400 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500 disabled:opacity-100"
          disabled={disabled || locating}
          onClick={useMyLocation}
          type="button"
        >
          {locating ? 'Locating…' : 'Use My Location'}
        </button>
      </form>

      <div
        aria-disabled={disabled || undefined}
        aria-label="Address map"
        className={`h-64 w-full rounded-lg border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 sm:h-72 [&_.leaflet-control-zoom_a]:!h-11 [&_.leaflet-control-zoom_a]:!w-11 ${disabled ? 'pointer-events-none' : ''}`}
        ref={mapElementRef}
        role="region"
        tabIndex={disabled ? -1 : 0}
      />

      <p className="text-sm text-gray-700">
        Selected coordinates:{' '}
        <span>{selected ? `${selected.latitude.toFixed(6)}, ${selected.longitude.toFixed(6)}` : 'None selected'}</span>
      </p>
      <p aria-atomic="true" aria-live="polite" className="min-h-5 text-sm text-gray-700" role="status">
        {status}
      </p>
    </div>
  );
}
