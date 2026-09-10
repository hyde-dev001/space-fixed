import 'leaflet/dist/leaflet.css';
import { Maximize2, Minimize2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { LiveTrackingRoute } from '@/types/logistics';

export type LiveRiderLocation = {
  leg_id: number;
  shipment_id: number | null;
  shipment_number?: number | null;
  shipment_reference: string | null;
  delivery_type?: string | null;
  delivery_label?: string | null;
  rider: { id: number | null; name: string | null };
  status: string | null;
  destination: {
    type?: string | null;
    name?: string | null;
    address?: string | null;
    latitude?: number | null;
    longitude?: number | null;
  };
  location: {
    latitude: number;
    longitude: number;
    accuracy_m: number | null;
    speed_mps: number | null;
    heading_deg: number | null;
    recorded_at: string | null;
    received_at: string | null;
  };
  stale: boolean;
  route?: LiveTrackingRoute | null;
};

type Props = {
  locations: LiveRiderLocation[];
  label?: string;
  followLocation?: boolean;
  viewer?: 'rider' | 'customer';
};

const validLocations = (locations: LiveRiderLocation[]) => locations.filter(({ location }) => (
  Number.isFinite(location.latitude)
  && Number.isFinite(location.longitude)
  && location.latitude >= -90
  && location.latitude <= 90
  && location.longitude >= -180
  && location.longitude <= 180
));

const destinationPoint = (entry: LiveRiderLocation): [number, number] | null => {
  const latitude = entry.destination.latitude;
  const longitude = entry.destination.longitude;

  return typeof latitude === 'number'
    && typeof longitude === 'number'
    && Number.isFinite(latitude)
    && Number.isFinite(longitude)
    && latitude >= -90
    && latitude <= 90
    && longitude >= -180
    && longitude <= 180
    ? [latitude, longitude]
    : null;
};

const formatDistance = (meters: number): string => meters >= 1000
  ? `${(meters / 1000).toFixed(1)} km`
  : `${Math.round(meters)} m`;

const formatEta = (seconds: number): string => `${Math.max(1, Math.ceil(seconds / 60))} min`;

type Point = [number, number];

const normalizedHeading = (value: number | null): number | null => {
  if (value === null || !Number.isFinite(value)) return null;
  return ((value % 360) + 360) % 360;
};

const MOTORBIKE_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" data-icon="motorcycle" aria-hidden="true"><path d="m18 14-1-3"/><path d="m3 9 6 2a2 2 0 0 1 2-2h2a2 2 0 0 1 1.99 1.81"/><path d="M8 17h3a1 1 0 0 0 1-1 6 6 0 0 1 6-6 1 1 0 0 0 1-1v-.75A5 5 0 0 0 17 5"/><circle cx="19" cy="17" r="3"/><circle cx="5" cy="17" r="3"/></svg>';

const riderIcon = (
  L: typeof import('leaflet'),
  heading: number | null,
) => {
  return L.divIcon({
    className: 'live-rider-marker',
    iconSize: [32, 32],
    iconAnchor: [16, 16],
    html: '<span style="display:flex;align-items:center;justify-content:center;width:32px;height:32px;border:2px solid #ffffff;border-radius:9999px;background:#111111"><span style="display:block;transform:rotate(' + (heading ?? 0) + 'deg)">' + MOTORBIKE_ICON + '</span></span>',
  });
};

export default function LiveTrackingMap({ locations, label = 'Live rider map', followLocation = false, viewer = 'customer' }: Props) {
  const mapShellRef = useRef<HTMLDivElement>(null);
  const containerRef = useRef<HTMLDivElement>(null);
  const mapRef = useRef<import('leaflet').Map | null>(null);
  const leafletRef = useRef<typeof import('leaflet') | null>(null);
  const markersRef = useRef(new Map<number, import('leaflet').Marker>());
  const destinationMarkersRef = useRef(new Map<number, import('leaflet').CircleMarker>());
  const routesRef = useRef(new Map<number, import('leaflet').Polyline>());
  const routeStateRef = useRef(new Map<number, LiveTrackingRoute | null>());
  const routeVersionRef = useRef(new Map<number, { version?: number; updatedAt?: number }>());
  const displayedPointsRef = useRef(new Map<number, Point>());
  const animationFramesRef = useRef(new Map<number, number>());
  const headingsRef = useRef(new Map<number, number>());
  const [mapReady, setMapReady] = useState(false);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const hasFittedRef = useRef(false);

  const toggleFullscreen = (): void => {
    const shell = mapShellRef.current;
    if (!shell) return;

    if (document.fullscreenElement === shell) {
      if (typeof document.exitFullscreen === 'function') {
        void document.exitFullscreen().catch(() => undefined);
      }
      return;
    }

    if (typeof shell.requestFullscreen === 'function') {
      void shell.requestFullscreen().catch(() => undefined);
    }
  };

  const cancelMarkerAnimation = (legId: number): void => {
    const frame = animationFramesRef.current.get(legId);
    if (frame !== undefined) {
      window.cancelAnimationFrame?.(frame);
      animationFramesRef.current.delete(legId);
    }
  };

  const animateMarker = (marker: import('leaflet').Marker, legId: number, target: Point): void => {
    const previous = displayedPointsRef.current.get(legId);
    cancelMarkerAnimation(legId);

    if (!previous
      || (previous[0] === target[0] && previous[1] === target[1])
      || typeof window.requestAnimationFrame !== 'function') {
      marker.setLatLng(target);
      displayedPointsRef.current.set(legId, target);
      return;
    }

    const startedAt = typeof performance !== 'undefined' ? performance.now() : Date.now();
    const step = (timestamp: number): void => {
      const progress = Math.min(1, Math.max(0, (timestamp - startedAt) / 900));
      const eased = progress * (2 - progress);
      const point: Point = [
        previous[0] + ((target[0] - previous[0]) * eased),
        previous[1] + ((target[1] - previous[1]) * eased),
      ];
      marker.setLatLng(point);
      displayedPointsRef.current.set(legId, point);

      if (progress < 1) {
        animationFramesRef.current.set(legId, window.requestAnimationFrame(step));
      } else {
        animationFramesRef.current.delete(legId);
        displayedPointsRef.current.set(legId, target);
      }
    };

    animationFramesRef.current.set(legId, window.requestAnimationFrame(step));
  };

  const headingFor = (legId: number, value: number | null): number | null => {
    const heading = normalizedHeading(value);
    if (heading === null) return null;

    const previous = headingsRef.current.get(legId);
    if (previous !== undefined) {
      const delta = Math.abs(heading - previous);
      if (Math.min(delta, 360 - delta) < 8) return previous;
    }

    headingsRef.current.set(legId, heading);
    return heading;
  };

  const acceptedRouteFor = (entry: LiveRiderLocation): LiveTrackingRoute | null => {
    const incoming = entry.route ?? null;
    const previous = routeStateRef.current.get(entry.leg_id) ?? null;
    if (!incoming) {
      routeStateRef.current.set(entry.leg_id, null);
      routeVersionRef.current.delete(entry.leg_id);
      return null;
    }

    const previousMeta = routeVersionRef.current.get(entry.leg_id);
    const incomingVersion = typeof incoming.route_version === 'number' && Number.isFinite(incoming.route_version)
      ? incoming.route_version
      : undefined;
    const incomingUpdatedAt = incoming.updated_at ? Date.parse(incoming.updated_at) : undefined;
    const isOlderVersion = incomingVersion !== undefined
      && previousMeta?.version !== undefined
      && incomingVersion < previousMeta.version;
    const isOlderTimestamp = incomingVersion !== undefined
      && previousMeta?.version === incomingVersion
      && incomingUpdatedAt !== undefined
      && previousMeta.updatedAt !== undefined
      && incomingUpdatedAt < previousMeta.updatedAt;

    if (previous && (isOlderVersion || isOlderTimestamp)) return previous;

    routeStateRef.current.set(entry.leg_id, incoming);
    routeVersionRef.current.set(entry.leg_id, {
      version: incomingVersion,
      updatedAt: incomingUpdatedAt,
    });
    return incoming;
  };

  useEffect(() => {
    if (!containerRef.current) return;
    let disposed = false;
    let map: import('leaflet').Map | null = null;
    let resizeObserver: ResizeObserver | null = null;

    void import('leaflet').then((L) => {
      if (disposed || !containerRef.current) return;

      const container = containerRef.current;
      map = L.map(container, { scrollWheelZoom: false }).setView([14.5995, 120.9842], 12);
      const tileLayer = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
      });
      tileLayer.addTo(map);
      leafletRef.current = L;
      mapRef.current = map;
      if (typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(() => {
          map?.invalidateSize({ pan: false, debounceMoveend: true });
        });
        resizeObserver.observe(container);
      }
      setMapReady(true);
      window.setTimeout(() => {
        map?.invalidateSize({ pan: false, debounceMoveend: true });
      }, 0);
    });

    return () => {
      disposed = true;
      animationFramesRef.current.forEach((frame) => window.cancelAnimationFrame?.(frame));
      animationFramesRef.current.clear();
      markersRef.current.clear();
      destinationMarkersRef.current.clear();
      routesRef.current.clear();
      routeStateRef.current.clear();
      routeVersionRef.current.clear();
      displayedPointsRef.current.clear();
      headingsRef.current.clear();
      resizeObserver?.disconnect();
      resizeObserver = null;
      map?.remove();
      mapRef.current = null;
      leafletRef.current = null;
    };
  }, []);

  useEffect(() => {
    const shell = mapShellRef.current;
    if (!shell) return;

    const handleFullscreenChange = () => {
      setIsFullscreen(document.fullscreenElement === shell);
      window.setTimeout(() => {
        mapRef.current?.invalidateSize({ pan: false, debounceMoveend: true });
      }, 0);
    };

    document.addEventListener('fullscreenchange', handleFullscreenChange);
    return () => document.removeEventListener('fullscreenchange', handleFullscreenChange);
  }, []);


  useEffect(() => {
    const L = leafletRef.current;
    const map = mapRef.current;
    if (!mapReady || !L || !map) return;

    const visible = validLocations(locations);
    if (visible.length === 0) hasFittedRef.current = false;
    const visibleIds = new Set(visible.map(({ leg_id }) => leg_id));

    markersRef.current.forEach((marker, legId) => {
      if (!visibleIds.has(legId)) {
        cancelMarkerAnimation(legId);
        marker.removeFrom(map);
        markersRef.current.delete(legId);
        routeStateRef.current.delete(legId);
        routeVersionRef.current.delete(legId);
        displayedPointsRef.current.delete(legId);
        headingsRef.current.delete(legId);
      }
    });
    destinationMarkersRef.current.forEach((marker, legId) => {
      if (!visibleIds.has(legId)) {
        marker.removeFrom(map);
        destinationMarkersRef.current.delete(legId);
      }
    });
    routesRef.current.forEach((route, legId) => {
      if (!visibleIds.has(legId)) {
        route.removeFrom(map);
        routesRef.current.delete(legId);
      }
    });

    visible.forEach((entry) => {
      const point: [number, number] = [entry.location.latitude, entry.location.longitude];
      const riderLabel = entry.rider.name && entry.rider.name.toLowerCase() !== 'delivery'
        ? entry.rider.name
        : 'Rider';
      const deliveryLabel = entry.delivery_label ? ' · ' + entry.delivery_label : '';
      const tooltip = riderLabel + deliveryLabel + (entry.stale ? ' - Stale location' : '');
      const existing = markersRef.current.get(entry.leg_id);
      const heading = headingFor(entry.leg_id, entry.location.heading_deg);
      const route = acceptedRouteFor(entry);

      if (existing) {
        animateMarker(existing, entry.leg_id, point);
        existing
          .setIcon(riderIcon(L, heading))
          .setOpacity(entry.stale ? 0.65 : 1)
          .unbindTooltip()
          .bindTooltip(tooltip);
      } else {
        const marker = L.marker(point, {
          icon: riderIcon(L, heading),
          keyboard: false,
          opacity: entry.stale ? 0.65 : 1,
        }).addTo(map).bindTooltip(tooltip);
        markersRef.current.set(entry.leg_id, marker);
        displayedPointsRef.current.set(entry.leg_id, point);
      }

      const destination = destinationPoint(entry);
      const existingDestination = destinationMarkersRef.current.get(entry.leg_id);
      if (destination) {
        const destinationLabel = `Destination${entry.destination.address ? `: ${entry.destination.address}` : ''}`;
        if (existingDestination) {
          existingDestination
            .setLatLng(destination)
            .setStyle({
              color: '#ffffff',
              fillColor: '#075985',
              weight: 3,
            })
            .unbindTooltip()
            .bindTooltip(destinationLabel);
        } else {
          const marker = L.circleMarker(destination, {
            radius: 7,
            color: '#ffffff',
            fillColor: '#075985',
            weight: 3,
            fillOpacity: 0.85,
          }).addTo(map).bindTooltip(destinationLabel);
          destinationMarkersRef.current.set(entry.leg_id, marker);
        }
      } else if (existingDestination) {
        existingDestination.removeFrom(map);
        destinationMarkersRef.current.delete(entry.leg_id);
      }

      const geometry = route?.source === 'direct' ? [] : route?.geometry ?? [];
      const routeGeometry = geometry.length >= 2 ? [point, ...geometry.slice(1)] : geometry;
      const existingRoute = routesRef.current.get(entry.leg_id);
      if (routeGeometry.length >= 2) {
        if (existingRoute) {
          existingRoute.setLatLngs(routeGeometry).setStyle({
            color: entry.stale ? '#94a3b8' : '#1677e8',
          });
        } else {
          routesRef.current.set(entry.leg_id, L.polyline(routeGeometry, {
            color: entry.stale ? '#94a3b8' : '#1677e8',
            weight: 5,
            opacity: 0.9,
          }).addTo(map));
        }
      } else if (existingRoute) {
        existingRoute.removeFrom(map);
        routesRef.current.delete(entry.leg_id);
      }
    });

    if (followLocation && hasFittedRef.current && visible.length > 0) {
      const first = visible[0];
      map.panTo([first.location.latitude, first.location.longitude], { animate: true, duration: 0.25 });
    }

    if (visible.length > 0 && !hasFittedRef.current) {
      const boundsPoints = visible.flatMap((entry) => {
        const current: [number, number] = [entry.location.latitude, entry.location.longitude];
        const destination = destinationPoint(entry);
        return destination ? [current, destination] : [current];
      });
      map.fitBounds(L.latLngBounds(boundsPoints), { padding: [24, 24], maxZoom: 15 });
      hasFittedRef.current = true;
    }
  }, [followLocation, locations, mapReady]);

  const visibleLocations = validLocations(locations);
  const primaryLocation = visibleLocations[0] ?? null;
  const primaryRoute = primaryLocation?.route ?? null;
  const primaryDestination = primaryLocation?.destination.address
    ?? primaryLocation?.destination.name
    ?? 'Delivery destination';
  const primaryViewerLabel = viewer === 'rider' ? 'You' : primaryLocation?.rider.name ?? 'Delivery rider';
  const primaryViewerDetail = viewer === 'rider'
    ? primaryLocation?.location.accuracy_m !== null && primaryLocation?.location.accuracy_m !== undefined
      ? `Current GPS location · ${formatDistance(primaryLocation.location.accuracy_m)} accuracy`
      : 'Current GPS location'
    : primaryDestination;
  const mapHeightClass = isFullscreen
    ? 'h-dvh min-h-dvh'
    : 'h-[30rem] sm:h-[38rem] lg:h-[44rem]';

  return (
    <div
      ref={mapShellRef}
      className="relative w-full bg-white dark:bg-slate-900 [&:fullscreen]:h-dvh [&:fullscreen]:w-screen"
    >
      <div
        ref={containerRef}
        className={'isolate w-full overflow-hidden bg-white [&_.leaflet-control-zoom_a]:!h-11 [&_.leaflet-control-zoom_a]:!w-11 ' + mapHeightClass + ' dark:bg-slate-900'}
        aria-label={label}
      />
      <button
        type="button"
        onClick={toggleFullscreen}
        aria-label={isFullscreen ? 'Exit full screen map' : 'View map full screen'}
        aria-pressed={isFullscreen}
        className="absolute right-3 top-3 z-[1100] inline-flex min-h-11 min-w-11 touch-manipulation items-center justify-center rounded-full border border-slate-200 bg-white text-slate-950 transition-colors hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 active:bg-slate-200 sm:hidden dark:border-slate-700 dark:bg-slate-900 dark:text-white dark:hover:bg-slate-800 dark:focus:ring-white"
      >
        {isFullscreen
          ? <Minimize2 aria-hidden="true" className="h-5 w-5" />
          : <Maximize2 aria-hidden="true" className="h-5 w-5" />}
      </button>
    </div>
  );
}
