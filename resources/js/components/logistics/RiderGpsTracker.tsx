import { logisticsApi } from '@/services/logisticsApi';
import { GPS_POSITION_OPTIONS, getCurrentPositionWithFallback } from '@/utils/geolocation';
import type { DeliveryContactSnapshot, LiveTrackingRoute } from '@/types/logistics';
import { useEffect, useRef, useState } from 'react';
import LiveTrackingMap, { type LiveRiderLocation } from './LiveTrackingMap';

type Props = {
  legId: number;
  enabled: boolean;
  online: boolean;
  destination?: Pick<DeliveryContactSnapshot, 'type' | 'name' | 'address' | 'latitude' | 'longitude'> | null;
  movingIntervalSeconds?: number;
  stationaryIntervalSeconds?: number;
  hiddenIntervalSeconds?: number;
};

type Coordinate = {
  latitude: number;
  longitude: number;
};

type SentPosition = Coordinate & {
  timestamp: number;
  accuracy_m: number | null;
  speed_mps: number | null;
  heading_deg: number | null;
  source: 'browser' | 'public_ip';
};

const distanceMeters = (from: Coordinate, to: Coordinate): number => {
  const earthRadiusMeters = 6_371_000;
  const latitudeDelta = (to.latitude - from.latitude) * Math.PI / 180;
  const longitudeDelta = (to.longitude - from.longitude) * Math.PI / 180;
  const fromLatitude = from.latitude * Math.PI / 180;
  const toLatitude = to.latitude * Math.PI / 180;
  const a = Math.sin(latitudeDelta / 2) ** 2
    + Math.cos(fromLatitude) * Math.cos(toLatitude) * Math.sin(longitudeDelta / 2) ** 2;

  return earthRadiusMeters * 2 * Math.asin(Math.min(1, Math.sqrt(a)));
};

type LocationRequestError = {
  code?: number;
  response?: {
    status?: number;
    data?: {
      message?: unknown;
      errors?: Record<string, unknown>;
    };
  };
};

class PublicIpLocationError extends Error {
  constructor(message = 'Unable to read a usable desktop network location.') {
    super(message);
    this.name = 'PublicIpLocationError';
  }
}

const locationErrorMessage = (error: unknown): string => {
  if (error instanceof PublicIpLocationError) return error.message;

  const details = error as LocationRequestError | null;
  const response = details?.response;
  const validationErrors = response?.data?.errors;

  if (validationErrors) {
    for (const value of Object.values(validationErrors)) {
      const message = Array.isArray(value) ? value[0] : value;
      if (typeof message === 'string') return message;
    }
  }

  if (response?.status === 422 && typeof response.data?.message === 'string') {
    return response.data.message;
  }

  if (details?.code === 1) return 'Location permission is required to start tracking.';
  if (details?.code === 2) return 'Your location is currently unavailable.';
  if (details?.code === 3) return 'The location request timed out. We will try again.';

  return 'Unable to read your location. We will try again.';
};

const ETA_SPEED_MPS = 8.33;
const MAX_TRACKING_ACCURACY_M = 50_000;
const MAX_USABLE_ACCURACY_M = 1_000;
const MAX_CLIENT_IMPLIED_SPEED_MPS = 100;
const PUBLIC_IP_LOCATION_URL = 'https://ipapi.co/json/';
const PUBLIC_IP_REQUEST_TIMEOUT_MS = 5_000;

const trackingStorageKey = (legId: number): string => `solespace:rider-gps:${legId}`;

const readTrackingPreference = (key: string): boolean => {
  try {
    return typeof window !== 'undefined' && window.sessionStorage.getItem(key) === '1';
  } catch {
    return false;
  }
};

const writeTrackingPreference = (key: string, enabled: boolean): void => {
  try {
    if (enabled) window.sessionStorage.setItem(key, '1');
    else window.sessionStorage.removeItem(key);
  } catch {
    // Session storage may be disabled; tracking still works for this page.
  }
};

const toSentPosition = (position: GeolocationPosition): SentPosition => ({
  latitude: position.coords.latitude,
  longitude: position.coords.longitude,
  timestamp: Number.isFinite(position.timestamp) ? position.timestamp : Date.now(),
  accuracy_m: Number.isFinite(position.coords.accuracy) ? position.coords.accuracy : null,
  speed_mps: position.coords.speed !== null && Number.isFinite(position.coords.speed)
    ? position.coords.speed
    : null,
  heading_deg: position.coords.heading !== null && Number.isFinite(position.coords.heading)
    ? position.coords.heading
    : null,
  source: 'browser',
});


const finiteCoordinate = (value: unknown): number | null => {
  if (value === null || value === undefined || value === '') return null;
  const coordinate = Number(value);
  return Number.isFinite(coordinate) ? coordinate : null;
};
const hasValidCoordinates = (position: Coordinate): boolean => (
  Number.isFinite(position.latitude)
  && position.latitude >= -90
  && position.latitude <= 90
  && Number.isFinite(position.longitude)
  && position.longitude >= -180
  && position.longitude <= 180
);
const isUsableBrowserPosition = (position: SentPosition): boolean => (
  hasValidCoordinates(position)
  && (position.accuracy_m === null
    || (position.accuracy_m >= 0 && position.accuracy_m <= MAX_USABLE_ACCURACY_M))
);
const getPublicIpPosition = async (timestamp?: number): Promise<SentPosition> => {
  const controller = new AbortController();
  const timeoutId = window.setTimeout(() => controller.abort(), PUBLIC_IP_REQUEST_TIMEOUT_MS);

  try {
    const response = await fetch(PUBLIC_IP_LOCATION_URL, {
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    });

    if (!response.ok) throw new PublicIpLocationError();

    const body = await response.json() as { latitude?: unknown; longitude?: unknown };
    const latitude = finiteCoordinate(body.latitude);
    const longitude = finiteCoordinate(body.longitude);

    if (
      latitude === null
      || longitude === null
      || !hasValidCoordinates({ latitude, longitude })
    ) {
      throw new PublicIpLocationError();
    }

    return {
      latitude,
      longitude,
      timestamp: timestamp !== undefined && Number.isFinite(timestamp) ? timestamp : Date.now(),
      accuracy_m: null,
      speed_mps: null,
      heading_deg: null,
      source: 'public_ip',
    };
  } catch (error) {
    if (error instanceof PublicIpLocationError) throw error;
    throw new PublicIpLocationError();
  } finally {
    window.clearTimeout(timeoutId);
  }
};

const destinationCoordinates = (
  destination?: Pick<DeliveryContactSnapshot, 'latitude' | 'longitude'> | null,
): Coordinate | null => {
  const latitude = finiteCoordinate(destination?.latitude);
  const longitude = finiteCoordinate(destination?.longitude);

  return latitude !== null && longitude !== null ? { latitude, longitude } : null;
};

const formatDistance = (meters: number): string => meters >= 1000
  ? `${(meters / 1000).toFixed(1)} km`
  : `${Math.round(meters)} m`;

const isLiveTrackingRoute = (value: unknown): value is LiveTrackingRoute => {
  if (!value || typeof value !== 'object') return false;
  const route = value as { distance_m?: unknown; duration_s?: unknown; geometry?: unknown };
  const geometry = route.geometry;

  return typeof route.distance_m === 'number'
    && Number.isFinite(route.distance_m)
    && typeof route.duration_s === 'number'
    && Number.isFinite(route.duration_s)
    && Array.isArray(geometry)
    && geometry.length >= 2
    && geometry.every((point: unknown) => Array.isArray(point)
      && typeof point[0] === 'number'
      && Number.isFinite(point[0])
      && typeof point[1] === 'number'
      && Number.isFinite(point[1]));
};
const isPermissionDenied = (error: unknown): boolean => (
  typeof error === 'object'
  && error !== null
  && (error as { code?: unknown }).code === 1
);

const resolveTrackingPosition = async (): Promise<SentPosition> => {
  let browserPosition: SentPosition;

  try {
    browserPosition = toSentPosition(await getCurrentPositionWithFallback(GPS_POSITION_OPTIONS));
  } catch (error) {
    if (isPermissionDenied(error)) throw error;
    return getPublicIpPosition();
  }

  if (isUsableBrowserPosition(browserPosition)) return browserPosition;


  return getPublicIpPosition(browserPosition.timestamp);
};
export default function RiderGpsTracker({
  legId,
  enabled,
  online,
  destination,
  movingIntervalSeconds = 5,
  stationaryIntervalSeconds = 30,
  hiddenIntervalSeconds = 60,
}: Props) {
  const trackingKey = trackingStorageKey(legId);
  const [tracking, setTracking] = useState(() => readTrackingPreference(trackingKey));
  const [serverRoute, setServerRoute] = useState<LiveTrackingRoute | null>(null);
  const [status, setStatus] = useState<'idle' | 'locating' | 'active' | 'offline' | 'error'>('idle');
  const [message, setMessage] = useState<string | null>(null);
  const [lastSentAt, setLastSentAt] = useState<string | null>(null);
  const [currentPosition, setCurrentPosition] = useState<SentPosition | null>(null);
  const lastPosition = useRef<SentPosition | null>(null);
  const latestPosition = useRef<SentPosition | null>(null);
  const requestInFlight = useRef(false);

  useEffect(() => {
    if (enabled) return;
    writeTrackingPreference(trackingKey, false);
    setTracking(false);
    setCurrentPosition(null);
    setServerRoute(null);
    setLastSentAt(null);
    setMessage(null);
    setStatus('idle');
    latestPosition.current = null;
    lastPosition.current = null;
  }, [enabled, trackingKey]);

  useEffect(() => {
    if (!tracking || !enabled) return undefined;

    let cancelled = false;
    let timeoutId: number | null = null;
    let watchId: number | null = null;

    const schedule = (seconds: number) => {
      if (cancelled) return;
      timeoutId = window.setTimeout(() => {
        timeoutId = null;
        void tick();
      }, seconds * 1000);
    };

    const applyPosition = (nextPosition: SentPosition): SentPosition | null => {
      const previousPosition = latestPosition.current;
      if (!hasValidCoordinates(nextPosition)) {
        setStatus('error');
        setMessage('The location reading was invalid. We will try again.');
        return null;
      }
      if (
        previousPosition
        && previousPosition.timestamp >= nextPosition.timestamp
        && !(previousPosition.source === 'public_ip' && nextPosition.source === 'browser')
      ) {
        return previousPosition;
      }

      if (nextPosition.accuracy_m !== null && nextPosition.accuracy_m > MAX_USABLE_ACCURACY_M) {
        setStatus('error');
        setMessage(`GPS accuracy is too low (${formatDistance(nextPosition.accuracy_m)}). Turn on your phone's location services and try again.`);
        return null;
      }

      if (previousPosition) {
        const elapsedSeconds = Math.max(1, (nextPosition.timestamp - previousPosition.timestamp) / 1000);
        const impliedSpeed = distanceMeters(previousPosition, nextPosition) / elapsedSeconds;
        if (impliedSpeed > MAX_CLIENT_IMPLIED_SPEED_MPS) {
          setStatus('error');
          setMessage('GPS reading ignored because it jumped unexpectedly. We will keep the last reliable position.');
          return null;
        }
      }

      latestPosition.current = nextPosition;
      setCurrentPosition(nextPosition);
      setMessage(null);
      setStatus(lastPosition.current ? 'active' : 'locating');
      return nextPosition;
    };
    const handleBrowserPosition = (position: GeolocationPosition): void => {
      applyPosition(toSentPosition(position));
    };


    const handlePositionError = (error: unknown) => {
      if (cancelled || latestPosition.current || lastPosition.current) return;
      setStatus('error');
      setMessage(locationErrorMessage(error));
    };

    const tick = async () => {
      if (cancelled) return;

      if (!online || document.hidden) {
        setStatus('offline');
        schedule(document.hidden ? hiddenIntervalSeconds : stationaryIntervalSeconds);
        return;
      }

      if (requestInFlight.current) {
        schedule(stationaryIntervalSeconds);
        return;
      }

      requestInFlight.current = true;
      setStatus('locating');

      try {
        const nextPosition = latestPosition.current ?? applyPosition(await resolveTrackingPosition());
        if (cancelled || !nextPosition) {
          if (!cancelled) schedule(stationaryIntervalSeconds);
          return;
        }
        const previousPosition = lastPosition.current;

        if (previousPosition?.timestamp === nextPosition.timestamp) {
          setStatus('active');
          schedule(stationaryIntervalSeconds);
          return;
        }

        const payload: Record<string, unknown> = {
          latitude: nextPosition.latitude,
          longitude: nextPosition.longitude,
          recorded_at: new Date(nextPosition.timestamp).toISOString(),
        };
        if (nextPosition.accuracy_m !== null && nextPosition.accuracy_m <= MAX_TRACKING_ACCURACY_M) payload.accuracy_m = nextPosition.accuracy_m;
        if (nextPosition.speed_mps !== null) payload.speed_mps = nextPosition.speed_mps;
        if (nextPosition.heading_deg !== null) payload.heading_deg = nextPosition.heading_deg;

        const response = await logisticsApi.recordLocation(legId, payload);
        if (cancelled) return;
        const responseRoute = response?.data?.route;
        if (latestPosition.current?.timestamp === nextPosition.timestamp) {
          setServerRoute(isLiveTrackingRoute(responseRoute) ? responseRoute : null);
        }

        lastPosition.current = nextPosition;
        setLastSentAt(new Date(nextPosition.timestamp).toISOString());
        setMessage(null);
        setStatus('active');
        const moved = !previousPosition || distanceMeters(previousPosition, nextPosition) >= 25;
        const serverInterval = Number(response?.data?.next_poll_after_seconds);
        schedule(moved && Number.isFinite(serverInterval) && serverInterval > 0
          ? serverInterval
          : moved ? movingIntervalSeconds : stationaryIntervalSeconds);
      } catch (error) {
        if (cancelled) return;

        if ((error as { response?: { status?: number } } | null)?.response?.status === 403) {
          setTracking(false);
          writeTrackingPreference(trackingKey, false);
          setCurrentPosition(null);
          setServerRoute(null);
          latestPosition.current = null;
          setStatus('error');
          setMessage('Tracking stopped because this delivery is no longer active.');
        } else {
          setStatus('error');
          setMessage(locationErrorMessage(error));
          schedule(online ? stationaryIntervalSeconds : hiddenIntervalSeconds);
        }
      } finally {
        requestInFlight.current = false;
      }
    };

    const handleVisibilityChange = () => {
      if (cancelled || document.hidden) return;
      if (timeoutId !== null) {
        window.clearTimeout(timeoutId);
        timeoutId = null;
      }
      setStatus('locating');
      void tick();
    };

    try {
      if (typeof navigator !== 'undefined' && navigator.geolocation?.watchPosition) {
        watchId = navigator.geolocation.watchPosition(handleBrowserPosition, handlePositionError, GPS_POSITION_OPTIONS);
      }
    } catch (error) {
      handlePositionError(error);
    }

    void tick();
    document.addEventListener('visibilitychange', handleVisibilityChange);

    return () => {
      cancelled = true;
      if (timeoutId !== null) window.clearTimeout(timeoutId);
      if (watchId !== null && typeof navigator !== 'undefined' && navigator.geolocation) {
        navigator.geolocation.clearWatch(watchId);
      }
      document.removeEventListener('visibilitychange', handleVisibilityChange);
    };
  }, [
    enabled,
    hiddenIntervalSeconds,
    legId,
    movingIntervalSeconds,
    online,
    stationaryIntervalSeconds,
    tracking,
  ]);


  if (!enabled) return null;

  const toggleTracking = () => {
    if (tracking) {
      setTracking(false);
      writeTrackingPreference(trackingKey, false);
      setStatus('idle');
      setMessage(null);
      setCurrentPosition(null);
      setServerRoute(null);
      latestPosition.current = null;
      lastPosition.current = null;
      return;
    }

    setMessage(null);
    setTracking(true);
    writeTrackingPreference(trackingKey, true);
  };

  const destinationPoint = destinationCoordinates(destination);
  const directRoute: LiveTrackingRoute | null = currentPosition && destinationPoint ? {
    distance_m: distanceMeters(currentPosition, destinationPoint),
    duration_s: Math.ceil(distanceMeters(currentPosition, destinationPoint) / ETA_SPEED_MPS),
    geometry: [
      [currentPosition.latitude, currentPosition.longitude],
      [destinationPoint.latitude, destinationPoint.longitude],
    ],
    source: 'direct',
  } : null;
  const route = serverRoute ?? directRoute;
  const mapLocations: LiveRiderLocation[] = tracking && currentPosition && destinationPoint ? [{
    leg_id: legId,
    shipment_id: null,
    shipment_reference: null,
    rider: { id: null, name: 'You' },
    status: 'active',
    destination: {
      type: destination?.type ?? null,
      name: destination?.name ?? null,
      address: destination?.address ?? null,
      latitude: destinationPoint.latitude,
      longitude: destinationPoint.longitude,
    },
    location: {
      latitude: currentPosition.latitude,
      longitude: currentPosition.longitude,
      accuracy_m: currentPosition.accuracy_m,
      speed_mps: currentPosition.speed_mps,
      heading_deg: currentPosition.heading_deg,
      recorded_at: new Date(currentPosition.timestamp).toISOString(),
      received_at: null,
    },
    stale: status !== 'active',
    route,
  }] : [];

  return (
    <section
      aria-label="GPS tracking"
      className="mt-5 rounded-xl border border-slate-300 bg-slate-100 p-4 dark:border-slate-700 dark:bg-slate-900"
    >
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h4 className="font-bold text-slate-950 dark:text-white">GPS tracking</h4>
          <p role="status" aria-live="polite" className="mt-1 text-sm text-slate-600 dark:text-slate-300">
            {status === 'active' && 'GPS tracking active'}
            {status === 'locating' && 'Getting your current location…'}
            {status === 'offline' && 'Waiting for an internet connection'}
            {status === 'error' && 'GPS tracking needs attention'}
            {status === 'idle' && 'Share your location only while this delivery is active.'}
          </p>
          {lastSentAt && (
            <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
              Last GPS update {new Date(lastSentAt).toLocaleTimeString()}
            </p>
          )}
          {tracking && currentPosition && currentPosition.accuracy_m !== null && currentPosition.accuracy_m > MAX_TRACKING_ACCURACY_M && (
            <p role="status" className="mt-1 text-xs font-semibold text-slate-700 dark:text-slate-300">
              GPS accuracy is approximate. A cellphone with location services will give a more accurate rider position.
            </p>
          )}
          {tracking && currentPosition?.source === 'public_ip' && (
            <p role="status" className="mt-1 text-xs font-semibold text-slate-700 dark:text-slate-300">
              Using an approximate public network location. A cellphone with location services will give a more accurate rider position.
            </p>
          )}
        </div>
        <button
          type="button"
          onClick={toggleTracking}
          disabled={!online && !tracking}
          className="min-h-11 touch-manipulation rounded-xl border border-slate-950 px-4 text-sm font-bold text-slate-950 transition-colors hover:bg-slate-200 focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:border-white dark:text-white dark:hover:bg-slate-800 dark:focus:ring-white"
        >
          {tracking ? 'Stop GPS tracking' : 'Start GPS tracking'}
        </button>
      </div>
      {tracking && !currentPosition && destinationPoint && (
        <p role="status" className="mt-3 text-sm font-semibold text-slate-800 dark:text-slate-200">
          Waiting for your GPS position to show the route.
        </p>
      )}
      {tracking && !destinationPoint && (
        <p role="status" className="mt-3 text-sm font-semibold text-slate-700 dark:text-slate-300">
          The customer map pin is unavailable. Use the Directions button above for navigation.
        </p>
      )}
      {mapLocations.length > 0 && (
        <div className="mt-4 overflow-hidden rounded-xl border border-slate-300 bg-white dark:border-slate-700 dark:bg-slate-900">
          <div className="flex flex-col gap-2 border-b border-slate-200 p-3 dark:border-slate-700 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <h5 className="font-bold text-slate-950 dark:text-white">Route to customer</h5>
              <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">
                {destination?.address || destination?.name || 'Delivery destination'}
              </p>
            </div>
            {route && (
              <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">
                ETA {Math.max(1, Math.ceil(route.duration_s / 60))} min · {formatDistance(route.distance_m)}
              </p>
            )}
          </div>
          <LiveTrackingMap locations={mapLocations} label="Rider route to customer map" followLocation viewer="rider" />
          <p className="border-t border-slate-200 px-3 py-2 text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400">
            {route?.source === 'road'
              ? 'Fastest available road route. Use Directions above for turn-by-turn navigation.'
              : 'Road route is unavailable right now. Use Directions above for turn-by-turn navigation.'}
          </p>
        </div>
      )}
      {message && (
        <p role="alert" className="mt-3 text-sm font-semibold text-slate-900 dark:text-white">
          {message}
        </p>
      )}
    </section>
  );
}
